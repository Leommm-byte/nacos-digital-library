#!/bin/sh
# Keeps public/build in step with the code for the local stack.
#
# Rebuilds CSS/JS on every change (vite build --watch). When package-lock.json
# changes, for example after a `git pull` that adds a package, it reinstalls
# the packages and restarts the build. Without that, the watcher would fail
# on the new import and quietly keep serving the previous build.
set -u

lock_sum() { md5sum package-lock.json | cut -d' ' -f1; }

# A build of the current code, never one left from an earlier run.
rm -rf public/build

while true; do
    sum=$(lock_sum)
    echo "Installing packages…"
    npm ci --no-audit --no-fund

    npx vite build --watch &
    build=$!

    while [ "$(lock_sum)" = "$sum" ] && kill -0 "$build" 2>/dev/null; do
        sleep 3
    done

    echo "package-lock.json changed (or the build stopped): reinstalling and rebuilding."
    kill "$build" 2>/dev/null
    wait "$build" 2>/dev/null
    sleep 1
done
