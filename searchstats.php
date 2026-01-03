<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?>
<?php
require_once __DIR__ . '/lib/provider.php';
function changeTimeZone( $date ) {
    if (!$date) {
        return 'N/A';
    }
    $datetime = new DateTime( $date );
    $newtime = new DateTimeZone('Pacific/Honolulu');
    $datetime->setTimezone($newtime);
    $date = $datetime->format('Y-m-d H:i:s');
    return $date;
}
$provider = getProvider();
$providerName = $provider->getName();
$rows = $provider->getSearchStats();

// Normalize patterns to lowercase in rows
foreach ($rows as &$row) {
    $row['pattern'] = strtolower($row['pattern'] ?? '');
}
unset($row);

// Re-calculate summary stats from normalized rows to ensure consistency
$statsMap = [];
foreach ($rows as $row) {
    $p = $row['pattern'];
    if (!isset($statsMap[$p])) {
        $statsMap[$p] = 0;
    }
    $statsMap[$p]++;
}
$stats = [];
foreach ($statsMap as $p => $count) {
    $stats[] = ['pattern' => $p, 'count' => $count];
}

$total = 0;
foreach( $stats as $stat ) {
    $total += $stat['count'];
}
$first = changeTimeZone( $provider->getFirstSearchTime() );

// Calculate average elapsed time per pattern
$patternElapsed = [];
foreach ($rows as $row) {
    $p = $row['pattern'];
    $e = (float)$row['elapsed'];
    if (!isset($patternElapsed[$p])) {
        $patternElapsed[$p] = ['total' => 0, 'count' => 0];
    }
    $patternElapsed[$p]['total'] += $e;
    $patternElapsed[$p]['count']++;
}

$avgElapsedStats = [];
foreach ($patternElapsed as $p => $data) {
    $avgElapsedStats[] = [
        'pattern' => $p,
        'avg_elapsed' => $data['total'] / $data['count']
    ];
}

// Sort for charts
$frequencyStats = $stats;
usort($frequencyStats, function($a, $b) { return $b['count'] <=> $a['count']; });

usort($avgElapsedStats, function($a, $b) { return $a['avg_elapsed'] <=> $b['avg_elapsed']; });

// Calculate histogram of elapsed time
$histogramBins = [
    '0-50ms' => 0,
    '50-100ms' => 0,
    '100-250ms' => 0,
    '250-500ms' => 0,
    '500-1000ms' => 0,
    '1000-2000ms' => 0,
    '2000ms+' => 0
];

foreach ($rows as $row) {
    $ms = (float)($row['elapsed'] ?? 0);
    if ($ms < 50) $histogramBins['0-50ms']++;
    elseif ($ms < 100) $histogramBins['50-100ms']++;
    elseif ($ms < 250) $histogramBins['100-250ms']++;
    elseif ($ms < 500) $histogramBins['250-500ms']++;
    elseif ($ms < 1000) $histogramBins['500-1000ms']++;
    elseif ($ms < 2000) $histogramBins['1000-2000ms']++;
    else $histogramBins['2000ms+']++;
}

$histogramData = [];
foreach ($histogramBins as $label => $count) {
    $histogramData[] = ['label' => $label, 'count' => $count];
}

// Calculate queries over time
$queriesByDate = [];
foreach ($rows as $row) {
    if (isset($row['created'])) {
        $localDate = changeTimeZone($row['created']);
        $date = substr($localDate, 0, 10); // YYYY-MM-DD in HST
        if (!isset($queriesByDate[$date])) {
            $queriesByDate[$date] = 0;
        }
        $queriesByDate[$date]++;
    }
}
ksort($queriesByDate);
$timelineData = [];
foreach ($queriesByDate as $date => $count) {
    $timelineData[] = ['date' => $date, 'count' => $count];
}
?>
<!DOCTYPE html>
<html lang="en" class="">
    <head>
