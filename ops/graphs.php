<?php
declare(strict_types=1);

/**
 * Graph tooling hub.
 * Add future tabs by appending entries to $tabs.
 */
$tabs = [
    'neo4j-qa' => [
        'label' => 'Neo4j QA',
        'url' => '/ops/neo4j_qa.php',
        'description' => 'Graph counts, relation quality checks, and Cypher helpers.',
    ],
    'word-cleanup' => [
        'label' => 'Word Cleanup',
        'url' => '/ops/word_cleanup.php',
        'description' => 'Stopwords, recategorization, and cleanup notes.',
    ],
  'graph-refresh' => [
    'label' => 'Graph Refresh',
    'url' => '/ops/graph_refresh.php',
    'description' => 'Rebuild graph from local corpus after rule changes.',
  ],
];

$tab = trim((string)($_GET['tab'] ?? 'neo4j-qa'));
if (!isset($tabs[$tab])) {
    $tab = 'neo4j-qa';
}

$active = $tabs[$tab];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Graphs</title>
  <style>
    :root {
      --bg: #f3efe8;
      --panel: #fffdf9;
      --edge: #d7cec1;
      --ink: #1f1a14;
      --muted: #6c6358;
      --accent: #264653;
      --accent-soft: #dce8ea;
    }
    body {
      margin: 0;
      font-family: Georgia, serif;
      color: var(--ink);
      background:
        radial-gradient(circle at 12% 8%, rgba(38, 70, 83, 0.10), transparent 26%),
        radial-gradient(circle at 88% 18%, rgba(159, 89, 46, 0.10), transparent 24%),
        linear-gradient(180deg, #f7f3ec, #efe8dc 68%, #f7f3ec);
    }
    main {
      max-width: 1480px;
      margin: 0 auto;
      padding: 20px;
    }
    .hero {
      background: var(--panel);
      border: 1px solid var(--edge);
      border-radius: 14px;
      padding: 18px;
      margin-bottom: 16px;
      box-shadow: 0 8px 24px rgba(52, 39, 21, 0.08);
    }
    .hero h1 {
      margin: 0 0 8px;
      font-size: clamp(1.8rem, 3vw, 2.6rem);
    }
    .muted {
      color: var(--muted);
      margin: 0;
    }
    .tabs {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin: 14px 0 0;
    }
    .tab {
      display: inline-block;
      border-radius: 999px;
      padding: 8px 14px;
      text-decoration: none;
      border: 1px solid var(--edge);
      color: var(--ink);
      background: #f8f3ea;
      font-size: 0.96rem;
    }
    .tab.active {
      border-color: var(--accent);
      background: var(--accent-soft);
      color: #17323b;
      font-weight: 600;
    }
    .surface {
      background: var(--panel);
      border: 1px solid var(--edge);
      border-radius: 14px;
      padding: 12px;
      box-shadow: 0 8px 24px rgba(52, 39, 21, 0.08);
    }
    .surface-head {
      margin: 0 0 10px;
      padding: 2px 4px;
    }
    .surface-head h2 {
      margin: 0 0 4px;
      font-size: 1.2rem;
    }
    .surface-head p {
      margin: 0;
      color: var(--muted);
    }
    iframe {
      width: 100%;
      height: calc(100vh - 260px);
      min-height: 700px;
      border: 1px solid var(--edge);
      border-radius: 10px;
      background: #fff;
    }
    @media (max-width: 900px) {
      iframe {
        height: calc(100vh - 280px);
        min-height: 600px;
      }
    }
  </style>
</head>
<body>
<main>
  <section class="hero">
    <h1>Graphs</h1>
    <p class="muted">Graph-related QA and cleanup tools in one place. Add more tabs in <code>ops/graphs.php</code> as the toolkit grows.</p>
    <div class="tabs">
      <?php foreach ($tabs as $tabId => $tabMeta): ?>
        <a class="tab <?php echo $tabId === $tab ? 'active' : ''; ?>" href="/ops/graphs?tab=<?php echo h($tabId); ?>">
          <?php echo h((string)$tabMeta['label']); ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="surface">
    <div class="surface-head">
      <h2><?php echo h((string)$active['label']); ?></h2>
      <p><?php echo h((string)$active['description']); ?></p>
    </div>
    <iframe title="<?php echo h((string)$active['label']); ?>" src="<?php echo h((string)$active['url']); ?>"></iframe>
  </section>
</main>
</body>
</html>