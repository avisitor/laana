#!/usr/bin/env bash
set -x
pkill -9 -f cloak_keepalive.py 2>/dev/null
pkill -9 -f 'chrome-linux64/chrome' 2>/dev/null
sleep 1
exit 0
