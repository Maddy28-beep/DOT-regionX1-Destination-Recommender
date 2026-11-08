#!/usr/bin/env bash
# ExploreDVO -- make the site faster to open: compress the stylesheets and scripts, and tell browsers to keep static files.
#
# Run this ON THE SERVER, as root (for example in the DigitalOcean web console).
#
# What it fixes (measured on the live site, PageSpeed Insights, mobile):
#   - /css/app.css is sent as 274 KB; compressed it is 68 KB. The scripts, the SVG and the data files are the same story:
#     nginx compresses only the HTML page by default. On a phone connection this alone saves seconds.
#   - static files carry no cache lifetime, so a returning visitor downloads the same stylesheet, script, photos and
#     hero clip again on every visit.
#
# By default it only LOOKS and tells you what it would do. Nothing changes until you add --apply.
#
#   bash /var/www/exploredvo/deploy/speed-up-static-files.sh            # look only
#   bash /var/www/exploredvo/deploy/speed-up-static-files.sh --apply    # do it
#
# Safe by design:
#   - it adds ONE include line to the app's nginx site and writes ONE new file (/etc/nginx/snippets/exploredvo-speed.conf);
#   - it keeps a copy of the site file in /root and puts it back, and says so, if `nginx -t` rejects the change;
#   - it only reloads nginx after `nginx -t` passes, and then checks the result with real requests;
#   - it never touches the database, the app's code or any file under /var/www;
#   - running it twice does nothing the second time.
#
# To undo:  delete the line containing "exploredvo-speed.conf" from the site file, then:  nginx -t && systemctl reload nginx
set -Eeuo pipefail

SITES=/etc/nginx/sites-enabled
SNIPPET=/etc/nginx/snippets/exploredvo-speed.conf
STAMP=$(date +%Y%m%d-%H%M%S)
MARK='exploredvo-speed.conf'

say()  { printf '%s\n' "$*"; }
step() { printf '\n==> %s\n' "$*"; }
die()  { printf '\nSTOPPED: %s\n' "$*" >&2; exit 1; }

snippet_text() {
  cat <<'NGINX'
# ExploreDVO speed settings (added by deploy/speed-up-static-files.sh).
#
# Compression. nginx compresses only text/html unless told otherwise. These are set inside the server block, so they
# take effect without touching the global nginx.conf.
gzip on;
gzip_vary on;
gzip_comp_level 5;
gzip_min_length 512;
gzip_proxied any;
gzip_types text/plain text/css text/javascript application/javascript application/json application/xml image/svg+xml font/ttf font/otf application/vnd.ms-fontobject;

# Keep static files. The stylesheets and scripts are linked as name.css?v=<file time>, so a changed file is a new
# address and a long lifetime is safe. Photos and the clip keep for 30 days.
# try_files falls back to Laravel (as the default site does), so a dynamic address that happens to end in .png or
# .svg is still answered by the app. "expires" only applies to files nginx serves itself.
location ~* \.(?:css|js)$ {
    expires 1y;
    try_files $uri /index.php?$query_string;
}
location ~* \.(?:webp|jpe?g|png|gif|svg|ico|woff2?|ttf|otf|mp4|webm)$ {
    expires 30d;
    try_files $uri /index.php?$query_string;
}
NGINX
}

# Put the include on its own line right after the first server_name line, unless it is already there.
add_include() {
  local file=$1
  grep -q "$MARK" "$file" && return 0
  local line="    include snippets/${MARK};"
  local tmp; tmp=$(mktemp)
  printf '%s\n' "$line" > "$tmp"
  sed -i -E "0,/^[[:space:]]*server_name[[:space:]]/{/^[[:space:]]*server_name[[:space:]]/r ${tmp}
}" "$file"
  rm -f "$tmp"
}

check() {
  # $1 = path. Prints what a browser that accepts compression gets.
  local out
  out=$(curl -sI -m 15 -H 'Accept-Encoding: gzip' "http://127.0.0.1$1" || true)
  printf '    %-26s %s | %s | %s\n' "$1" \
    "$(printf '%s' "$out" | head -1 | tr -d '\r')" \
    "$(printf '%s' "$out" | grep -i '^content-encoding' | tr -d '\r' | tr 'A-Z' 'a-z' || echo 'content-encoding: (none)')" \
    "$(printf '%s' "$out" | grep -i '^cache-control' | tr -d '\r' | tr 'A-Z' 'a-z' || echo 'cache-control: (none)')"
}

main() {
  local MODE=${1:-}
  [ "$(id -u)" = "0" ] || die "Run this as root."
  local APPLY=0; [ "$MODE" = "--apply" ] && APPLY=1

  step "Which nginx site serves the app?"
  local candidates; candidates=$(grep -lE 'fastcgi_pass' "$SITES"/* 2>/dev/null || true)
  [ -n "$candidates" ] || die "No site in $SITES uses PHP (fastcgi_pass). Send me the output of: ls $SITES"
  [ "$(printf '%s\n' "$candidates" | wc -l)" = "1" ] || die "More than one site uses PHP:
$candidates
Tell me which one is the app and I will point the script at it."
  local SITE; SITE=$(readlink -f "$candidates")
  say "    $SITE"

  step "How the site answers now"
  check /css/app.css
  check /js/app.js
  check /video/hero.mp4

  if grep -q "$MARK" "$SITE"; then
    step "Already set up"
    say "    $SITE already includes $MARK. Nothing to do."
    exit 0
  fi

  step "What would change"
  say "    1. write $SNIPPET  (the settings below)"
  say "    2. add this line after the first server_name line of $SITE:"
  say "           include snippets/${MARK};"
  say "    3. nginx -t, then reload nginx (if the test fails, the original site file is put back)"
  say ""
  say "    --- $SNIPPET ---"
  snippet_text | sed 's/^/    /'
  say "    ---"

  if [ "$APPLY" != "1" ]; then
    say ""
    say "Look only: nothing was changed. Run again with --apply to do it."
    exit 0
  fi

  step "Applying"
  cp -a "$SITE" "/root/nginx-site-before-speed-$STAMP"
  say "    kept a copy: /root/nginx-site-before-speed-$STAMP"
  mkdir -p "$(dirname "$SNIPPET")"
  snippet_text > "$SNIPPET"
  add_include "$SITE"

  if ! nginx -t; then
    cp -a "/root/nginx-site-before-speed-$STAMP" "$SITE"
    rm -f "$SNIPPET"
    die "nginx did not accept the change, so the original site file is back and nothing was reloaded. Send me the lines above."
  fi
  systemctl reload nginx

  step "Checking the result (with real requests)"
  check /css/app.css
  check /js/app.js
  check /video/hero.mp4
  say ""
  say "Done. app.css should now say content-encoding: gzip and a cache-control with a long max-age."
  say "To undo: cp -a /root/nginx-site-before-speed-$STAMP $SITE && rm -f $SNIPPET && nginx -t && systemctl reload nginx"
}

main "$@"
