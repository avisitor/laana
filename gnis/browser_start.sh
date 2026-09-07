#!/usr/bin/env bash
# Launch the CloakBrowser stealth Chromium with CDP on :9242 and wait for it.
# Creates .cloak-venv on first run. Requires: uv (or python3.13 + venv/pip),
# npm for agent-browser. See ../data/hawaiian_place_names.md for details.
set -euo pipefail
cd "$(dirname "$0")"

VENV=.cloak-venv
CDP_PORT=9242

if curl -s --max-time 3 "http://127.0.0.1:${CDP_PORT}/json/version" > /dev/null 2>&1; then
    echo "CloakBrowser already listening on :${CDP_PORT}"
    exit 0
fi

if [ ! -x "${VENV}/bin/python" ]; then
    if command -v uv > /dev/null 2>&1; then
        uv venv "${VENV}" --python 3.13
        uv pip install --python "${VENV}/bin/python" "cloakbrowser==0.4.10"
    else
        python3.13 -m venv "${VENV}"
        "${VENV}/bin/pip" install "cloakbrowser==0.4.10"
    fi
elif ! "${VENV}/bin/python" -c "import cloakbrowser" 2> /dev/null; then
    if command -v uv > /dev/null 2>&1; then
        uv pip install --python "${VENV}/bin/python" "cloakbrowser==0.4.10"
    else
        "${VENV}/bin/pip" install "cloakbrowser==0.4.10"
    fi
fi

"${VENV}/bin/python" -c "import cloakbrowser; cloakbrowser.ensure_binary()"

if ! command -v agent-browser > /dev/null 2>&1; then
    npm i -g agent-browser@0.31.1
fi

setsid nohup "${VENV}/bin/python" cloak_keepalive.py > browser.log 2>&1 < /dev/null &

for _ in $(seq 1 45); do
    if curl -s --max-time 3 "http://127.0.0.1:${CDP_PORT}/json/version" > /dev/null 2>&1; then
        echo "CDP up on :${CDP_PORT}"
        exit 0
    fi
    sleep 2
done

echo "CDP did not come up; check browser.log" >&2
exit 1
