#!/usr/bin/env bash
# ExploreDVO -- connect a domain name to the server.
#
# Run this ON THE SERVER, as root (for example in the DigitalOcean web console). It does three things:
#   1. adds the domain names to the nginx site that serves the app,
#   2. gets a free Let's Encrypt certificate for them (certbot) so the site opens on https,
#   3. tells the app its new address (APP_URL in .env, and secure cookies), then rebuilds the config cache.
#
# By default it only LOOKS and tells you what it would do. Nothing changes until you add --apply.
#
#   bash /var/www/exploredvo/deploy/connect-domain.sh www.exploredavao.com you@example.com            # look only
#   bash /var/www/exploredvo/deploy/connect-domain.sh www.exploredavao.com you@example.com --apply    # do it
#
# The first argument is the address people should end up on (here the "www" one); the bare domain is sent to it.
# The e-mail is where Let's Encrypt sends a warning if a certificate ever fails to renew.
#
# Safe by design:
#   - it stops, changing nothing, if the domain does not yet point at this server (DNS not finished);
#   - it keeps a copy of every file it edits in /root and puts the nginx file back if the new one fails `nginx -t`;
#   - the server's IP address keeps answering on plain http, so deploy.ps1's own health check is unaffected;
#   - it never touches the database or the app's code.
set -Eeuo pipefail

APP=/var/www/exploredvo
SITES=/etc/nginx/sites-enabled
STAMP=$(date +%Y%m%d-%H%M%S)

say()  { printf '%s\n' "$*"; }
step() { printf '\n==> %s\n' "$*"; }
die()  { printf '\nSTOPPED: %s\n' "$*" >&2; exit 1; }

as_www() { sudo -u www-data env HOME=/tmp "$@"; }

# ---- the nginx edits, as functions on a file so they can be tried on a copy -------------------------------------

# Add $names to the first server_name line, unless they are already there. The names already on the line stay
# (so the IP address keeps working).
add_names() {
  local file=$1; shift
  local names=("$@") n line
  line=$(grep -m1 -E '^[[:space:]]*server_name[[:space:]]' "$file") || die "No server_name line in $file."
  for n in "${names[@]}"; do
    if ! grep -m1 -E '^[[:space:]]*server_name[[:space:]]' "$file" | grep -qE "[[:space:]]${n//./\\.}([[:space:];])"; then
      sed -i -E "0,/^([[:space:]]*server_name[[:space:]]+)([^;]*);/s//\1\2 ${n};/" "$file"
    fi
  done
}

