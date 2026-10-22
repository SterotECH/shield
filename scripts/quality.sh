#!/bin/sh
# Shared local and CI gates. Any failed command stops the gate.
set -eu
cd "$(dirname "$0")/.."

stage=${1:-push}
case "$stage" in commit|push) ;; *) echo 'Usage: sh scripts/quality.sh commit|push' >&2; exit 2 ;; esac

composer_run() {
    if [ "${CI:-}" != true ] && command -v lerd >/dev/null 2>&1; then
        lerd composer "$@"
    else
        composer "$@"
    fi
}

git diff --check
git diff --cached --check
for directory in . packages/core packages/redis packages/runtime packages/cli packages/laravel; do
    composer_run --working-dir="$directory" validate --strict
done

if [ "$stage" = commit ]; then
    for package in core redis runtime cli laravel; do
        composer_run --working-dir="packages/$package" lint:check
    done
else
    # The CLI suite tests both decorated output and NO_COLOR explicitly.
    unset NO_COLOR
    composer_run check
    for directory in . packages/core packages/redis packages/runtime packages/cli packages/laravel; do
        composer_run --working-dir="$directory" audit --locked
    done
fi
