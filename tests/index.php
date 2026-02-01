<?php
/**
 * Noiiolelo Test Dashboard
 * Displays the latest test report in a user-friendly format
 */

// Set Hawaii timezone for proper time display
date_default_timezone_set('Pacific/Honolulu');

// Find the latest JSON report or a specific one
$reportsDir = __DIR__ . '/reports';
$jsonFiles = glob($reportsDir . '/test-report-*.json');

if (empty($jsonFiles)) {
    die('No test reports found. Run tests first with: ./tests/run-tests.sh');
}

// Sort by filename (which includes timestamp) to get the latest
rsort($jsonFiles);

// Check if a specific report is requested
$requestedReport = $_GET['report'] ?? null;
if ($requestedReport && file_exists($reportsDir . '/' . $requestedReport)) {
    $latestReport = $reportsDir . '/' . $requestedReport;
    $isLatestReport = (basename($latestReport) === basename($jsonFiles[0]));
} else {
    $latestReport = $jsonFiles[0];
    $isLatestReport = true;
}

$reportData = json_decode(file_get_contents($latestReport), true);

if (!$reportData) {
    die('Failed to parse test report: ' . basename($latestReport));
}

// Calculate some statistics
$passedTests = array_filter($reportData['test_cases'], fn($test) => $test['status'] === 'passed');
$failedTests = array_filter($reportData['test_cases'], fn($test) => $test['status'] === 'failed');
$errorTests = array_filter($reportData['test_cases'], fn($test) => $test['status'] === 'error');
$skippedTests = array_filter($reportData['test_cases'], fn($test) => $test['status'] === 'skipped');

// Extract all warnings and issues from test outputs
$warningsAndIssues = [];
foreach ($reportData['test_cases'] as $test) {
    if (!empty($test['output'])) {
        $output = $test['output'];
        // Look for common warning/error patterns
        if (strpos($output, 'failed:') !== false ||
            strpos($output, 'SQLSTATE') !== false ||
            strpos($output, 'Warning:') !== false ||
            strpos($output, 'Notice:') !== false ||
            strpos($output, 'Error:') !== false ||
            strpos($output, 'Update failed:') !== false) {
            $warningsAndIssues[] = [
                'test' => $test['name'],
                'class' => $test['class'],
                'status' => $test['status'],
                'message' => $output
            ];
        }
    }
}

// Get slowest tests
$slowestTests = $reportData['test_cases'];
usort($slowestTests, fn($a, $b) => $b['time'] <=> $a['time']);
$slowestTests = array_slice($slowestTests, 0, 5);

// Calculate report age
$reportTimestamp = new DateTime($reportData['timestamp']);
$now = new DateTime();
$interval = $now->diff($reportTimestamp);