<?php include 'common-head.html'; ?>
<title>Noiʻiʻōlelo searches</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body {
    padding: .2em;
}
.chart-container {
    background: white;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
</style>
<script>
	$(document).ready(function () {
        $('#table').DataTable({
            paging: false,
            order: [[ 5, "desc" ]],
            ordering: true,
        }
        );

        // Fetch providers dynamically
        const currentProvider = "<?=$providerName?>";
        const baseUrl = window.location.origin.replace('http:', 'https:') + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
        const apiUrl = baseUrl + 'api/providers';
        $.getJSON(apiUrl, function(providers) {
            const select = $('#provider-select');
            providers.forEach(function(p) {
                const selected = (p.toLowerCase() === currentProvider.toLowerCase()) ? 'selected' : '';
                select.append(`<option value="${p}" ${selected}>${p}</option>`);
            });
        });

        $('#provider-select').on('change', function() {
            const provider = $(this).val();
            const url = new URL(window.location.href);
            url.protocol = 'https:';
            url.searchParams.set('provider', provider);
            window.location.href = url.toString();
        });

        // Chart data
        const statsData = <?= json_encode($stats) ?>;
        const freqData = <?= json_encode($frequencyStats) ?>;
        const elapsedData = <?= json_encode($avgElapsedStats) ?>;
        const histData = <?= json_encode($histogramData) ?>;
        const timelineData = <?= json_encode($timelineData) ?>;

        // Pie Chart
        new Chart(document.getElementById('pieChart'), {
            type: 'pie',
            data: {
                labels: statsData.map(s => s.pattern),
                datasets: [{
                    data: statsData.map(s => s.count),
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#C9CBCF'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    title: { display: true, text: 'Query Type Distribution' }
                }
            }
        });

        // Frequency Bar Chart
        new Chart(document.getElementById('freqChart'), {
            type: 'bar',
            data: {
                labels: freqData.map(s => s.pattern),
                datasets: [{
                    label: 'Number of Queries',
                    data: freqData.map(s => s.count),
                    backgroundColor: '#36A2EB'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: { display: false }
                }
            }
        });

        // Elapsed Time Bar Chart
        new Chart(document.getElementById('elapsedChart'), {
            type: 'bar',
            data: {
                labels: elapsedData.map(s => s.pattern),
                datasets: [{
                    label: 'Avg Elapsed Time (ms)',
                    data: elapsedData.map(s => s.avg_elapsed),
                    backgroundColor: '#FF6384'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: { display: false }
                }
            }
        });

        // Histogram Chart
        new Chart(document.getElementById('histogramChart'), {
            type: 'bar',
            data: {
                labels: histData.map(h => h.label),
                datasets: [{
                    label: 'Number of Queries',
                    data: histData.map(h => h.count),
                    backgroundColor: '#4BC0C0'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    title: { display: true, text: 'Elapsed Time Histogram (All Queries)' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Count' }
                    },
                    x: {
                        title: { display: true, text: 'Elapsed Time Range' }
                    }
                }
            }
        });

        // Timeline Chart
        new Chart(document.getElementById('timelineChart'), {
            type: 'line',
            data: {
                labels: timelineData.map(t => t.date),
                datasets: [{
                    label: 'Queries per Day',
                    data: timelineData.map(t => t.count),
                    borderColor: '#36A2EB',
                    backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    fill: true,
                    tension: 0.1,
                    pointRadius: timelineData.length === 1 ? 5 : 3,
                    pointHitRadius: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    title: { display: true, text: 'Search Volume Over Time' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Number of Queries' },
                        ticks: {
                            stepSize: 1,
                            precision: 0
                        }
                    },
                    x: {
                        title: { display: true, text: 'Date' }
                    }
                }
            }
        });
	});
</script>
</head>
<body>
    <div style="padding: 1em; background: #f8f9fa; border-bottom: 1px solid #dee2e6; display: flex; align-items: center; justify-content: space-between;">
        <h2>Searches on Noiʻiʻōlelo since <?=$first?></h2>
        <div>
            <label for="provider-select" style="margin-right: 0.5em; font-weight: bold;">Search Provider:</label>
            <select id="provider-select" class="form-control" style="display: inline-block; width: auto;">
                <!-- Options populated dynamically -->
            </select>
        </div>
    </div>
    <p style="padding-left:1em; color:#666; font-style:italic; margin-top: 0.5em;">Current Provider: <?=$providerName?></p>
    
    <div style="padding:1em; display: flex; flex-wrap: wrap; gap: 20px;">
        <div style="flex: 1; min-width: 300px;">
            <h3>Summary</h3>
            <table class="table table-sm table-striped">
                <thead>
                    <tr><th style="width:6em">Type</th><th>Count</th></tr>
                </thead>
                <tbody>
<?php
foreach( $stats as $row ) {
    echo "<tr><td>{$row['pattern']}</td><td>{$row['count']}</td></tr>\n";
}
?>
                </tbody>
            </table>
            <h6 style="margin-top:.5em;">Total: <?=$total?></h6>
        </div>
        <div class="chart-container" style="flex: 1; min-width: 300px; max-width: 500px;">
            <canvas id="pieChart"></canvas>
        </div>
    </div>

    <div style="padding: 1em; display: flex; flex-wrap: wrap; gap: 20px;">
        <div class="chart-container" style="flex: 1; min-width: 450px;">
            <h4>Query Frequency</h4>
            <canvas id="freqChart"></canvas>
        </div>
        <div class="chart-container" style="flex: 1; min-width: 450px;">
            <h4>Average Elapsed Time (ms)</h4>
            <canvas id="elapsedChart"></canvas>
        </div>
    </div>

    <div style="padding: 1em;">
        <div class="chart-container" style="width: 100%;">
            <canvas id="timelineChart" style="max-height: 300px;"></canvas>
        </div>
    </div>

    <div style="padding: 1em;">
        <div class="chart-container" style="width: 100%;">
            <canvas id="histogramChart" style="max-height: 300px;"></canvas>
        </div>
    </div>

    <div class="sentences">
        <table id="table">
            <thead><tr><th>Search term</th><th>Type</th><th>Order</th><th>Results</th><th>Elapsed</th><th>Time</th></tr></thead>
            <tbody>

<?php
foreach( $rows as $row ) {
    $order = ($row['sort']) ? $row['sort'] : '';
    $elapsed = ($row['elapsed']) ? $row['elapsed'] : '';
    echo "<tr><td>{$row['searchterm']}</td><td>{$row['pattern']}</td><td>$order</td><td>{$row['results']}</td><td>$elapsed</td><td>" . changeTimeZone( $row['created'] ) . "</td></tr>\n";
}
?>
            <tbody>
        </table>
    </div></body>
</html>
