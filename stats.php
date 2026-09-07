<?php
// Simple framework page for Stats Dashboard
$base = './'; // Adjust based on where this file is relative to root, assuming root.
require_once __DIR__ . '/lib/provider.php';
// Persist a deliberate ?provider= selection before any output (the stats
// shell itself constructs no provider; the tab iframes use the cookie).
rememberProviderSelection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'common-head.html'; ?>
    <title>Statistics Dashboard - Noiʻiʻōlelo</title>
    <script>
        var pattern ="";
        var orderBy ="";
    </script>
    <!-- Add Bootstrap JS for tabs (not present in common-head) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.4.1/js/bootstrap.bundle.min.js"></script>
</head>
<body id="fadein" onload="changeid()">

    <!-- Header / Navigation matching index.php style; provider pinned across tabs -->
    <?php
        $selectedProviderName = resolveProviderName();
        $providerParam = 'provider=' . urlencode($selectedProviderName);
        $homeProviderQuery = "?$providerParam";
        $tabProviderQuery = "&$providerParam";
    ?>
    <ul class="nav nav-tabs">
        <li class="nav-item" style="display:flex; align-items:center; gap:0.4em; padding:0.4em 0.8em 0.4em 0; margin-right:auto;">
            <label for="provider-select" style="font-size:0.85em; font-weight:600; margin:0;">Provider:</label>
            <select id="provider-select" class="dd-menu" onchange="switchProvider(this)" style="font-size:0.85em; max-width:12em;">
                <?php foreach (array_keys(getKnownProviders()) as $provName): ?>
                    <option value="<?=$provName?>" <?= strcasecmp($selectedProviderName, $provName) === 0 ? 'selected' : '' ?>><?=$provName?></option>
                <?php endforeach; ?>
            </select>
        </li>
        <li class="nav-item"><a href="index.php<?=$homeProviderQuery?>" class="nav-link">Home</a></li>
        <li class="nav-item"><a href="index.php?sources<?=$tabProviderQuery?>" class="nav-link">Sources</a></li>
        <li class="nav-item"><a href="index.php?resources<?=$tabProviderQuery?>" class="nav-link">Resources</a></li>
        <li class="nav-item"><a href="index.php?grammar<?=$tabProviderQuery?>" class="nav-link">Grammar</a></li>
        <li class="nav-item"><a href="stats.php<?=$homeProviderQuery?>" class="nav-link active">Stats</a></li>
    </ul>

    <div class="container-fluid stats-container">
        <!-- Nav tabs -->
        <ul class="nav nav-tabs" id="statsTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="grammarstats-tab" data-toggle="tab" href="#grammarstats" role="tab" aria-controls="grammarstats" aria-selected="true" data-src="grammar_stats.php">
                    Grammar Stats
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="wordstats-tab" data-toggle="tab" href="#wordstats" role="tab" aria-controls="wordstats" aria-selected="false" data-src="wordstats.html">
                    Word Stats
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="searchstats-tab" data-toggle="tab" href="#searchstats" role="tab" aria-controls="searchstats" aria-selected="false" data-src="searchstats.php">
                    Search Stats
                </a>
            </li>
        </ul>

        <!-- Tab panes -->
        <div class="tab-content" id="statsTabContent">
            <div class="tab-pane fade show active" id="grammarstats" role="tabpanel" aria-labelledby="grammarstats-tab">
                <!-- Content will be loaded here -->
                <iframe src="" id="iframe-grammarstats" class="stats-frame" title="Grammar Statistics"></iframe>
            </div>
            <div class="tab-pane fade" id="wordstats" role="tabpanel" aria-labelledby="wordstats-tab">
                <!-- Content will be loaded here -->
                <iframe src="" id="iframe-wordstats" class="stats-frame" title="Word Statistics"></iframe>
            </div>
            <div class="tab-pane fade" id="searchstats" role="tabpanel" aria-labelledby="searchstats-tab">
                <!-- Content will be loaded here -->
                <iframe src="" id="iframe-searchstats" class="stats-frame" title="Search Statistics"></iframe>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Function to load iframe content
            function loadTabContent(tabLink) {
                var targetId = $(tabLink).attr('href'); // #wordstats or #searchstats
                var srcUrl = $(tabLink).data('src');
                var iframe = $(targetId).find('iframe');

                // Check if iframe already has src
                if (iframe.attr('src') === "" || iframe.attr('src') === "about:blank") {
                    console.log("Loading content for " + targetId + " from " + srcUrl);
                    iframe.attr('src', srcUrl);
                }
            }

            // Load the active tab on page load
            var activeTab = $('#statsTabs .nav-link.active');
            if(activeTab.length > 0) {
                loadTabContent(activeTab);
            }

            // Event listener for tab show
            $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                loadTabContent(e.target);
            });
        });
    </script>
</body>
</html>
