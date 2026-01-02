<?php
// namespace Noiiolelo; // Removed to allow inclusion in index.php
$isIncluded = defined('INCLUDED_FROM_INDEX');
?>
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

<?php if (!$isIncluded): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Noiʻi ʻŌlelo - Grammar Pattern Analysis</title>
    <!-- Include common head elements -->
    <?php 
    if (file_exists(__DIR__ . '/common-head.html')) {
        readfile(__DIR__ . '/common-head.html'); 
    } else if (file_exists(__DIR__ . '/../common/common-head.html')) {
        readfile(__DIR__ . '/../common/common-head.html'); 
    } else {
        // Fallback if common-head is missing
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">';
        echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>';
    }
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php endif; ?>
    <style>
        /* Override parent .sentences styles to remove the blue box */
        .sentences {
            background: transparent !important;
            box-shadow: none !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            color: inherit !important;
            max-width: 100% !important;
        }

        .stats-container {
            width: 100%;
            max-width: 1400px; /* Increased width */
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
            color: #333;
            border-radius: 8px;
        }
        .chart-wrapper {
            position: relative;
            height: 500px; /* Increased height */
            width: 100%;
            margin-bottom: 30px;
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stats-header {
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        .stats-header h2 {
            color: #333; /* Ensure header is visible */
        }
        /* Only apply body background if standalone, or let index.php handle it */
        <?php if (!$isIncluded): ?>
        body {
            background-color: #f8f9fa;
        }
        <?php endif; ?>
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10;
            border-radius: 8px;
        }
    </style>
<?php if (!$isIncluded): ?>
</head>
<body>
<?php endif; ?>

<div class="stats-container">
    <div class="stats-header">
        <h2><i class="bi bi-graph-up"></i> Grammar Pattern Analysis</h2>
        <p class="lead">Statistical distribution of Hawaiian grammatical patterns in the corpus.</p>
        <?php if (!$isIncluded): ?>
        <a href="index.php" class="btn btn-outline-primary">&larr; Back to Search</a>
        <?php endif; ?>
    </div>

    <!-- Global Chart -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Global Pattern Distribution</h5>
        </div>
        <div class="card-body position-relative">
             <div id="globalLoading" class="loading-overlay">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="globalChart"></canvas>
            </div>
            <p class="text-muted small mt-2">Total occurrences of each grammatical pattern across the entire corpus.</p>
        </div>
    </div>

    <!-- Timeline Chart -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Pattern Frequency Over Time (Decades)</h5>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="logScaleToggle">
                <label class="form-check-label" for="logScaleToggle">Logarithmic Scale</label>
            </div>
        </div>
        <div class="card-body position-relative">
            <div id="timelineLoading" class="loading-overlay">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="timelineChart"></canvas>
            </div>
            <p class="text-muted small mt-2">Frequency of patterns grouped by decade.</p>
        </div>
    </div>
</div>

<script>
// Configuration
const startYear = 1830;
const endYear = 2020;
const step = 10;

// Palette generation logic (client-side)
function generatePalette(count) {
    const palette = [
        'hsla(0, 100%, 50%, 1)',   // Red
        'hsla(240, 100%, 50%, 1)', // Blue
        'hsla(120, 100%, 35%, 1)', // Green (darker for visibility)
        'hsla(39, 100%, 50%, 1)',  // Orange
        'hsla(300, 100%, 25%, 1)', // Purple
        'hsla(180, 100%, 40%, 1)', // Teal
        'hsla(60, 100%, 40%, 1)',  // Yellow/Gold (darker)
        'hsla(300, 100%, 50%, 1)', // Magenta
        'hsla(0, 0%, 0%, 1)',      // Black
        'hsla(33, 100%, 50%, 1)',  // Brown-ish
        'hsla(210, 100%, 50%, 1)', // Azure
        'hsla(270, 100%, 50%, 1)', // Violet
        'hsla(90, 100%, 40%, 1)',  // Lime green (darker)
        'hsla(150, 100%, 40%, 1)', // Spring green
        'hsla(15, 100%, 50%, 1)',  // Red-Orange
    ];
    
    const colors = [];
    for (let i = 0; i < count; i++) {
        if (i < palette.length) {
            colors.push(palette[i]);
        } else {
            const h = (i * 137.508) % 360; 
            colors.push(`hsla(${h}, 80%, 45%, 1)`);
        }
    }
    return colors;
}

let globalChart = null;
let timelineChart = null;
let patternColorMap = {};

// Toggle Log Scale
document.getElementById('logScaleToggle').addEventListener('change', function(e) {
    if (timelineChart) {
        const isLog = e.target.checked;
        timelineChart.options.scales.y.type = isLog ? 'logarithmic' : 'linear';
        if (isLog) {
            // Set min to epsilon for log scale to show 0 values (mapped to 0.1) at the bottom
            timelineChart.options.scales.y.min = 0.1;
        } else {
            delete timelineChart.options.scales.y.min;
            timelineChart.options.scales.y.beginAtZero = true;
        }
        timelineChart.update();
    }
});

// 1. Load Global Stats
async function loadGlobalStats() {
    try {
        // Use existing endpoint, passing provider explicitly
        const response = await fetch('ops/getGrammarPatterns.php?provider=MySQL');
        const stats = await response.json();
        
        // Check for error object
        if (stats.error) throw new Error(stats.error);
        if (!Array.isArray(stats)) throw new Error("Invalid response format");
        
        // Sort by count descending
        stats.sort((a, b) => b.count - a.count);
        
        const labels = stats.map(s => s.pattern_type);
        const counts = stats.map(s => s.count);
        const colors = generatePalette(stats.length);
        
        // Store colors for timeline consistency
        stats.forEach((s, i) => {
            patternColorMap[s.pattern_type] = colors[i];
        });

        const ctxGlobal = document.getElementById('globalChart').getContext('2d');
        globalChart = new Chart(ctxGlobal, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Occurrences',
                    data: counts,
                    backgroundColor: colors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        display: true,
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                            font: { size: 11 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        
        document.getElementById('globalLoading').style.display = 'none';
        
        // Start loading timeline after global is done (to have colors ready)
        loadTimelineStats(labels);
        
    } catch (e) {
        console.error("Error loading global stats:", e);
        document.getElementById('globalLoading').innerHTML = `<div class="alert alert-danger">Error loading data: ${e.message}</div>`;
    }
}

// 2. Load Timeline Stats
async function loadTimelineStats(allPatterns) {
    const labels = [];
    const datasets = {};
    
    // Initialize datasets
    allPatterns.forEach(type => {
        datasets[type] = {
            label: type,
            data: [],
            borderColor: patternColorMap[type],
            backgroundColor: 'transparent',
            tension: 0.3,
            borderWidth: 2
        };
    });

    // Create chart instance first
    const ctxTimeline = document.getElementById('timelineChart').getContext('2d');
    timelineChart = new Chart(ctxTimeline, {
        type: 'line',
        data: {
            labels: [],
            datasets: []
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 10 } },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            const value = context.raw;
                            // Display 0 for epsilon values (0.1)
                            label += (value < 1 ? 0 : value).toLocaleString();
                            return label;
                        }
                    }
                }
            },
            scales: { 
                y: { 
                    beginAtZero: true, 
                    title: { display: true, text: 'Count' },
                    ticks: {
                        callback: function(value, index, values) {
                            if (value < 1) return '0';
                            return Number(value).toLocaleString();
                        }
                    }
                } 
            }
        }
    });

    // Fetch data for each decade
    const promises = [];
    for (let year = startYear; year < endYear; year += step) {
        const rangeStart = year;
        const rangeEnd = year + step - 1;
        const label = `${rangeStart}s`;
        labels.push(label);
        
        // Fetch individually using existing endpoint
        const p = fetch(`ops/getGrammarPatterns.php?provider=MySQL&from=${rangeStart}&to=${rangeEnd}`)
            .then(r => r.json())
            .then(result => {
                if (result.error) return { label, periodMap: {} };
                
                const periodMap = {};
                if (Array.isArray(result)) {
                    result.forEach(s => periodMap[s.pattern_type] = s.count);
                }
                return { label, periodMap };
            })
            .catch(e => ({ label, periodMap: {} }));
            
        promises.push(p);
    }

    // Wait for all to complete (or could update incrementally)
    try {
        const results = await Promise.all(promises);
        
        // Sort results by year to ensure correct order in chart
        // (Promise.all preserves order of promises, so this matches 'labels' array)
        
        results.forEach((res, index) => {
            allPatterns.forEach(type => {
                const count = res.periodMap[type] || 0;
                // Use 0.1 instead of 0 to allow log scale plotting
                datasets[type].data.push(count === 0 ? 0.1 : count);
            });
        });

        // Update chart
        timelineChart.data.labels = labels;
        timelineChart.data.datasets = Object.values(datasets);
        timelineChart.update();
        
        document.getElementById('timelineLoading').style.display = 'none';
        
    } catch (e) {
        console.error("Error loading timeline:", e);
        document.getElementById('timelineLoading').innerHTML = `<div class="alert alert-danger">Error loading timeline: ${e.message}</div>`;
    }
}

// Start
document.addEventListener('DOMContentLoaded', loadGlobalStats);

</script>