# Right after the first server_name line: domain visitors on http go to https, and the bare domain goes to www.
# Requests by IP address (and the 127.0.0.1 health check) are not touched.
add_redirect() {
  local file=$1 host=$2 apex=$3
  grep -q 'dvo_redirect' "$file" && return 0
  local apex_re=${apex//./\\.}
  local snippet
  snippet=$(cat <<NGINX
    # --- domain: http -> https, and ${apex} -> ${host} (added by deploy/connect-domain.sh) ---
    set \$dvo_redirect "";
    if (\$scheme = http) { set \$dvo_redirect "h"; }
    if (\$host ~* ^(www\\.)?${apex_re}\$) { set \$dvo_redirect "\${dvo_redirect}d"; }
    if (\$dvo_redirect = "hd") { return 301 https://${host}\$request_uri; }
    if (\$host = ${apex}) { return 301 https://${host}\$request_uri; }
    # --- end domain ---
NGINX
)
  # sed's "r" appends the snippet file after the first server_name line (awk -v would eat the backslashes).
  local snipfile; snipfile=$(mktemp)
  printf '%s
' "$snippet" > "$snipfile"
  sed -i -E "0,/^[[:space:]]*server_name[[:space:]]/{/^[[:space:]]*server_name[[:space:]]/r ${snipfile}
}" "$file"
  rm -f "$snipfile"
}

# Set KEY=value in the .env (replace the line if there is one, add it if not).
set_env() {
  local file=$1 key=$2 value=$3
  if grep -qE "^${key}=" "$file"; then
    sed -i -E "s|^${key}=.*|${key}=${value}|" "$file"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$file"
  fi
}

main() {
  local HOST=${1:-} EMAIL=${2:-} MODE=${3:-}
  [ -n "$HOST" ] && [ -n "$EMAIL" ] || die "Usage: bash connect-domain.sh www.example.com you@example.com [--apply]"
  [ "$(id -u)" = "0" ] || die "Run this as root."
  local APPLY=0; [ "$MODE" = "--apply" ] && APPLY=1
  local APEX=${HOST#www.}
  [ "$APEX" != "$HOST" ] || die "Give the www address first, for example www.example.com (the bare domain is added for you)."

  step "Which nginx site serves the app?"
  local candidates; candidates=$(grep -lE 'fastcgi_pass' "$SITES"/* 2>/dev/null || true)
  [ -n "$candidates" ] || die "No site in $SITES uses PHP (fastcgi_pass). Send me the output of: ls $SITES"
  [ "$(printf '%s\n' "$candidates" | wc -l)" = "1" ] || die "More than one site uses PHP:
$candidates
Tell me which one is the app and I will point the script at it."
  local SITE; SITE=$(readlink -f "$candidates")
  say "    $SITE"
  say "    current: $(grep -m1 -E '^[[:space:]]*server_name' "$SITE" | sed 's/^[[:space:]]*//')"

  step "Does the domain point at this server?"
  local MYIP
  MYIP=$(curl -s -m 5 http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address || true)
  [ -n "$MYIP" ] || MYIP=$(curl -s -4 -m 8 https://ifconfig.me || true)
  [ -n "$MYIP" ] || die "Could not work out this server's public IP address."
  local n ip bad=0
  say "    this server: $MYIP"
  for n in "$HOST" "$APEX"; do
    ip=$(getent ahostsv4 "$n" | awk '{print $1; exit}' || true)
    say "    $n -> ${ip:-(nothing)}"
    [ "$ip" = "$MYIP" ] || bad=1
  done
  [ "$bad" = "0" ] || die "The domain does not point here yet. In Namecheap > Advanced DNS, both the @ and www records must be A records for $MYIP, then wait a few minutes and run this again. Nothing has been changed."

  step "What it will do"
  say "    1. nginx: add $HOST and $APEX to $SITE (a copy is kept in /root)"
  say "    2. certbot: a free certificate for $HOST and $APEX (renews itself)"
  say "    3. nginx: send http -> https for the domain, and $APEX -> $HOST"
  say "    4. $APP/.env: APP_URL=https://$HOST and SESSION_SECURE_COOKIE=true (a copy is kept in /root), then config:cache"
  if [ "$APPLY" = "0" ]; then
    say ""
    say "Nothing was changed. If that looks right, run it again with --apply on the end."
    return 0
  fi

  step "1/4 nginx names"
  cp -a "$SITE" "/root/nginx-site-before-domain-$STAMP"
  add_names "$SITE" "$HOST" "$APEX"
  if ! nginx -t; then cp -a "/root/nginx-site-before-domain-$STAMP" "$SITE"; die "nginx did not accept the change. The original file is back in place."; fi
  systemctl reload nginx
  say "    done"

  step "2/4 certificate"
  command -v certbot >/dev/null || { apt-get update -qq; apt-get install -y -qq certbot python3-certbot-nginx; }
  certbot --nginx -d "$HOST" -d "$APEX" -m "$EMAIL" --agree-tos --no-eff-email --non-interactive --no-redirect
  say "    done"

  step "3/4 redirects"
  add_redirect "$SITE" "$HOST" "$APEX"
  if ! nginx -t; then
    cp -a "/root/nginx-site-before-domain-$STAMP" "$SITE"
    die "nginx did not accept the redirects, so the original site file is back (the certificate is kept). Tell me and we will do the redirects another way."
  fi
  systemctl reload nginx
  say "    done"

  step "4/4 the app's address"
  cp -a "$APP/.env" "/root/env-before-domain-$STAMP"; chmod 600 "/root/env-before-domain-$STAMP"
  set_env "$APP/.env" APP_URL "https://$HOST"
  set_env "$APP/.env" SESSION_SECURE_COOKIE true
  ( cd "$APP" && as_www php artisan config:cache )
  say "    done"

  step "Checking"
  local code
  code=$(curl -s -o /dev/null -m 25 -w '%{http_code}' "https://$HOST/");               say "    https://$HOST/        $code  (want 200)"
  code=$(curl -s -o /dev/null -m 25 -w '%{http_code}' "http://$HOST/");                say "    http://$HOST/         $code  (want 301)"
  code=$(curl -s -o /dev/null -m 25 -w '%{http_code}' "https://$APEX/");               say "    https://$APEX/    $code  (want 301)"
  code=$(curl -s -o /dev/null -m 25 -w '%{http_code}' "http://127.0.0.1/");            say "    http://127.0.0.1/            $code  (want 200: the deploy health check)"
  say ""
  say "Done. To undo: cp -a /root/nginx-site-before-domain-$STAMP $SITE && cp -a /root/env-before-domain-$STAMP $APP/.env && nginx -t && systemctl reload nginx && (cd $APP && sudo -u www-data php artisan config:cache)"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then main "$@"; fi
