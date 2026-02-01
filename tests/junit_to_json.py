import argparse
import json
import os
import re
import sys
import xml.etree.ElementTree as ET
from datetime import datetime
from typing import Optional


def extract_testcases_recursive(element):
    """Recursively find all testcase elements in nested testsuites."""
    testcases = []

    for testcase in element.findall('testcase'):
        testcases.append(testcase)

    for testsuite in element.findall('testsuite'):
        testcases.extend(extract_testcases_recursive(testsuite))

    return testcases


def extract_testsuite_stats(element):
    """Extract stats from a testsuite element, handling nested suites."""
    return {
        'tests': int(element.get('tests', 0)),
        'assertions': int(element.get('assertions', 0)),
        'failures': int(element.get('failures', 0)),
        'errors': int(element.get('errors', 0)),
        'skipped': int(element.get('skipped', 0)),
        'time': float(element.get('time', 0.0)),
    }


def _read_source_line(file_path: str, line_number: int, context: int = 2) -> list:
    if not file_path or not os.path.isfile(file_path):
        return []
    try:
        with open(file_path, 'r') as f:
            lines = f.readlines()
    except OSError:
        return []

    if line_number <= 0:
        return []

    start = max(0, line_number - 1 - context)
    end = min(len(lines), line_number + context)
    return [lines[i].rstrip('\n') for i in range(start, end)]


def _extract_skip_message(expr: str) -> Optional[str]:
    literal = re.search(r"['\"]([^'\"]+)['\"]", expr)
    if literal:
        return literal.group(1)

    fallback = re.search(r"\?\?\s*['\"]([^'\"]+)['\"]", expr)
    if fallback:
        return fallback.group(1)

    return None


def _infer_from_method(file_path: str, test_name: str) -> Optional[str]:
    if not file_path or not os.path.isfile(file_path):
        return None
    try:
        with open(file_path, 'r') as f:
            content = f.read()
    except OSError:
        return None

    pattern = re.compile(
        r"function\s+" + re.escape(test_name) + r"\s*\(.*?\)\s*\{(.*?)\n\}",
        re.DOTALL
    )
    match = pattern.search(content)
    if not match:
        return None

    body = match.group(1)
    skip_match = re.search(r"markTestSkipped\((.*?)\)\s*;", body, re.DOTALL)
    if not skip_match:
        return None

    message = _extract_skip_message(skip_match.group(1).strip())
    return message


def _infer_from_setup(file_path: str) -> Optional[str]:
    if not file_path or not os.path.isfile(file_path):
        return None
    try:
        with open(file_path, 'r') as f:
            content = f.read()
    except OSError:
        return None

    for method_name in ("setUpBeforeClass", "setUp"):
        pattern = re.compile(
            r"function\s+" + method_name + r"\s*\(.*?\)\s*\{(.*?)\n\}",
            re.DOTALL
        )
        match = pattern.search(content)
        if not match:
            continue
        body = match.group(1)
        skip_match = re.search(r"markTestSkipped\((.*?)\)\s*;", body, re.DOTALL)
        if not skip_match:
            continue
        message = _extract_skip_message(skip_match.group(1).strip())
        if message:
            return message

    return None


def infer_skip_reason(file_path: Optional[str], line_str: Optional[str], test_name: Optional[str]) -> str:
    try:
        line_number = int(line_str or 0)
    except ValueError:
        line_number = 0

    candidates = _read_source_line(file_path or '', line_number, context=3)
    if not candidates:
        return 'Skipped without explicit reason'

    joined = ' '.join(candidates)
    match = re.search(r'markTestSkipped\((.*)\)\s*;?', joined)
    if match:
        expr = match.group(1).strip()
        message = _extract_skip_message(expr)
        if message:
            return message
        return f'Skipped via markTestSkipped({expr}) at {file_path}:{line_number}'

    if test_name:
        message = _infer_from_method(file_path or '', test_name)
        if message:
            return message

    message = _infer_from_setup(file_path or '')
    if message:
        return message

    return f'Skipped at {file_path}:{line_number} (reason not captured in JUnit)'


