#!/bin/sh
#
# Runs on the DreamHost server; the Deploy workflow uploads and calls it
# (.github/workflows/deploy.yml, docs/deployment.md).
#
#   release.sh <name>     unpack incoming/<name>.tar.gz and make it live
#   release.sh rollback   switch back to the previous release
#
# Layout under $APP_ROOT (default ~/nacos):
#   releases/<name>/   one directory per release (the last 3 are kept)
#   shared/.env        the live settings, linked into every release
#   shared/storage/    uploads, logs, sessions, backups: linked in too
#   shared/live/       live election results (public/live)
# and ~/nacosyabatech.com is a link to the live release, so switching is
# instant and the web directory setting (nacosyabatech.com/public) and the
# cron line never change.
set -eu

APP_ROOT=${APP_ROOT:-$HOME/nacos}
SITE=${SITE_LINK:-$HOME/nacosyabatech.com}
PHP=${PHP_BIN:-/usr/local/php83/bin/php}
KEEP=${KEEP_RELEASES:-3}

say() { printf '==> %s\n' "$*"; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

current() { [ -L "$SITE" ] && readlink "$SITE" || true; }

switch_to() {
    ln -sfn "$1" "$SITE.next"
    mv -fT "$SITE.next" "$SITE"
}

# The site's PHP processes (FastCGI, run as this user) remember where files
# are for a couple of minutes, so after the switch some would keep serving
# the old release: a page from the new one, then a click answered by the
# old one ("The selected status is invalid"). Stopping them makes the next
# request start fresh on the new release. Only *.cgi processes: not this
# script's own php, nor the queue worker.
restart_php() {
    pkill -u "$(id -un)" -f 'php[0-9.]*\.cgi' 2>/dev/null || true
}

if [ "${1:-}" = "rollback" ]; then
    live=$(current)
    previous=$(ls -1dt "$APP_ROOT"/releases/*/ 2>/dev/null | sed 's#/$##' | grep -vx "$live" | head -n 1 || true)
    [ -n "$previous" ] || fail "There is no earlier release to go back to."
    say "Switching back to $(basename "$previous")"
    switch_to "$previous"
    restart_php
    (cd "$previous" && "$PHP" artisan queue:restart >/dev/null && "$PHP" artisan up >/dev/null) || true
    say "Live: $(basename "$previous"). Database changes made by newer releases were not undone."
    exit 0
fi

name=${1:?usage: release.sh <name> | rollback}
archive="$APP_ROOT/incoming/$name.tar.gz"
release="$APP_ROOT/releases/$name"
shared="$APP_ROOT/shared"

[ -f "$archive" ] || fail "$archive is missing."
[ -f "$shared/.env" ] || fail "$shared/.env is missing: create it first (docs/deployment.md, one-time setup)."

# The first deploy: a real directory where the link will go (what the panel
# made) is kept aside, never deleted.
if [ -e "$SITE" ] && [ ! -L "$SITE" ]; then
    aside="$SITE.before-deploy-$(date +%Y%m%d%H%M%S)"
    say "Moving the existing $SITE aside to $aside"
    mv "$SITE" "$aside"
fi

say "Unpacking $name"
mkdir -p "$release"
tar -xzf "$archive" -C "$release"
rm -f "$archive"

say "Linking the shared settings and files"
for dir in app/private app/backups framework/cache/data framework/sessions framework/views framework/alerts logs; do
    mkdir -p "$shared/storage/$dir"
done
mkdir -p "$shared/live"
chmod 700 "$shared/storage/app/backups"
rm -rf "$release/storage" "$release/public/live"
ln -s "$shared/storage" "$release/storage"
ln -s "$shared/live" "$release/public/live"
ln -sfn "$shared/.env" "$release/.env"
mkdir -p "$release/bootstrap/cache"

live=$(current)
if [ -n "$live" ] && [ -d "$live" ]; then
    say "Maintenance mode while the database is updated"
    (cd "$live" && "$PHP" artisan down --retry=30 --refresh=15 >/dev/null) || true
fi

cd "$release"
if ! "$PHP" artisan migrate --force; then
    [ -n "$live" ] && (cd "$live" && "$PHP" artisan up >/dev/null) || true
    fail "The database update failed; the previous release is still live. $release was left for a look."
fi
"$PHP" artisan optimize

say "Going live"
switch_to "$release"
restart_php
"$PHP" artisan queue:restart >/dev/null
"$PHP" artisan up >/dev/null

# Keep the newest few releases (never the live one).
ls -1dt "$APP_ROOT"/releases/*/ | sed 's#/$##' | tail -n +"$((KEEP + 1))" | while read -r old; do
    [ "$old" = "$release" ] || rm -rf "$old"
done

say "Live: $name"
