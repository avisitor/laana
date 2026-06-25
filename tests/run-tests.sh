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
HEARTBEAT_SECONDS=20
ACTIVE_CMD_PID=""
ACTIVE_HEARTBEAT_PID=""

cd $SERVER_DIR

# Create reports directory if it doesn't exist
mkdir -p "$REPORT_DIR"

timestamp() {
    date '+%H:%M:%S'
}

verbose_log() {
    if [ "${VERBOSE:-0}" = "1" ]; then
        echo "[$(timestamp)] $*"
    fi
}

phpunit_event_filter() {
    awk '
        /^PHPUnit Started/ { print; fflush(); next }
        /^Test Runner Started/ { print; fflush(); next }
        /^Test Suite Started \((Provider|Search|API|Integration),/ {
            line = $0
            sub(/^Test Suite Started \(/, "▶ Suite: ", line)
            sub(/, [0-9]+ tests\)$/, "", line)
            print line
            fflush()
            next
        }
        /^Test Preparation Started \(/ {
            line = $0
            sub(/^Test Preparation Started \(/, "→ ", line)
            sub(/\)$/, "", line)
            print line
            fflush()
            next
        }
        /^Test Passed \(/ {
            line = $0
            sub(/^Test Passed \(/, "✓ ", line)
            sub(/\)$/, "", line)
            print line
            fflush()
            next
        }
        /^Test Failed \(/ || /^Test Errored \(/ || /^Test Skipped \(/ || /^Test Considered Risky \(/ || /^Test Marked Incomplete \(/ {
            print
            fflush()
            next
        }
        /^\[catalog-test / || /^\[keaolama-parser / {
            print
            fflush()
            next
        }
        /^PHPUnit Finished/ || /^Test Runner Finished/ {
            print
            fflush()
            next
        }
    '
}

cleanup_active_processes() {
    if [ -n "${ACTIVE_HEARTBEAT_PID:-}" ]; then
        kill "$ACTIVE_HEARTBEAT_PID" 2>/dev/null || true
        wait "$ACTIVE_HEARTBEAT_PID" 2>/dev/null || true
        ACTIVE_HEARTBEAT_PID=""
    fi
    if [ -n "${ACTIVE_CMD_PID:-}" ]; then
        # Kill full process group first (phpunit + tee + children), then fallback to PID.
        kill -- -"$ACTIVE_CMD_PID" 2>/dev/null || true
        kill "$ACTIVE_CMD_PID" 2>/dev/null || true
        wait "$ACTIVE_CMD_PID" 2>/dev/null || true
        ACTIVE_CMD_PID=""
    fi
}

on_interrupt() {
    echo ""
    echo "⚠️  Interrupted. Stopping test run..."
    cleanup_active_processes
    exit 130
}

trap on_interrupt INT TERM
trap cleanup_active_processes EXIT

run_with_heartbeat_to_report() {
    local description="$1"
    shift

    verbose_log "▶ $description"
    verbose_log "   Command: $*"

    if command -v setsid >/dev/null 2>&1; then
        setsid "$@" > >(tee "$TEXT_REPORT") 2>&1 &
    else
        "$@" > >(tee "$TEXT_REPORT") 2>&1 &
    fi
    local cmd_pid=$!
    ACTIVE_CMD_PID="$cmd_pid"
    local elapsed=0

    (
        while kill -0 "$cmd_pid" 2>/dev/null; do
            sleep "$HEARTBEAT_SECONDS"
            elapsed=$((elapsed + HEARTBEAT_SECONDS))
            if kill -0 "$cmd_pid" 2>/dev/null; then
                verbose_log "… still running ($elapsed s): $description"
            fi
        done
    ) &
    local heartbeat_pid=$!
    ACTIVE_HEARTBEAT_PID="$heartbeat_pid"

    set +e
    wait "$cmd_pid"
    local rc=$?
    set -e

    kill "$heartbeat_pid" 2>/dev/null || true
    wait "$heartbeat_pid" 2>/dev/null || true

    ACTIVE_HEARTBEAT_PID=""
    ACTIVE_CMD_PID=""
    if [ "$rc" -eq 0 ]; then
        verbose_log "✓ Completed: $description"
    else
        verbose_log "✗ Failed (exit $rc): $description"
    fi

    return "$rc"
}

run_phpunit_with_filtered_events() {
    verbose_log "▶ PHPUnit test execution"
    verbose_log "   Command: php $PHPUNIT --debug --display-errors --display-warnings --display-deprecations --display-notices --display-skipped --display-incomplete --display-phpunit-deprecations --log-junit $XML_REPORT"

    if command -v setsid >/dev/null 2>&1; then
        setsid stdbuf -oL -eL php "$PHPUNIT" \
            --debug \
            --display-errors \
            --display-warnings \
            --display-deprecations \
            --display-notices \
            --display-skipped \
            --display-incomplete \
            --display-phpunit-deprecations \
            --log-junit "$XML_REPORT" \
            > >(phpunit_event_filter | tee "$TEXT_REPORT") 2>&1 &
    else
        stdbuf -oL -eL php "$PHPUNIT" \
            --debug \
            --display-errors \
            --display-warnings \
            --display-deprecations \
            --display-notices \
            --display-skipped \
            --display-incomplete \
            --display-phpunit-deprecations \
            --log-junit "$XML_REPORT" \
            > >(phpunit_event_filter | tee "$TEXT_REPORT") 2>&1 &
    fi

    local cmd_pid=$!
    ACTIVE_CMD_PID="$cmd_pid"
    local elapsed=0

    (
        while kill -0 "$cmd_pid" 2>/dev/null; do
            sleep "$HEARTBEAT_SECONDS"
            elapsed=$((elapsed + HEARTBEAT_SECONDS))
            if kill -0 "$cmd_pid" 2>/dev/null; then
                verbose_log "… still running ($elapsed s): PHPUnit test execution"
            fi
        done
    ) &
    local heartbeat_pid=$!
    ACTIVE_HEARTBEAT_PID="$heartbeat_pid"

    set +e
    wait "$cmd_pid"
    local rc=$?
    set -e

    kill "$heartbeat_pid" 2>/dev/null || true
    wait "$heartbeat_pid" 2>/dev/null || true

    ACTIVE_HEARTBEAT_PID=""
    ACTIVE_CMD_PID=""

    if [ "$rc" -eq 0 ]; then
        verbose_log "✓ Completed: PHPUnit test execution"
    else
        verbose_log "✗ Failed (exit $rc): PHPUnit test execution"
    fi

    return "$rc"
}

# Function to run tests and generate reports
run_tests() {
    echo "=========================================="
    echo "🔍 Noiiolelo Test Suite"
    echo "=========================================="
    echo "📅 Started: $(date '+%Y-%m-%d %H:%M:%S HST')"
    echo ""

    # Check for verbose flag
    if [ "${VERBOSE:-0}" = "1" ]; then
        echo "🔊 Running in verbose mode (live progress output)..."
        echo "🧭 Verbose mode prints suite names, test starts/results, command lines, and heartbeat updates"
        if [ "${TRACE:-0}" = "1" ]; then
            echo "🧪 TRACE=1 enabled: using PHPUnit --debug event stream"
        fi
        echo ""

        if [ "${TRACE:-0}" = "1" ]; then
            run_with_heartbeat_to_report \
                "PHPUnit test execution (trace)" \
                php "$PHPUNIT" \
                --debug \
                --display-errors \
                --display-warnings \
                --display-deprecations \
                --display-notices \
                --display-skipped \
                --display-incomplete \
                --display-phpunit-deprecations \
                --log-junit "$XML_REPORT"
        else
            run_phpunit_with_filtered_events
        fi
        EXIT_CODE=$?
        
        echo ""
        echo "📊 Verbose run completed"
        echo "📄 Text report: $TEXT_REPORT"
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
        verbose_log "▶ JUnit XML -> JSON conversion"

        python3 "$TEST_DIR/junit_to_json.py" \
            --xml "$XML_REPORT" \
            --json "$JSON_REPORT" \
            || echo "⚠️  Python3 not available for JSON conversion"
        verbose_log "✓ JUnit XML -> JSON conversion finished"

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
while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose)
            export VERBOSE=1
            shift
            ;;
        *)
            break
            ;;
    esac
done

# Run the tests
run_tests
EXIT_CODE=$?

exit $EXIT_CODE
