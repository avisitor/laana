<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/provider.php';

function formatCount($value): string {
    if (is_numeric($value)) {
        return number_format((float)$value);
    }
    return (string)$value;
}

$providers = getKnownProviders();

$results = [];
foreach ($providers as $providerName) {
    try {
        $provider = getProvider($providerName);
        $stats = $provider->getCorpusStats();
        $groupCounts = $provider->getSourceGroupCounts();
        $grammarPatterns = $provider->getGrammarPatterns();

        usort($grammarPatterns, function ($a, $b) {
            return (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0);
        });

        $patternTotal = 0;
        foreach ($grammarPatterns as $row) {
            $patternTotal += (int)($row['count'] ?? 0);
        }
        $stats['sentence_patterns_total'] = $patternTotal;
        $stats['grammar_pattern_counts'] = count($grammarPatterns);

        ksort($stats);
        ksort($groupCounts);

        $results[] = [
            'name' => $provider->getName(),
            'stats' => $stats,
            'groups' => $groupCounts,
            'grammar' => $grammarPatterns,
            'error' => null,
        ];
    } catch (Throwable $e) {
        $results[] = [
            'name' => $providerName,
            'stats' => [],
            'groups' => [],
            'grammar' => [],
            'error' => $e->getMessage(),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Noiiolelo Provider Stats Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f7f7f7; color: #222; }
        h1 { margin-bottom: 10px; }
        .provider { background: #fff; border-radius: 8px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .provider h2 { margin: 0 0 12px 0; }
        .error { color: #b00020; font-weight: bold; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e5e5; }
        th { background: #fafafa; font-weight: 600; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
    <h1>Noiiolelo Provider Stats Dashboard</h1>
    <p class="muted"><a href="/">Noiiolelo</a></p>

    <?php foreach ($results as $row): ?>
        <div class="provider">
            <h2><?php echo htmlspecialchars($row['name']); ?></h2>

            <?php if ($row['error']): ?>
                <div class="error">Error: <?php echo htmlspecialchars($row['error']); ?></div>
            <?php else: ?>
                <div class="grid">
                    <div>
                        <h3>Totals</h3>
                        <?php if (empty($row['stats'])): ?>
                            <div class="muted">No stats returned.</div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr><th>Metric</th><th>Count</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($row['stats'] as $key => $value): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$key); ?></td>
                                            <td class="num"><?php echo htmlspecialchars(formatCount($value)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3>Counts by Groupname</h3>
                        <?php if (empty($row['groups'])): ?>
                            <div class="muted">No groupname counts returned.</div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr><th>Groupname</th><th>Count</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($row['groups'] as $group => $count): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$group); ?></td>
                                            <td class="num"><?php echo htmlspecialchars(formatCount($count)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3>Grammar Patterns</h3>
                        <?php if (empty($row['grammar'])): ?>
                            <div class="muted">No grammar pattern counts returned.</div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr><th>Pattern</th><th>Count</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($row['grammar'] as $pattern): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)($pattern['pattern_type'] ?? '')); ?></td>
                                            <td class="num"><?php echo htmlspecialchars(formatCount($pattern['count'] ?? 0)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>