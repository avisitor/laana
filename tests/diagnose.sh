#!/bin/bash
# Process a test suite report. If there are errors, pass it to opencode to evaluate and propose fixes

set -euo pipefail

# Usage:
#   ./diagnose.sh report.json
#   ./diagnose.sh report.json --test
#   ./diagnose.sh report.json -t

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 <report.json> [--test|-t]"
    exit 1
fi

REPORTFILE="$1"
MODE="run"

if [[ "${2:-}" == "--test" || "${2:-}" == "-t" ]]; then
    MODE="test"
fi

if [[ ! -f "$REPORTFILE" ]]; then
    echo "Error: File not found: $REPORTFILE"
    exit 1
fi

# Exit if there are no failures
if jq -e '
    (.failures // 0) > 0 or
    (.errors   // 0) > 0 or
    (.skipped  // 0) > 0
' $REPORTFILE > /dev/null; then
    echo "There are failures, errors, or skipped tests"
else
    echo "All tests passed with no skips"
    exit
fi

# Strip out much of the report to simplify for the LLM
SIMPLIFIED=`echo $REPORTFILE | sed -e 's/.json/.simplified.json/'`

# Escape the report into a valid JSON string
REPORT=$(jq -Rs . "$REPORTFILE")

jq '{
  timestamp,
  suite_names,
  total_suites,
  tests,
  assertions,
  failures,
  errors,
  skipped,
  time,
  success_rate,
  skipped_tests,
  test_cases: (
    .test_cases
    | map(
        select(.status != "passed")
        | del(.assertions, .time)
      )
  )
}' "$REPORTFILE" > $SIMPLIFIED

# Test mode: print the JSON payload and exit
if [[ "$MODE" == "test" ]]; then
    cat <<EOF | jq .
{
  "model": "qwen2.5",
  "messages": [
    {
      "role": "system",
      "content": "You are a senior systems engineer..."
    },
    {
      "role": "user",
      "content": $(printf '%s' "$SIMPLIFIED")
    }
  ],
  "options": {
    "num_ctx": 32768
  }
}
EOF
    exit 0
fi

# Normal mode
MODEL=opencode/big-pickle
MODEL=opencode/gpt-5-nano
PROMPT="OpenCode, open $SIMPLIFIED and walk through each test that failed, had a warning or was skipped. For each one, locate the corresponding test file and source file, and explain what’s going wrong and how to fix it in the test or in the code or configuration. Propose specific code changes but do not make any changes."
opencode --model $MODEL run $PROMPT

