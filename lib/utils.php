<?php

function getBlockedSourceGroups(): array
{
	$blockedRaw = $_ENV['BLOCKED_SOURCES'] ?? getenv('BLOCKED_SOURCES') ?? '';
	$blockedGroups = array_filter(array_map('trim', explode(',', (string)$blockedRaw)));
	return array_map('strtolower', $blockedGroups);
}

function filterSourceGroupCounts(array $counts): array
{
	$blockedGroups = getBlockedSourceGroups();
	if (empty($blockedGroups)) {
		return $counts;
	}

	$blockedLookup = array_flip($blockedGroups);
	foreach ($counts as $group => $value) {
		if (isset($blockedLookup[strtolower((string)$group)])) {
			unset($counts[$group]);
		}
	}

	return $counts;
}

function filterSourcesByBlockedGroups(array $sources): array
{
	$blockedGroups = getBlockedSourceGroups();
	if (empty($blockedGroups)) {
		return $sources;
	}

	$blockedLookup = array_flip($blockedGroups);
	$sources = array_filter($sources, function ($source) use ($blockedLookup) {
		$group = strtolower($source['groupname'] ?? '');
		return $group === '' || !isset($blockedLookup[$group]);
	});

	return array_values($sources);
}

?>

