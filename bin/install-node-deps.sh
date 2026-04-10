#!/bin/sh

set -eu

set -- $(sha256sum package-lock.json)
lock_hash="$1"
installed_hash="$(cat node_modules/.package-lock.sha256 2>/dev/null || true)"

if [ ! -x node_modules/.bin/tailwindcss ] || [ ! -x node_modules/.bin/webpack ] || [ "$lock_hash" != "$installed_hash" ]; then
    npm ci --include=optional --no-fund --no-audit
    printf '%s' "$lock_hash" > node_modules/.package-lock.sha256
fi