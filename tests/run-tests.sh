#!/bin/bash
# Comprehensive test runner for noiiolelo test suite
# Generates JSON reports and detailed console output
# Based on retree-hawaii test runner pattern

set -euo pipefail

TIMESTAMP=$(date +"%Y%m%d-%H%M%S")
REPORT_DIR="tests/reports"
TEXT_REPORT="$REPORT_DIR/test-report-$TIMESTAMP.txt"
XML_REPORT="$REPORT_DIR/junit-$TIMESTAMP.xml"
JSON_REPORT="$REPORT_DIR/test-report-$TIMESTAMP.json"

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
        php vendor/bin/phpunit --testdox 2>&1 | tee "$TEXT_REPORT"
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
        timeout 120 php vendor/bin/phpunit --log-junit "$XML_REPORT" --testdox > "$TEXT_REPORT" 2>&1
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
        echo "📝 Generating JSON report..."
        
        python3 - "$XML_REPORT" "$JSON_REPORT" <<'PYTHON_SCRIPT'
import sys
import json
import xml.etree.ElementTree as ET
from datetime import datetime

try:
    xml_file = sys.argv[1]
    json_file = sys.argv[2]
    
    # Parse the JUnit XML file
    tree = ET.parse(xml_file)
    root = tree.getroot()
    
    # Get all testsuites (nested)
    testsuites = root.findall('.//testsuite')
    
    # Collect suite names (excluding root)
    suite_names = [ts.get('name', 'Unknown') for ts in testsuites if ts.get('file')]
    
    # Extract stats from root testsuite
    root_suite = root.find('testsuite')
    total_tests = int(root_suite.get('tests', 0))
    total_assertions = int(root_suite.get('assertions', 0))
    total_time = float(root_suite.get('time', 0.0))
    
    # Extract all test cases recursively
    def extract_testcases_recursive(element):
        """Recursively find all testcase elements in nested testsuites"""
        testcases = []
        for testcase in element.findall('testcase'):
            testcases.append(testcase)
        for testsuite in element.findall('testsuite'):
            testcases.extend(extract_testcases_recursive(testsuite))
        return testcases
    
    all_test_cases = []
    for testcase in extract_testcases_recursive(root):
        case_info = {
            'name': testcase.get('name', 'Unknown'),
            'class': testcase.get('class', ''),
            'classname': testcase.get('classname', ''),
            'file': testcase.get('file', ''),
            'line': int(testcase.get('line', 0)),
            'assertions': int(testcase.get('assertions', 0)),
            'time': round(float(testcase.get('time', 0.0)), 6),
            'status': 'passed'  # default
        }
        
        # Check for failures, errors, or skipped tests
        failure = testcase.find('failure')
        error = testcase.find('error')
        skipped = testcase.find('skipped')
        system_out = testcase.find('system-out')
        
        if failure is not None:
            case_info['status'] = 'failed'
            case_info['failure_message'] = failure.get('message', '')
            case_info['failure_text'] = failure.text or ''
        elif error is not None:
            case_info['status'] = 'error'
            case_info['error_message'] = error.get('message', '')
            case_info['error_text'] = error.text or ''
        elif skipped is not None:
            case_info['status'] = 'skipped'
            case_info['skip_message'] = skipped.get('message', '')
        
        # Include system output if present (truncate if too long)
        if system_out is not None and system_out.text:
            output = system_out.text.strip()
            if len(output) > 500:  # Truncate long output
                output = output[:500] + '...'
            case_info['output'] = output
            
            # Check if output contains SQL/runtime errors
            error_patterns = ['Update failed:', 'SQLSTATE', 'Fatal error:', 'ERROR:', 
                            'Error:', 'syntax error', 'failed:']
            for pattern in error_patterns:
                if pattern in output and case_info['status'] == 'passed':
                    case_info['status'] = 'failed'
                    case_info['failure_message'] = 'SQL/Runtime Error detected in output'
                    case_info['failure_text'] = f'Test output contains error: {pattern}'
                    break
        
        all_test_cases.append(case_info)
    
    # Recalculate statistics based on actual test statuses
    actual_failures = len([t for t in all_test_cases if t['status'] == 'failed'])
    actual_errors = len([t for t in all_test_cases if t['status'] == 'error'])
    actual_skipped = len([t for t in all_test_cases if t['status'] == 'skipped'])
    actual_passed = len([t for t in all_test_cases if t['status'] == 'passed'])
    
    # Create comprehensive summary
    summary = {
        'timestamp': datetime.now().isoformat(),
        'suite_names': list(set(suite_names)),
        'total_suites': len([ts for ts in testsuites if ts.get('file')]),
        'tests': len(all_test_cases),
        'assertions': total_assertions,
        'failures': actual_failures,
        'errors': actual_errors,
        'skipped': actual_skipped,
        'passed': actual_passed,
        'time': round(total_time, 6),
        'success_rate': round(actual_passed / len(all_test_cases) * 100, 2) if len(all_test_cases) > 0 else 0,
        'test_cases': all_test_cases
    }
    
    with open(json_file, 'w') as f:
        json.dump(summary, f, indent=2)
    
    print(f'✅ JSON report created: {json_file.split("/")[-1]}')
    print(f'   Tests: {len(all_test_cases)}, Passed: {actual_passed}, Failed: {actual_failures}, Errors: {actual_errors}')
    print(f'   Success Rate: {summary["success_rate"]}%, Time: {total_time:.2f}s')
    
except Exception as e:
    print(f'⚠️  Failed to generate JSON report: {e}', file=sys.stderr)
    import traceback
    traceback.print_exc()
    sys.exit(1)
PYTHON_SCRIPT
        
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
        echo "📊 JSON report: $JSON_REPORT"
        echo "🌐 View in browser: tests/index.php"
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