if ($interval->days > 0) {
    $reportAgeText = $interval->days . ' day' . ($interval->days > 1 ? 's' : '') . ' ago';
} elseif ($interval->h > 0) {
    $reportAgeText = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
} elseif ($interval->i > 0) {
    $reportAgeText = $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
} else {
    $reportAgeText = 'Just now';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📚 Noiiolelo Test Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .header .subtitle {
            opacity: 0.9;
            font-size: 1.1em;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0;
            background: #f8f9fa;
        }
        
        .stat-card {
            padding: 25px;
            text-align: center;
            border-right: 1px solid #dee2e6;
        }
        
        .stat-card:last-child {
            border-right: none;
        }
        
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .passed { color: #28a745; }
        .failed { color: #dc3545; }
        .error { color: #fd7e14; }
        .skipped { color: #6c757d; }
        .success-rate { color: #17a2b8; }
        
        .content {
            padding: 30px;
        }
        
        .section {
            margin-bottom: 40px;
        }
        
        .section h2 {
            color: #6366f1;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #6366f1;
        }
        
        .info-card h3 {
            color: #6366f1;
            margin-bottom: 10px;
        }
        
        .info-card ul {
            margin-left: 20px;
        }
        
        .test-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .test-table th {
            background: #6366f1;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .test-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .test-table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-passed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-error { background: #fff3cd; color: #856404; }
        .status-skipped { background: #e2e3e5; color: #383d41; }
        
        .time-badge {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
            font-family: monospace;
        }
        
        .assertion-count {
            color: #6c757d;
            font-size: 0.9em;
        }
        
        .test-output {
            background: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.8em;
            color: #495057;
            max-width: 400px;
            word-break: break-word;
        }
        
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #6c757d;
            border-top: 1px solid #dee2e6;
        }
        
        .btn {
            background: #6366f1;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        
        .btn:hover {
            background: #4f46e5;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .test-table {
                font-size: 0.9em;
            }
            
            .test-table th,
            .test-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📚 Noiiolelo Dictionary</h1>
            <div class="subtitle">
                <?php if ($isLatestReport): ?>
                    Test Dashboard - Latest Report
                <?php else: ?>
                    Test Dashboard - Historical Report
                    <br><small style="opacity: 0.8;"><?= htmlspecialchars(basename($latestReport)) ?></small>
                <?php endif; ?>
            </div>
            <div style="margin-top: 15px;">
                <?php if (!$isLatestReport): ?>
                    <a href="index.php" style="color: rgba(255,255,255,0.9); text-decoration: none; font-size: 0.9em;">
                        🏠 Latest Report
                    </a>
                    |
                <?php endif; ?>
                <a href="reports.php" style="color: rgba(255,255,255,0.9); text-decoration: none; font-size: 0.9em;">
                    📊 Browse All Reports
                </a>
                |
                <a href="reports/" style="color: rgba(255,255,255,0.9); text-decoration: none; font-size: 0.9em;">
                    📁 Reports Folder
                </a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number passed"><?= count($passedTests) ?></div>
                <div class="stat-label">Passed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number failed"><?= count($failedTests) ?></div>
                <div class="stat-label">Failed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number error"><?= count($errorTests) ?></div>
                <div class="stat-label">Errors</div>
            </div>
            <div class="stat-card">
                <div class="stat-number skipped"><?= count($skippedTests) ?></div>
                <div class="stat-label">Skipped</div>
            </div>
            <?php if (!empty($warningsAndIssues)): ?>
            <div class="stat-card">
                <div class="stat-number" style="color: #fd7e14;"><?= count($warningsAndIssues) ?></div>
                <div class="stat-label">Warnings</div>
            </div>
            <?php endif; ?>
            <div class="stat-card">
                <div class="stat-number success-rate"><?= $reportData['success_rate'] ?>%</div>
                <div class="stat-label">Success Rate</div>
            </div>
        </div>
        
        <div class="content">
            <div class="section">
                <h2>📊 Report Summary</h2>
                <div class="info-grid">
                    <div class="info-card">
                        <h3>📅 Report Details</h3>
                        <p><strong>Generated:</strong> <?= $reportAgeText ?></p>
                        <p><strong>File:</strong> <?= basename($latestReport) ?></p>
                        <p><strong>Timestamp:</strong> <?= $reportTimestamp->format('Y-m-d H:i:s T') ?></p>
                    </div>
                    <div class="info-card">
                        <h3>🧪 Test Execution</h3>
                        <p><strong>Total Tests:</strong> <?= $reportData['tests'] ?></p>
                        <p><strong>Total Assertions:</strong> <?= number_format($reportData['assertions']) ?></p>
                        <p><strong>Execution Time:</strong> <?= round($reportData['time'], 2) ?>s</p>
                    </div>
                    <div class="info-card">
                        <h3>📋 Test Suites</h3>
                        <p><strong>Suites Run:</strong> <?= $reportData['total_suites'] ?></p>
                        <p><strong>Suite Names:</strong></p>
                        <ul style="font-size: 0.9em; margin-top: 10px;">
                            <?php foreach ($reportData['suite_names'] as $suite): ?>
                                <li><?= htmlspecialchars(basename(str_replace('\\', '/', $suite))) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($slowestTests)): ?>
            <div class="section">
                <h2>🐌 Slowest Tests (Top 5)</h2>
                <table class="test-table">
                    <thead>
                        <tr>
                            <th>Test Name</th>
                            <th>Class</th>
                            <th>Time</th>
                            <th>Assertions</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slowestTests as $test): ?>
                        <tr>
                            <td><?= htmlspecialchars($test['name']) ?></td>
                            <td><?= htmlspecialchars(basename(str_replace('\\', '/', $test['class']))) ?></td>
                            <td><span class="time-badge"><?= round($test['time'], 3) ?>s</span></td>
                            <td><span class="assertion-count"><?= $test['assertions'] ?></span></td>
                            <td><span class="status-badge status-<?= $test['status'] ?>"><?= $test['status'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($warningsAndIssues)): ?>
            <div class="section">
                <h2>⚠️ Warnings & Issues (<?= count($warningsAndIssues) ?>)</h2>
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <p style="margin: 0; color: #856404;">
                        <strong>🔍 Found <?= count($warningsAndIssues) ?> test(s) with warnings or issues that should be reviewed:</strong>
                    </p>
                </div>
                <table class="test-table">
                    <thead>
                        <tr>
                            <th>Test Name</th>
                            <th>Class</th>
                            <th>Status</th>
                            <th>Warning/Issue Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($warningsAndIssues as $issue): ?>
                        <tr>
                            <td><?= htmlspecialchars($issue['test']) ?></td>
                            <td><?= htmlspecialchars(basename(str_replace('\\', '/', $issue['class']))) ?></td>
                            <td><span class="status-badge status-<?= $issue['status'] ?>"><?= $issue['status'] ?></span></td>
                            <td>
                                <div class="warning-output" style="background: #fff3cd; padding: 8px; border-radius: 4px; font-family: monospace; font-size: 0.8em; color: #856404; border-left: 3px solid #ffc107;">
                                    <?= htmlspecialchars($issue['message']) ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($failedTests) || !empty($errorTests)): ?>
            <div class="section">
                <h2>❌ Failed & Error Tests</h2>
                <table class="test-table">
                    <thead>
                        <tr>
                            <th>Test Name</th>
                            <th>Class</th>
                            <th>Status</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_merge($failedTests, $errorTests) as $test): ?>
                        <tr>
                            <td><?= htmlspecialchars($test['name']) ?></td>
                            <td><?= htmlspecialchars(basename(str_replace('\\', '/', $test['class']))) ?></td>
                            <td><span class="status-badge status-<?= $test['status'] ?>"><?= $test['status'] ?></span></td>
                            <td>
                                <?php if (isset($test['failure_message'])): ?>
                                    <div class="test-output"><?= htmlspecialchars($test['failure_message']) ?></div>
                                    <?php if (isset($test['failure_text']) && $test['failure_text']): ?>
                                        <details style="margin-top: 5px;">
                                            <summary style="cursor: pointer; color: #6c757d;">Details</summary>
                                            <pre style="margin-top: 5px; font-size: 0.8em;"><?= htmlspecialchars($test['failure_text']) ?></pre>
                                        </details>
                                    <?php endif; ?>
                                <?php elseif (isset($test['error_message'])): ?>
                                    <div class="test-output"><?= htmlspecialchars($test['error_message']) ?></div>
                                    <?php if (isset($test['error_text']) && $test['error_text']): ?>
                                        <details style="margin-top: 5px;">
                                            <summary style="cursor: pointer; color: #6c757d;">Details</summary>
                                            <pre style="margin-top: 5px; font-size: 0.8em;"><?= htmlspecialchars($test['error_text']) ?></pre>
                                        </details>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="section">
                <h2>📋 All Test Cases</h2>
                <table class="test-table">
                    <thead>
                        <tr>
                            <th>Test Name</th>
                            <th>Class</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Assertions</th>
                            <th>Output</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['test_cases'] as $test): ?>
                        <tr>
                            <td><?= htmlspecialchars($test['name']) ?></td>
                            <td><?= htmlspecialchars(basename(str_replace('\\', '/', $test['class']))) ?></td>
                            <td><span class="status-badge status-<?= $test['status'] ?>"><?= $test['status'] ?></span></td>
                            <td><span class="time-badge"><?= round($test['time'], 3) ?>s</span></td>
                            <td><span class="assertion-count"><?= $test['assertions'] ?></span></td>
                            <td>
                                <?php if (!empty($test['output'])): ?>
                                    <div class="test-output"><?= htmlspecialchars($test['output']) ?></div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="footer">
            <p>Noiiolelo Test Dashboard | 
            <?php if ($isLatestReport): ?>
                Latest report generated <?= $reportAgeText ?>
            <?php else: ?>
                Historical report from <?= $reportTimestamp->format('M j, Y H:i T') ?>
            <?php endif; ?>
            </p>
            <a href="?" class="btn">🔄 Refresh</a>
            <?php if (!$isLatestReport): ?>
                <a href="index.php" class="btn">📊 Latest Report</a>
            <?php endif; ?>
            <a href="#" class="btn" onclick="alert('Run in terminal: cd /var/www/html/laana && ./tests/run-tests.sh'); return false;">▶️ Run Tests</a>
        </div>
    </div>
</body>
</html>
