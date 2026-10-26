#!/usr/bin/env bash
# Build an installable plugin zip: dist/seo-action-plan.zip
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p dist
rm -f dist/seo-action-plan.zip
zip -rq dist/seo-action-plan.zip seo-action-plan -x '*.DS_Store'
echo "dist/seo-action-plan.zip"
