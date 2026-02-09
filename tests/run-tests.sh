#!/bin/bash
# Comprehensive test runner for noiiolelo test suite
# Generates JSON reports and detailed console output
# Based on retree-hawaii test runner pattern

set -euo pipefail

SERVER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

TIMESTAMP=$(date +"%Y%m%d-%H%M%S")
REPORT_DIR="$SERVER_DIR/tests/reports"
TEXT_REPORT="$REPORT_DIR/test-report-$TIMESTAMP.txt"
XML_REPORT="$REPORT_DIR/junit-$TIMESTAMP.xml"
JSON_REPORT="$REPORT_DIR/test-report-$TIMESTAMP.json"
VIEW_DIR="https://noiiolelo.worldspot.org/tests/"
PHPUNIT=$SERVER_DIR/vendor/bin/phpunit

cd $SERVER_DIR

# Create reports directory if it doesn't exist
mkdir -p "$REPORT_DIR"

# Function to run tests and generate reports
run_tests() {
    echo "=========================================="
    echo "🔍 Noiiolelo Test Suite"
    echo "=========================================="
    echo "📅 Started: $(date '+%Y-%m-%d %H:%M:%S HST')"
    echo ""

    # Check for verbose flag
    if [ "${VERBOSE:-0}" = "1" ]; then
        echo "🔊 Running in verbose mode (debug output enabled)..."
        echo ""
        
        # Run with all output visible (no JUnit XML in verbose mode)
        php $PHPUNIT --testdox 2>&1 | tee "$TEXT_REPORT"
        EXIT_CODE=${PIPESTATUS[0]}
        
        echo ""
        echo "⚠️  JSON report not generated in verbose mode"
        echo "📄 Text report: $TEXT_REPORT"
        
        return $EXIT_CODE
    else
        echo "🚀 Running tests (quiet mode)..."
        echo ""
        
        # Run with JUnit XML output (capture exit code but don't exit on failure)
        set +e
        #timeout 120 php $PHPUNIT --log-junit "$XML_REPORT" --testdox > "$TEXT_REPORT" 2>&1
        php $PHPUNIT --log-junit "$XML_REPORT" --testdox > "$TEXT_REPORT" 2>&1
        EXIT_CODE=$?
        set -e
        
        # Display the test output
        cat "$TEXT_REPORT"
    fi

    echo ""
    echo "=========================================="
    echo "📊 Processing Results"
    echo "=========================================="

    # Parse summary from testdox output
    if grep -q "Tests: " "$TEXT_REPORT"; then
        echo ""
        echo "Summary:"
        grep "Tests: " "$TEXT_REPORT" | tail -n 1
        echo ""
    fi

    # Generate JSON report from JUnit XML
    if [ -f "$XML_REPORT" ]; then
        echo "📝 Generating JSON report from $XML_REPORT..."

        python3 "$TEST_DIR/junit_to_json.py" \
            --xml "$XML_REPORT" \
            --json "$JSON_REPORT" \
            || echo "⚠️  Python3 not available for JSON conversion"

        if [ -f "$JSON_REPORT" ]; then
            rm -f "$REPORT_DIR/latest.json"
            ln -s "$(basename "$JSON_REPORT")" "$REPORT_DIR/latest.json"
        fi

        # Remove intermediate XML file
        rm -f "$XML_REPORT"
    else
        echo "⚠️  JUnit XML not generated - JSON report skipped"
    fi

    echo ""
    echo "=========================================="
    echo "✨ Complete"
    echo "=========================================="
    echo "📄 Text report: $TEXT_REPORT"
    if [ -f "$JSON_REPORT" ]; then
        if [ -f "$TEST_DIR/diagnose.sh" ]; then
            echo "🔎 Running diagnostics..."
            bash "$TEST_DIR/diagnose.sh" "$JSON_REPORT"
        else
            echo "⚠️  Diagnostics script not found: $TEST_DIR/diagnose.sh"
        fi
        echo "📊 JSON report: $JSON_REPORT"
        echo "🌐 View in browser: $VIEW_DIR"
    fi
    echo ""

    return $EXIT_CODE
}

# Check for verbose flag
if [ "${1:-}" = "-v" ] || [ "${1:-}" = "--verbose" ] || [ "${VERBOSE:-0}" = "1" ]; then
    export VERBOSE=1
fi

# Run the tests
run_tests
EXIT_CODE=$?

exit $EXIT_CODE
