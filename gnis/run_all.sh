#!/usr/bin/env bash
# Full pipeline: browser -> crawl -> assemble -> consistent mirror.
#   ./run_all.sh [--with-en] [--force-download]
# Assumes the Cloudflare challenge has been cleared once in the running
# browser (browser_start.sh + Turnstile click) before crawling.
set -euo pipefail
cd "$(dirname "$0")"

WITH_EN=0
DL_ARGS=""
for a in "$@"; do
    case "$a" in
        --with-en) WITH_EN=1 ;;
        --force-download) DL_ARGS="--force" ;;
    esac
done

./download_sources.sh $DL_ARGS
./browser_start.sh
python3 crawl_hpn.py --lang haw
[ "$WITH_EN" = 1 ] && python3 crawl_hpn.py --lang en
python3 build_final.py
php ../data/validate_name_lists.php
echo "Done. Output: ../data/name_lists/hawaiian_place_names.json"
