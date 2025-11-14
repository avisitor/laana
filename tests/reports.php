<?php
/**
 * Test Reports Browser
 * Browse and view all available test reports
 */

// Set Hawaii timezone for proper time display
date_default_timezone_set('Pacific/Honolulu');

$reportsDir = __DIR__ . '/reports';
$jsonFiles = glob($reportsDir . '/test-report-*.json');

// Sort by filename (newest first)
rsort($jsonFiles);

// If viewing a specific report
$viewReport = $_GET['report'] ?? null;
if ($viewReport && file_exists($reportsDir . '/' . $viewReport)) {
    // Redirect to index.php to view the report in the full dashboard
    header('Location: index.php?report=' . urlencode($viewReport));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Test Reports Browser - Noiiolelo</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .nav {
            padding: 15px 20px;
            background: #e9ecef;
            border-bottom: 1px solid #dee2e6;
        }
        
        .nav a {
            color: #6366f1;
            text-decoration: none;
            margin-right: 15px;
            font-weight: 500;
        }
        
        .nav a:hover {
            text-decoration: underline;
        }
        
        .content {
            padding: 20px;
        }
        
        .reports-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .reports-table th,
        .reports-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .reports-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .reports-table tr:hover {
            background: #f8f9fa;
        }
        
        .report-link {
            color: #6366f1;
            text-decoration: none;
            font-family: monospace;
        }
        
        .report-link:hover {
            text-decoration: underline;
        }
        
        .status-good { color: #28a745; }
        .status-issues { color: #ffc107; }
        .status-bad { color: #dc3545; }
        
        .no-reports {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .quick-stats {
            display: inline-block;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.9em;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Test Reports Browser</h1>
            <p>Noiiolelo Test Suite History</p>
        </div>
        
        <div class="nav">
            <a href="index.php">🏠 Latest Dashboard</a>
            <a href="reports.php">📊 All Reports</a>
            <a href="reports/">📁 Reports Folder</a>
        </div>
        
        <div class="content">
            <?php if (empty($jsonFiles)): ?>
                <div class="no-reports">
                    <h2>No test reports found</h2>
                    <p>Run tests first with: <code>./tests/run-tests.sh</code></p>
                </div>
            <?php else: ?>
                <h2>Available Test Reports (<?= count($jsonFiles) ?> total)</h2>
                
                <table class="reports-table">
                    <thead>
                        <tr>
                            <th>Report</th>
                            <th>Timestamp</th>
                            <th>Results</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jsonFiles as $jsonFile): 
                            $data = json_decode(file_get_contents($jsonFile), true);
                            $basename = basename($jsonFile);
                            $timestamp = new DateTime($data['timestamp']);
                            
                            // Determine status class
                            if ($data['failures'] == 0 && $data['errors'] == 0) {
                                $statusClass = 'status-good';
                                $statusText = '✓ All Passed';
                            } elseif ($data['failures'] > 0 && $data['errors'] == 0) {
                                $statusClass = 'status-issues';
                                $statusText = '⚠ Some Failures';
                            } else {
                                $statusClass = 'status-bad';
                                $statusText = '✗ Errors';
                            }
                        ?>
                        <tr>
                            <td>
                                <a href="index.php?report=<?= urlencode($basename) ?>" class="report-link">
                                    <?= htmlspecialchars($basename) ?>
                                </a>
                            </td>
                            <td><?= $timestamp->format('Y-m-d H:i:s T') ?></td>
                            <td>
                                <span class="quick-stats">
                                    <span class="status-good"><?= $data['passed'] ?> ✓</span>
                                    <?php if ($data['failures'] > 0): ?>
                                        <span class="status-issues"><?= $data['failures'] ?> ✗</span>
                                    <?php endif; ?>
                                    <?php if ($data['errors'] > 0): ?>
                                        <span class="status-bad"><?= $data['errors'] ?> ⚠</span>
                                    <?php endif; ?>
                                    (<?= $data['success_rate'] ?>%)
                                </span>
                            </td>
                            <td><span class="<?= $statusClass ?>"><?= $statusText ?></span></td>
                            <td>
                                <a href="index.php?report=<?= urlencode($basename) ?>">View</a>
                                |
                                <a href="reports/<?= urlencode($basename) ?>" download>Download</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
