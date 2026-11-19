#!/usr/bin/env bash
# ExploreDVO -- keep private plan links out of the web server's access log.
#
# Run this ON THE SERVER, as root. A private link carries its secret in the address
# (/plan/saved/<secret> and /plan/shared/<secret>), and nginx writes every address to its access log. This makes
# nginx skip those two paths when it writes the log, so the secrets are not kept there. Nothing else is
# changed: every other request is logged exactly as before.
#
# By default it only LOOKS. Nothing changes until you add --apply.
#
#   bash /var/www/exploredvo/deploy/hide-private-links-from-logs.sh            # look only
#   bash /var/www/exploredvo/deploy/hide-private-links-from-logs.sh --apply    # do it
#
# Safe by design: it keeps a copy of every file it edits in /root, runs `nginx -t` before reloading, puts every file
# back if nginx does not accept the change, and can be run twice (the second run changes nothing).
# It never touches the app, the database or .env.
set -Eeuo pipefail

CONF=/etc/nginx/nginx.conf
SITES=/etc/nginx/sites-enabled
EXTRA=/etc/nginx/conf.d/exploredvo-private-links.conf
STAMP=$(date +%Y%m%d-%H%M%S)
APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

say()  { printf '%s\n' "$*"; }
step() { printf '\n==> %s\n' "$*"; }
die()  { printf '\nSTOPPED: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run this as root."
command -v nginx >/dev/null || die "nginx is not installed here."
nginx -t >/dev/null 2>&1 || die "nginx does not accept its current settings, so nothing was changed. Fix that first (nginx -t shows why)."

step "Which access_log lines would be changed?"
# Lines that write a log file and do not yet have a condition. "access_log off" needs no change.
mapfile -t FILES < <(grep -l -E '^[[:space:]]*access_log[[:space:]]' "$CONF" "$SITES"/* /etc/nginx/conf.d/*.conf 2>/dev/null | grep -v -F "$EXTRA" || true)
TODO=()
for f in "${FILES[@]}"; do
  if grep -E '^[[:space:]]*access_log[[:space:]]' "$f" | grep -v -E 'access_log[[:space:]]+off|if=' | grep -q .; then
    TODO+=("$f")
    say "    $f"
    grep -n -E '^[[:space:]]*access_log[[:space:]]' "$f" | grep -v -E 'access_log[[:space:]]+off|if=' | sed 's/^/        /'
  fi
done
[ "${#TODO[@]}" -gt 0 ] || { say "    none: every access_log line already has a condition or is off. Nothing to do."; exit 0; }

if [ "$APPLY" -ne 1 ]; then
  say ""
  say "Looked only. Add --apply to make nginx skip /plan/saved/ and /plan/shared/ in the log."
  exit 0
fi

step "Backing up"
BACKUP=/root/nginx-before-private-links-$STAMP
mkdir -p "$BACKUP"
for f in "${TODO[@]}"; do cp -a "$f" "$BACKUP/$(echo "$f" | tr '/' '_')"; done
[ -f "$EXTRA" ] && cp -a "$EXTRA" "$BACKUP/$(basename "$EXTRA").old" || true
say "    copies kept in $BACKUP"

restore() {
  for f in "${TODO[@]}"; do cp -a "$BACKUP/$(echo "$f" | tr '/' '_')" "$f"; done
  [ -f "$BACKUP/$(basename "$EXTRA").old" ] && cp -a "$BACKUP/$(basename "$EXTRA").old" "$EXTRA" || rm -f "$EXTRA"
}

step "Applying"
cat > "$EXTRA" <<'NGINX'
# Written by deploy/hide-private-links-from-logs.sh: a private plan link carries its secret in the address,
# so these two paths are not written to the access log.
map $request_uri $exploredvo_log {
    ~^/plan/(saved|shared)/ 0;
    default 1;
}
NGINX
for f in "${TODO[@]}"; do
  # nginx only accepts if= after a format name, so a line that names only a file gets "combined" (the default format) first
  sed -i -E '/^[[:space:]]*access_log[[:space:]]+[^[:space:];]+;[[:space:]]*$/{/access_log[[:space:]]+off/!s/;[[:space:]]*$/ combined;/}' "$f"
  # then add "if=$exploredvo_log" before the semicolon of each unconditional access_log line
  sed -i -E '/^[[:space:]]*access_log[[:space:]]/{/access_log[[:space:]]+off/!{/if=/!s/;[[:space:]]*$/ if=$exploredvo_log;/}}' "$f"
done

if ! nginx -t; then
  restore
  die "nginx did not accept the change. Every file is back as it was (copies in $BACKUP)."
fi
systemctl reload nginx || { restore; nginx -t >/dev/null 2>&1 && systemctl reload nginx || true; die "nginx could not reload. Files restored."; }

step "Checking"
if grep -h -E '^[[:space:]]*access_log' "${TODO[@]}" | grep -v -E 'off|if=\$exploredvo_log' | grep -q .; then
  say "    some access_log lines still have no condition; look at them by hand."
else
  say "    every access_log line now skips /plan/saved/ and /plan/shared/."
fi
say ""
say "Done. Older log lines are not rewritten; they leave on their own when nginx rotates its logs."