def build_summary(xml_report_path: str, summary_only: bool) -> dict:
    tree = ET.parse(xml_report_path)
    root = tree.getroot()

    testsuites = []
    if root.tag == 'testsuites':
        testsuites = root.findall('testsuite')
    elif root.tag == 'testsuite':
        testsuites = [root]

    if not testsuites:
        raise RuntimeError('No test suites found in XML')

    total_tests = 0
    total_assertions = 0
    total_failures = 0
    total_errors = 0
    total_skipped = 0
    total_time = 0.0
    all_test_cases = []
    suite_names = []
    skipped_tests = []

    for testsuite in testsuites:
        suite_name = testsuite.get('name', 'Unknown')
        suite_names.append(suite_name)

        stats = extract_testsuite_stats(testsuite)
        total_tests += stats['tests']
        total_assertions += stats['assertions']
        total_failures += stats['failures']
        total_errors += stats['errors']
        total_skipped += stats['skipped']
        total_time += stats['time']

        testcases = extract_testcases_recursive(testsuite)

        for testcase in testcases:
            case_info = {
                'name': testcase.get('name'),
                'class': testcase.get('class'),
                'suite': suite_name,
                'assertions': int(testcase.get('assertions', 0)),
                'time': float(testcase.get('time', 0.0)),
                'status': 'passed',
            }

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
                skip_message = skipped.get('message', '')
                if not skip_message and skipped.text:
                    skip_message = skipped.text.strip()
                if not skip_message:
                    skip_message = infer_skip_reason(
                        testcase.get('file'),
                        testcase.get('line'),
                        testcase.get('name')
                    )
                case_info['skip_message'] = skip_message
                skipped_tests.append({
                    'name': case_info['name'],
                    'class': case_info['class'],
                    'suite': case_info['suite'],
                    'skip_message': skip_message,
                })

            if system_out is not None and system_out.text:
                output = system_out.text.strip()
                if len(output) > 200:
                    output = output[:200] + '...'
                case_info['output'] = output

                sql_error_patterns = ['Update failed:', 'SQLSTATE', 'Fatal error:', 'ERROR:', 'Error:']
                for pattern in sql_error_patterns:
                    if pattern in output and case_info['status'] == 'passed':
                        case_info['status'] = 'failed'
                        case_info['failure_message'] = 'SQL/Runtime Error detected in output'
                        case_info['failure_text'] = f'Test output contains error: {pattern}'
                        break

            all_test_cases.append(case_info)

    actual_failures = [t for t in all_test_cases if t['status'] == 'failed']
    actual_errors = [t for t in all_test_cases if t['status'] == 'error']
    actual_skipped = [t for t in all_test_cases if t['status'] == 'skipped']
    actual_passed = [t for t in all_test_cases if t['status'] == 'passed']

    skipped_count = max(len(actual_skipped), total_skipped)

    summary = {
        'timestamp': datetime.now().isoformat(),
        'suite_names': suite_names,
        'total_suites': len(testsuites),
        'tests': len(all_test_cases),
        'assertions': total_assertions,
        'failures': len(actual_failures),
        'errors': len(actual_errors),
        'skipped': skipped_count,
        'time': round(total_time, 6),
        'success_rate': round(len(actual_passed) / len(all_test_cases) * 100, 2) if len(all_test_cases) > 0 else 0,
        'skipped_tests': skipped_tests,
        'test_cases': all_test_cases,
    }

    if not summary_only:
        print(
            f"✅ JSON summary: suites={len(testsuites)}, tests={total_tests}, "
            f"failures={len(actual_failures)}, errors={len(actual_errors)}, "
            f"skipped={len(actual_skipped)}, assertions={total_assertions}"
        )

        if actual_failures or actual_errors:
            print('\n❌ Failing / error tests:')
            for case in actual_failures:
                print(f"  FAIL  {case['class']}::{case['name']}")
            for case in actual_errors:
                print(f"  ERROR {case['class']}::{case['name']}")

    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description='Convert JUnit XML to JSON summary')
    parser.add_argument('--xml', required=True, help='Path to JUnit XML report')
    parser.add_argument('--json', required=True, help='Path to write JSON summary')
    parser.add_argument('--summary-only', action='store_true', help='Suppress console summary output')
    args = parser.parse_args()

    if not os.path.isfile(args.xml):
        print('⚠️  JUnit XML not found', file=sys.stderr)
        return 1

    summary = build_summary(args.xml, args.summary_only)

    with open(args.json, 'w') as f:
        json.dump(summary, f, indent=2)

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
