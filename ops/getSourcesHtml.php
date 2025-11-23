<?php
require_once __DIR__ . '/../lib/provider.php';

// Get parameters
$page = intval($_GET['page'] ?? 1);
$groupname = $_GET['group'] ?? '';
$search = $_GET['search'] ?? '';
$providerName = $_GET['provider'] ?? '';
$sortBy = $_GET['sort'] ?? '';
$sortDir = $_GET['dir'] ?? 'asc';

$provider = getProvider($providerName);

// Get page size from provider
$pageSize = 50; // Default page size for sources

// Calculate offset (page is 1-indexed from InfiniteScroll)
$offset = ($page - 1) * $pageSize;

// Get sources with sort parameters
$allSources = $provider->getSources($groupname, [], $sortBy, $sortDir);

// Debug output
if (empty($allSources)) {
    error_log("getSourcesHtml: No sources returned for groupname='$groupname', provider='{$provider->getName()}'");
} else {
    error_log("getSourcesHtml: Got " . count($allSources) . " sources for groupname='$groupname'");
}

// Apply search filter if provided
if ($search) {
    $searchLower = strtolower($search);
    $allSources = array_filter($allSources, function($row) use ($searchLower) {
        $sourceName = strtolower($row['sourcename'] ?? '');
        $authors = strtolower($row['authors'] ?? '');
        $groupname = strtolower($row['groupname'] ?? '');
        $link = strtolower($row['link'] ?? '');
        return strpos($sourceName, $searchLower) !== false ||
               strpos($authors, $searchLower) !== false ||
               strpos($groupname, $searchLower) !== false ||
               strpos($link, $searchLower) !== false;
    });
    // Re-index array
    $allSources = array_values($allSources);
}

// Get page of results (provider already sorted the data)
$sources = array_slice($allSources, $offset, $pageSize);

// Wrap in tbody so jQuery can parse the tr elements
echo '<tbody>';

// Generate HTML for this page
foreach ($sources as $row) {
    $source = $row['sourcename'];
    $sourceid = $row['sourceid'];
    $providerParam = $providerName ? "&provider=$providerName" : '';
    $providerAttr = $providerName ? " provider='$providerName'" : '';
    $plainlink = "<a class='context fancy' sourceid='$sourceid' simplified='1'$providerAttr href='rawpage.php?simplified&id=$sourceid$providerParam' target='_blank'>Plain</a>";
    $htmllink = "<a class='context fancy' sourceid='$sourceid' simplified='0'$providerAttr href='rawpage.php?id=$sourceid$providerParam' target='_blank'>HTML</a>";
    $authors = $row['authors'] ?? '';
    $link = $row['link'];
    $date = $row['date'] ?? '';
    $groupname_val = $row['groupname'] ?? '';
    $group = $groupname_val . " ($sourceid)";
    $sourcelink = "<a class='fancy' href='$link' target='_blank'>$source</a>";
    $count = $row['sentencecount'] ?? 0;
?>
<tr class="source-row">
    <td class="hawaiiansentence"><?=htmlspecialchars($group)?></td>
    <td class="hawaiiansentence"><?=$sourcelink?></td>
    <td class="hawaiiansentence"><?=htmlspecialchars($date)?></td>
    <td class="hawaiiansentence"><?=$htmllink?></td>
    <td class="hawaiiansentence"><?=$plainlink?></td>
    <td class='authors'><?=htmlspecialchars($authors)?></td>
    <td class="hawaiiansentence" style="text-align:right;"><?=$count?></td>
</tr>
<?php
}

echo '</tbody>';

error_log("getSourcesHtml: Generated HTML for " . count($sources) . " sources (offset=$offset, pageSize=$pageSize)");
