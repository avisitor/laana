<?php
require_once __DIR__ . '/../env-loader.php';

loadEnv();

function neo4jRequest(string $query, array $parameters = []): array
{
    $uri = getenv('NEO4J_URI') ?: 'http://localhost:7474';
    $username = getenv('NEO4J_USERNAME') ?: 'neo4j';
    $password = getenv('NEO4J_PASSWORD') ?: 'password';
    $url = rtrim(str_replace(['bolt://', ':7687'], ['http://', ':7474'], $uri), '/') . '/db/neo4j/tx/commit';

    $payload = [
        'statements' => [[
            'statement' => $query,
            'parameters' => empty($parameters) ? new stdClass() : $parameters,
        ]],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $error !== '') {
        return ['error' => $error !== '' ? $error : 'Unknown Neo4j cURL error'];
    }

    if ($httpCode !== 200 && $httpCode !== 201) {
        return ['error' => 'Neo4j HTTP error ' . $httpCode, 'raw' => $response];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid JSON response from Neo4j', 'raw' => $response];
    }

    if (!empty($decoded['errors'])) {
        return ['error' => json_encode($decoded['errors'])];
    }

    $result = $decoded['results'][0] ?? ['columns' => [], 'data' => []];
    $columns = $result['columns'] ?? [];
    $rows = [];
    foreach (($result['data'] ?? []) as $row) {
        $values = $row['row'] ?? [];
        $assoc = [];
        foreach ($columns as $index => $column) {
            $assoc[$column] = $values[$index] ?? null;
        }
        $rows[] = $assoc;
    }

    return [
        'columns' => $columns,
        'rows' => $rows,
    ];
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function renderTable(string $title, array $result): void
{
    echo '<section class="panel">';
    echo '<h2>' . h($title) . '</h2>';

    if (!empty($result['error'])) {
        echo '<div class="error">' . h($result['error']) . '</div>';
        echo '</section>';
        return;
    }

    $rows = $result['rows'] ?? [];
    $columns = $result['columns'] ?? [];

    if (empty($rows)) {
        echo '<p class="muted">No rows returned.</p>';
        echo '</section>';
        return;
    }

    echo '<table><thead><tr>';
    foreach ($columns as $column) {
        echo '<th>' . h($column) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            echo '<td>' . h((string)$value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</section>';
}

$entityTerm = trim((string)($_GET['entity'] ?? ''));

$summary = neo4jRequest(
    'MATCH (e:GraphEntity) WITH count(e) AS entity_count '
    . 'MATCH (d:Document) WITH entity_count, count(d) AS document_count '
    . 'MATCH ()-[r]->() RETURN entity_count, document_count, count(r) AS relationship_count'
);

$labels = neo4jRequest(
    'MATCH (e:GraphEntity) '
    . 'UNWIND [label IN labels(e) WHERE label <> "GraphEntity"] AS label '
    . 'RETURN label, count(*) AS count ORDER BY count DESC LIMIT 20'
);

$relations = neo4jRequest(
    'MATCH ()-[r]->() RETURN type(r) AS relation, count(*) AS count ORDER BY count DESC LIMIT 20'
);

$highDegree = neo4jRequest(
    'MATCH (e:GraphEntity)-[r]-() '
    . 'RETURN e.id AS id, e.name AS name, labels(e) AS labels, count(r) AS degree '
    . 'ORDER BY degree DESC LIMIT 20'
);

$isolated = neo4jRequest(
    'MATCH (e:GraphEntity) WHERE NOT (e)--() '
    . 'RETURN e.id AS id, e.name AS name, labels(e) AS labels LIMIT 20'
);

$recentDocs = neo4jRequest(
    'MATCH (d:Document) OPTIONAL MATCH (d)<-[:MENTIONED_IN]-(e:GraphEntity) '
    . 'RETURN d.id AS id, d.title AS title, count(e) AS mentions, d.last_seen AS last_seen '
    . 'ORDER BY d.last_seen DESC LIMIT 20'
);

$entityLookup = null;
if ($entityTerm !== '') {
    $entityLookup = neo4jRequest(
        'MATCH (e:GraphEntity) '
        . 'WHERE e.id = $term OR toLower(e.name) CONTAINS toLower($term) '
        . 'OPTIONAL MATCH (e)-[r]-(n) '
        . 'RETURN e.id AS id, e.name AS name, labels(e) AS labels, type(r) AS relation, n.id AS neighbor_id, n.name AS neighbor_name '
        . 'LIMIT 50',
        ['term' => $entityTerm]
    );
}

$browserQueries = [
    'Sample graph neighborhood' => 'MATCH p=(e:GraphEntity)-[*1..2]-(n) RETURN p LIMIT 100',
    'High-degree entities' => 'MATCH (e:GraphEntity)-[r]-() RETURN e.name, labels(e), count(r) AS degree ORDER BY degree DESC LIMIT 25',
    'Recent documents with mentions' => 'MATCH (d:Document)<-[:MENTIONED_IN]-(e:GraphEntity) RETURN d.id, d.title, count(e) AS mentions ORDER BY d.last_seen DESC LIMIT 25',
    'Entity by name' => "MATCH p=(e:GraphEntity)-[*1..2]-(n) WHERE toLower(e.name) CONTAINS toLower('Kamehameha') RETURN p LIMIT 100",
    'Suspicious isolated entities' => 'MATCH (e:GraphEntity) WHERE NOT (e)--() RETURN e.id, e.name, labels(e) LIMIT 50',
];

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'summary' => $summary,
        'labels' => $labels,
        'relations' => $relations,
        'high_degree' => $highDegree,
        'isolated' => $isolated,
        'recent_docs' => $recentDocs,
        'entity_lookup' => $entityLookup,
        'browser_queries' => $browserQueries,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Neo4j QA</title>
  <style>
    body { font-family: Georgia, serif; margin: 0; background: #f4f1ea; color: #1f1d1a; }
    main { max-width: 1280px; margin: 0 auto; padding: 24px; }
    h1, h2 { margin: 0 0 12px; }
    .intro, .panel { background: #fffdf8; border: 1px solid #d8d0c2; border-radius: 12px; padding: 18px; margin-bottom: 18px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 18px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #ece5d9; vertical-align: top; }
    th { background: #f6efe2; }
    .error { color: #8a1f11; font-weight: 600; }
    .muted { color: #6b6255; }
    pre { background: #2e2a25; color: #f6efe2; padding: 12px; border-radius: 8px; overflow-x: auto; white-space: pre-wrap; }
    input[type="text"] { width: min(480px, 100%); padding: 10px 12px; border-radius: 8px; border: 1px solid #b8ad9c; font: inherit; }
    button { padding: 10px 14px; border: 0; border-radius: 8px; background: #264653; color: #fff; font: inherit; cursor: pointer; }
    a { color: #8a4b08; }
  </style>
</head>
<body>
<main>
  <section class="intro">
    <h1>Neo4j QA</h1>
    <p>Use this page to spot-check graph quality, find suspicious entities, and copy Cypher into Neo4j Browser for visualization.</p>
        <p><a href="/ops/graphs?tab=neo4j-qa">Graphs Hub</a> · <a href="/ops/graphs?tab=word-cleanup">Word Cleanup Tab</a> · <a href="/">Home</a></p>
    <p><strong>Neo4j Browser:</strong> <a href="http://localhost:7474/browser" target="_blank" rel="noopener noreferrer">http://localhost:7474/browser</a></p>
    <p class="muted">Tip: open Neo4j Browser in one tab, this QA page in another, and paste the queries below to visually inspect clusters and edge quality.</p>
  </section>

  <section class="panel">
    <h2>Lookup Entity</h2>
    <form method="get">
      <input type="text" name="entity" value="<?php echo h($entityTerm); ?>" placeholder="Entity name or exact id">
      <button type="submit">Inspect</button>
    </form>
  </section>

  <div class="grid">
    <?php renderTable('Summary', $summary); ?>
    <?php renderTable('Entity Labels', $labels); ?>
    <?php renderTable('Relationship Types', $relations); ?>
    <?php renderTable('High-Degree Entities', $highDegree); ?>
    <?php renderTable('Isolated Entities', $isolated); ?>
    <?php renderTable('Recent Documents', $recentDocs); ?>
  </div>

  <?php if ($entityLookup !== null): ?>
    <?php renderTable('Entity Lookup', $entityLookup); ?>
    <section class="panel">
      <h2>Browser Query For This Entity</h2>
      <pre><?php echo h("MATCH p=(e:GraphEntity)-[*1..2]-(n)\nWHERE e.id = '" . $entityTerm . "' OR toLower(e.name) CONTAINS toLower('" . $entityTerm . "')\nRETURN p\nLIMIT 100"); ?></pre>
    </section>
  <?php endif; ?>

  <section class="panel">
    <h2>Neo4j Browser Queries</h2>
    <?php foreach ($browserQueries as $title => $query): ?>
      <h3><?php echo h($title); ?></h3>
      <pre><?php echo h($query); ?></pre>
    <?php endforeach; ?>
  </section>
</main>
</body>
</html>