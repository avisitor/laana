<?php
declare(strict_types=1);

$lastRunFile = __DIR__ . '/graph_refresh_last_run.json';
$lastRun = null;

if (is_file($lastRunFile)) {
    $raw = file_get_contents($lastRunFile);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $lastRun = $decoded;
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatScalar($value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    return (string)$value;
}

$commands = [
    'Full rebuild (recommended after stopword/rule changes)' =>
        'php scripts/rebuild_graph_from_local.php --verbose',
    'Rebuild with explicit limits for testing' =>
        'php scripts/rebuild_graph_from_local.php --limit=250 --batch-size=50 --verbose',
    'Rebuild without clearing existing graph first' =>
        'php scripts/rebuild_graph_from_local.php --no-clear --verbose',
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Graph Refresh</title>
  <style>
    :root { --bg: #f2ede3; --panel: #fffdf8; --edge: #d5cab6; --ink: #1f1b14; --muted: #6f6658; --accent: #29535c; }
    body { margin: 0; font-family: Georgia, serif; color: var(--ink); background: linear-gradient(180deg, #f7f2e8, #efe7d9 64%, #f7f2e8); }
    main { max-width: 1180px; margin: 0 auto; padding: 24px; }
    .panel { background: var(--panel); border: 1px solid var(--edge); border-radius: 14px; padding: 18px; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(52, 39, 21, 0.08); }
    h1, h2 { margin: 0 0 10px; }
    p { margin: 0 0 10px; }
    .muted { color: var(--muted); }
    pre { margin: 0; background: #28231f; color: #f7efe2; border-radius: 10px; padding: 12px; overflow-x: auto; }
    code { background: #efe5d4; padding: 2px 6px; border-radius: 6px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 8px 10px; border-bottom: 1px solid #e9dfcf; text-align: left; vertical-align: top; }
    th { background: #f5ecde; }
    a { color: #7a4a16; }
  </style>
</head>
<body>
<main>
  <section class="panel">
    <h1>Graph Refresh</h1>
    <p>Use this workflow after changing stopwords, relationship rules, or entity extraction behavior.</p>
    <p class="muted">It rebuilds the graph from locally stored corpus text (<code>contents.text</code>) and does not re-fetch source websites.</p>
    <p><a href="/ops/graphs">Graphs Hub</a> · <a href="/ops/graphs?tab=neo4j-qa">Neo4j QA Tab</a> · <a href="/ops/graphs?tab=word-cleanup">Word Cleanup Tab</a></p>
  </section>

  <section class="panel">
    <h2>Run Commands</h2>
    <?php foreach ($commands as $label => $command): ?>
      <p><strong><?php echo h($label); ?></strong></p>
      <pre><?php echo h($command); ?></pre>
      <p></p>
    <?php endforeach; ?>
  </section>

  <section class="panel">
    <h2>Behavior Notes</h2>
    <p>Default mode clears the existing graph first, then rebuilds it from local text. This gives consistent results after rule changes.</p>
    <p><code>--no-clear</code> is available, but for global rule changes a full clear-and-rebuild is usually safer.</p>
    <p>Last run summary is written to <code>ops/graph_refresh_last_run.json</code>.</p>
  </section>

  <section class="panel">
    <h2>Last Run</h2>
    <?php if ($lastRun === null): ?>
      <p class="muted">No run history recorded yet.</p>
    <?php else: ?>
      <table>
        <tbody>
        <?php foreach ($lastRun as $key => $value): ?>
          <tr>
            <th><?php echo h((string)$key); ?></th>
            <td><?php echo h(formatScalar($value)); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
