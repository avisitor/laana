<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use HawaiianSearch\HawaiianWordLoader;
use HawaiianSearch\WordCleanupStore;

use Authorization\AuthorizationClient;

\Avisitor\Env\Loader::load(__DIR__ . '/../.env');

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $idpUrl = (string)(\Avisitor\Env\Loader::get('IDP_URL') ?? '');
    if ($idpUrl !== '') {
        $payload = (new \WorldSpot\IDPClient\Auth\TokenManager())->decodeToken($token, rtrim($idpUrl, '/'));
        if (isset($payload['sub'])) {
            $_SESSION['jwt_token'] = $token;
            $_SESSION['auth_email'] = $payload['sub'];
        }
    }
}

$userEmail = (string)($_SESSION['auth_email'] ?? $_SESSION['email'] ?? $_SESSION['username'] ?? '');

$auth = new AuthorizationClient();
$auth->checkAuth($userEmail, ['roles'=>['admin','editor']]);

$availableFiles = WordCleanupStore::listOverrideFiles();
$selectedFile = (string)($_GET['file'] ?? basename(WordCleanupStore::getOverridesFilePath()));
$overrideFile = dirname(WordCleanupStore::getOverridesFilePath()) . '/' . $selectedFile;

if (!file_exists($overrideFile)) {
    $overrideFile = WordCleanupStore::getOverridesFilePath();
    $selectedFile = basename($overrideFile);
}
$message = null;
$error = null;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function parseWords(string $input): array
{
    $words = [];
    $lines = preg_split('/\R/u', $input) ?: [];

    foreach ($lines as $line) {
        foreach (preg_split('/\s*,\s*/u', trim($line)) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $words[] = $chunk;
            }
        }
    }

    return array_values(array_unique($words));
}

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

$actionLabels = [
    'stopword' => 'Stopword',
    'include' => 'Include in Hawaiian set',
    'review' => 'Review only',
    'note' => 'Note only',
    'clear' => 'Clear override',
];

// ---- JSON endpoint for infinite scroll ----
$format = (string)($_GET['format'] ?? '');
if ($format === 'json') {
    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(10, min(500, (int)($_GET['per_page'] ?? 100)));

    if ($search !== '') {
        $entityResult = neo4jRequest(
            'MATCH (e:GraphEntity) WHERE toLower(e.name) CONTAINS toLower($search) '
            . 'OPTIONAL MATCH (e)-[r]-() '
            . 'RETURN e.id AS id, e.name AS name, labels(e) AS labels, count(r) AS mention_count '
            . 'ORDER BY mention_count DESC '
            . 'LIMIT 200',
            ['search' => $search]
        );
        $total = count($entityResult['rows'] ?? []);
        $hasMore = false;
    } else {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(500, (int)($_GET['per_page'] ?? 100)));
        $offset = ($page - 1) * $perPage;

        $entityResult = neo4jRequest(
            'MATCH (e:GraphEntity) '
            . 'OPTIONAL MATCH (e)-[r]-() '
            . 'RETURN e.id AS id, e.name AS name, labels(e) AS labels, count(r) AS mention_count '
            . 'ORDER BY mention_count DESC '
            . 'SKIP ' . $offset . ' LIMIT ' . $perPage
        );

        $countResult = neo4jRequest(
            'MATCH (e:GraphEntity) RETURN count(e) AS total'
        );
        $total = (int)(($countResult['rows'][0]['total'] ?? 0));
        $hasMore = ($offset + $perPage) < $total;
    }

    $overrides = WordCleanupStore::loadOverrides($overrideFile);
    $stopwordMap = [];
    foreach ($overrides as $entry) {
        $n = $entry['normalized'] ?? WordCleanupStore::normalizeWord($entry['word'] ?? '');
        if ($n !== '') {
            $stopwordMap[$n] = $entry;
        }
    }

    $entities = [];
    foreach (($entityResult['rows'] ?? []) as $entity) {
        $ename = (string)($entity['name'] ?? '');
        $normalized = WordCleanupStore::normalizeWord($ename);
        $existingOverride = $stopwordMap[$normalized] ?? null;

        $elabels = $entity['labels'] ?? [];
        if (is_array($elabels)) {
            $elabels = array_values(array_filter($elabels, fn($l) => $l !== 'GraphEntity'));
        }

        $entities[] = [
            'id' => (string)($entity['id'] ?? ''),
            'name' => $ename,
            'labels' => $elabels,
            'mention_count' => (int)($entity['mention_count'] ?? 0),
            'override_status' => $existingOverride['action'] ?? null,
            'override_label' => $existingOverride ? ($actionLabels[$existingOverride['action']] ?? $existingOverride['action']) : null,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'entities' => $entities,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'has_more' => $hasMore,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- POST handlers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'bulk_override') {
            $entityIds = (array)($_POST['entity_ids'] ?? []);
            if (empty($entityIds)) {
                throw new \RuntimeException('Select at least one entity.');
            }

            $cleanupAction = trim((string)($_POST['cleanup_action'] ?? 'stopword'));
            $category = trim((string)($_POST['category'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($cleanupAction === 'clear') {
                WordCleanupStore::removeEntries($entityIds, $overrideFile);
                $message = 'Removed ' . count($entityIds) . ' override(s).';
            } else {
                WordCleanupStore::upsertEntries($entityIds, $cleanupAction, $category, $note, $overrideFile);
                $message = 'Saved ' . count($entityIds) . ' override(s).';
            }
        } elseif ($action === 'save_words') {
            $cleanupAction = trim((string)($_POST['cleanup_action'] ?? 'review'));
            $category = trim((string)($_POST['category'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $entityNames = parseWords((string)($_POST['entity_names'] ?? ''));

            if ($cleanupAction === 'clear') {
                WordCleanupStore::removeEntries($entityNames, $overrideFile);
                $message = 'Removed ' . count($entityNames) . ' override(s).';
            } else {
                WordCleanupStore::upsertEntries($entityNames, $cleanupAction, $category, $note, $overrideFile);
                $message = 'Saved ' . count($entityNames) . ' override(s).';
            }
        } elseif ($action === 'remove_word') {
            $words = (array)($_POST['words'] ?? []);
            $word = (string)($_POST['word'] ?? '');
            if ($word !== '') {
                $words[] = $word;
            }
            if (empty($words)) {
                throw new \RuntimeException('No word specified for removal.');
            }
            WordCleanupStore::removeEntries($words, $overrideFile);
            $message = 'Removed the selected override.';
        }

        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?saved=1');
        exit;
    } catch (\Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_file') {
    $newFilename = trim((string)($_POST['new_filename'] ?? ''));
    if ($newFilename !== '') {
        try {
            $newPath = WordCleanupStore::createNewFile($newFilename);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?file=' . basename($newPath) . '&created=1');
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $message = $message ?? 'Cleanup overrides updated.';
}

// ---- Load data for initial page render ----
$overrides = WordCleanupStore::loadOverrides($overrideFile);
$summary = WordCleanupStore::getSummary($overrideFile);
$effectiveWordSet = [];

try {
    $effectiveWordSet = HawaiianWordLoader::loadAsHashSet(__DIR__ . '/../hawaiian_words.txt');
} catch (\Throwable $throwable) {
    $effectiveWordSet = [];
}

$stopwordMap = [];
foreach ($overrides as $entry) {
    $n = $entry['normalized'] ?? WordCleanupStore::normalizeWord($entry['word'] ?? '');
    if ($n !== '') {
        $stopwordMap[$n] = $entry;
    }
}

$countResult = neo4jRequest('MATCH (e:GraphEntity) RETURN count(e) AS total');
$entityTotal = (int)(($countResult['rows'][0]['total'] ?? 0));

$initialPage = neo4jRequest(
    'MATCH (e:GraphEntity) '
    . 'OPTIONAL MATCH (e)-[r]-() '
    . 'RETURN e.id AS id, e.name AS name, labels(e) AS labels, count(r) AS mention_count '
    . 'ORDER BY mention_count DESC '
    . 'LIMIT 100'
);
$initialEntities = $initialPage['rows'] ?? [];
$neo4jError = isset($initialPage['error']) ? $initialPage['error'] : null;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Word Cleanup</title>
  <style>
    :root { --bg: #f5efe4; --panel: #fffdf8; --border: #d8ccb8; --ink: #1f1b16; --muted: #6d6254; --accent: #7f4f24; --accent-2: #2f5d62; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Georgia, serif; background: linear-gradient(180deg, #f7f1e6, #efe7db 65%, #f7f1e6); color: var(--ink); }
    main { max-width: 1360px; margin: 0 auto; padding: 24px; padding-bottom: 100px; }
    .hero, .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 8px 30px rgba(72, 51, 23, 0.08); }
    .hero { padding: 24px; margin-bottom: 20px; }
    .hero h1 { margin: 0 0 8px; font-size: clamp(2rem, 4vw, 3rem); }
    .muted { color: var(--muted); }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 18px; }
    .panel { padding: 18px; }
    label { display: block; font-size: 0.95rem; margin-bottom: 6px; color: var(--muted); }
    input[type="text"], select, textarea { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #bcae98; border-radius: 10px; font: inherit; background: #fff; }
    textarea { min-height: 140px; resize: vertical; }
    .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 12px; }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
    button, .button-link { border: 0; border-radius: 10px; padding: 10px 14px; background: var(--accent); color: #fff; font: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
    button.secondary, .button-link.secondary { background: var(--accent-2); }
    button.ghost, .button-link.ghost { background: #5c554c; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #ece3d5; vertical-align: top; }
    th { background: #f7f0e3; font-weight: 600; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .notice { padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; }
    .notice.success { background: #eef7ef; color: #245c2a; border: 1px solid #b8dbbf; }
    .notice.error { background: #fbefef; color: #7a1e1e; border: 1px solid #e1b3b3; }
    .table-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .pill { display: inline-block; padding: 3px 8px; border-radius: 999px; background: #efe5d6; color: #5b4630; font-size: 0.82rem; }
    .pill.stopword { background: #f0cfcf; color: #7a2626; }
    .pill.include { background: #cfe8d5; color: #1f5c2f; }
    .pill.review { background: #f0e5cf; color: #7a6226; }
    pre { background: #2d2823; color: #f8f0e4; padding: 14px; border-radius: 10px; overflow-x: auto; }
    code { background: #efe5d6; padding: 2px 6px; border-radius: 6px; }
    a { color: var(--accent); }

    .entity-list-wrap { border: 1px solid var(--border); border-radius: 10px; display: flex; flex-direction: column; }
    .entity-list { overflow-y: auto; }
    .entity-list table { border: 0; }
    .entity-list th { position: sticky; top: 0; z-index: 2; }
    .entity-list tr:hover { background: #faf6ee; }
    .entity-list .check-col { width: 40px; text-align: center; }
    .entity-list td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }

    .filter-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 12px 12px 0; }
    .filter-bar input { flex: 1; min-width: 160px; }
    .count-badge { display: inline-block; padding: 2px 7px; border-radius: 999px; background: #e2d9ca; color: #4f4030; font-size: 0.78rem; font-weight: 600; }
    .status-filters { display: flex; gap: 6px; flex-wrap: wrap; padding: 0 12px 8px; }
    .status-filters .sfilt { padding: 4px 12px; border-radius: 999px; border: 1px solid var(--border); background: transparent; cursor: pointer; font: inherit; font-size: 0.85rem; color: var(--muted); }
    .status-filters .sfilt:hover { background: #efe5d6; }
    .status-filters .sfilt.active { background: var(--accent); color: #fff; border-color: var(--accent); }
    .status-filters .sfilt .cnt { font-weight: 600; }

    .bulk-bar { position: fixed; bottom: 0; left: 0; right: 0; z-index: 100;
      display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
      padding: 12px 24px; background: #fffdf8; border-top: 2px solid var(--border);
      box-shadow: 0 -4px 20px rgba(72, 51, 23, 0.12); }
    .bulk-bar select { width: auto; min-width: 150px; }
    .bulk-bar input[type="text"] { width: auto; min-width: 120px; flex: 0 1 180px; }
    .bulk-bar-inner { max-width: 1360px; margin: 0 auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; width: 100%; }

    .stat-row { display: flex; gap: 16px; flex-wrap: wrap; }
    .stat-card { background: #f7f3eb; border-radius: 10px; padding: 10px 14px; text-align: center; flex: 1; min-width: 100px; }
    .stat-card .val { font-size: 1.6rem; font-weight: 700; }
    .stat-card .lbl { font-size: 0.82rem; color: var(--muted); }
    #select-all { margin: 0; }

    .scroll-loader { text-align: center; padding: 16px; color: var(--muted); }
    .scroll-loader .spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 0.7s linear infinite; margin-right: 8px; vertical-align: middle; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
<main>
  <section class="hero">
    <h1>Word Cleanup</h1>
    <p class="muted">Select entities from the graph below and classify them as stopwords, inclusions, or review items.</p>
    <p class="muted">Overrides file:
      <form method="get" style="display:inline">
        <select name="file" onchange="this.form.submit()" style="display:inline; width:auto; padding:4px 8px; border-radius:6px; font:inherit;">
          <?php foreach ($availableFiles as $f): ?>
            <option value="<?php echo h($f['filename']); ?>"<?php echo $f['filename'] === $selectedFile ? ' selected' : ''; ?>>
              <?php echo h($f['filename']); ?> (<?php echo date('Y-m-d H:i', $f['modified']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Switch</button></noscript>
      </form>
      <form method="post" style="display:inline; margin-left:8px;">
        <input type="hidden" name="action" value="create_file">
        <input type="text" name="new_filename" placeholder="new filename" style="display:inline; width:180px; padding:4px 8px; border-radius:6px; font:inherit;">
        <button type="submit" class="ghost" style="padding:4px 10px; font-size:0.85rem;">Create</button>
      </form>
    </p>
    <p><a href="/ops/graphs?tab=word-cleanup">Graphs Hub</a> · <a href="/ops/graphs?tab=neo4j-qa">Neo4j QA Tab</a> · <a href="/">Home</a></p>
  </section>

  <?php if ($message !== null): ?>
    <div class="notice success"><?php echo h($message); ?></div>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <div class="notice error"><?php echo h($error); ?></div>
  <?php endif; ?>
  <?php if ($neo4jError !== null): ?>
    <div class="notice error">Neo4j: <?php echo h($neo4jError); ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['created'])): ?>
    <div class="notice success">Created new override file.</div>
  <?php endif; ?>

  <div class="grid">
    <section class="panel" style="grid-column: 1 / -1;">
      <h2>Entities in Graph <span class="muted" style="font-weight:400; font-size:0.9rem;">— <?php echo number_format($entityTotal); ?> total, ordered by mention frequency</span></h2>

      <div class="entity-list-wrap">
        <div class="filter-bar">
          <input type="text" id="entity-filter" placeholder="Filter loaded entities by name…" oninput="filterEntities()" onkeydown="if(event.key==='Enter'){event.preventDefault();searchEntities();}">
          <button type="button" onclick="searchEntities()" style="padding:6px 12px; font-size:0.85rem;">Search graph</button>
          <span id="visible-count" class="count-badge">0 / 0</span>
          <label style="display:flex; align-items:center; gap:4px; font-size:0.9rem; cursor:pointer;">
            <input type="checkbox" id="select-all" onchange="toggleAll()"> Select all visible
          </label>
        </div>
        <div class="status-filters" id="status-filters">
          <button class="sfilt active" data-status="" onclick="setStatusFilter(this, '')">All</button>
          <button class="sfilt" data-status="stopword" onclick="setStatusFilter(this, 'stopword')">Stopwords</button>
          <button class="sfilt" data-status="unclassified" onclick="setStatusFilter(this, 'unclassified')">Unclassified</button>
          <button class="sfilt" data-status="include" onclick="setStatusFilter(this, 'include')">Include</button>
          <button class="sfilt" data-status="review" onclick="setStatusFilter(this, 'review')">Review</button>
          <button class="sfilt" data-status="note" onclick="setStatusFilter(this, 'note')">Note</button>
        </div>

        <form method="post" id="entity-form">
          <input type="hidden" name="action" value="bulk_override">

          <div class="entity-list" id="entity-list">
            <table>
              <thead>
                <tr>
                  <th class="check-col"></th>
                  <th>Name</th>
                  <th>ID</th>
                  <th>Labels</th>
                  <th class="num">Mentions</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="entity-tbody">
                <?php foreach ($initialEntities as $entity):
                    $eid = (string)($entity['id'] ?? '');
                    $ename = (string)($entity['name'] ?? '');
                    $elabels = $entity['labels'] ?? [];
                    if (is_array($elabels)) {
                        $elabels = array_filter($elabels, fn($l) => $l !== 'GraphEntity');
                    }
                    $elabelStr = is_array($elabels) ? implode(', ', $elabels) : (string)$elabels;
                    $mentionCount = (int)($entity['mention_count'] ?? 0);
                    $normalized = WordCleanupStore::normalizeWord($ename);
                    $existingOverride = $stopwordMap[$normalized] ?? null;
                    $statusClass = '';
                    $statusLabel = '—';
                    $dataStatus = 'unclassified';
                    if ($existingOverride !== null) {
                        $act = $existingOverride['action'] ?? '';
                        $statusClass = $act;
                        $statusLabel = $actionLabels[$act] ?? $act;
                        $dataStatus = $act;
                    }
                ?>
                  <tr class="entity-row"
                      data-name="<?php echo h(strtolower($ename)); ?>"
                      data-id="<?php echo h(strtolower($eid)); ?>"
                      data-status="<?php echo h($dataStatus); ?>"
                      data-page="1">
                    <td class="check-col">
                      <input type="checkbox" name="entity_ids[]" value="<?php echo h($ename); ?>" class="entity-checkbox">
                    </td>
                    <td><strong><?php echo h($ename); ?></strong></td>
                    <td style="font-size:0.85rem; color:var(--muted);"><?php echo h($eid); ?></td>
                    <td><span style="font-size:0.85rem;"><?php echo h($elabelStr); ?></span></td>
                    <td class="num"><?php echo h(number_format($mentionCount)); ?></td>
                    <td><?php if ($existingOverride !== null): ?>
                      <span class="pill <?php echo h($statusClass); ?>"><?php echo h($statusLabel); ?></span>
                    <?php else: ?>
                      <span class="pill">unclassified</span>
                    <?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="scroll-loader" id="scroll-loader" style="display:none;">
              <span class="spinner"></span> Loading more…
            </div>
            <div class="scroll-loader" id="scroll-end" style="<?php echo $entityTotal <= 100 ? '' : 'display:none;'; ?>">
              All <?php echo number_format($entityTotal); ?> entities loaded.
            </div>
          </div>
        </form>
      </div>
    </section>
  </div>

  <div class="grid">
    <section class="panel">
      <h2>Summary</h2>
      <div class="stat-row">
        <?php foreach ($summary as $key => $value): ?>
          <div class="stat-card">
            <div class="val"><?php echo h((string)$value); ?></div>
            <div class="lbl"><?php echo h(ucfirst((string)$key)); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="muted" style="margin-top:12px;">Effective Hawaiian word set size after overrides: <strong><?php echo h((string)count($effectiveWordSet)); ?></strong></p>
    </section>

    <section class="panel">
      <h2>Inline Add</h2>
      <form method="post">
        <input type="hidden" name="action" value="save_words">
        <label for="entity_names">Entity names (one per line)</label>
        <textarea id="entity_names" name="entity_names" placeholder="ua&#10;ka&#10;hele ʻōlelo" style="min-height:80px;"></textarea>
        <div class="row">
          <div>
            <label for="cleanup_action_inline">Action</label>
            <select id="cleanup_action_inline" name="cleanup_action">
              <?php foreach ($actionLabels as $value => $label): ?>
                <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="category_inline">Category</label>
            <input type="text" id="category_inline" name="category" placeholder="particle, loanword">
          </div>
        </div>
        <label for="note_inline">Note</label>
        <textarea id="note_inline" name="note" placeholder="Why?" style="min-height:50px;"></textarea>
        <div class="actions">
          <button type="submit">Save</button>
        </div>
      </form>
    </section>
  </div>

  <section class="panel" style="margin-top: 18px;">
    <h2>Current Overrides</h2>
    <?php if (empty($overrides)): ?>
      <p class="muted">No cleanup overrides saved yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Word / Phrase</th>
            <th>Action</th>
            <th>Category</th>
            <th>Note</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($overrides as $entry): ?>
            <tr>
              <td><?php echo h((string)($entry['word'] ?? '')); ?></td>
              <td><span class="pill <?php echo h($entry['action'] ?? ''); ?>"><?php echo h((string)($entry['action'] ?? 'review')); ?></span></td>
              <td><?php echo h((string)($entry['category'] ?? '')); ?></td>
              <td><?php echo h((string)($entry['note'] ?? '')); ?></td>
              <td><?php echo h((string)($entry['updated_at'] ?? '')); ?></td>
              <td>
                <div class="table-actions">
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="remove_word">
                    <input type="hidden" name="word" value="<?php echo h((string)($entry['word'] ?? '')); ?>">
                    <button type="submit" class="ghost">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</main>

<div class="bulk-bar" id="bulk-bar">
  <div class="bulk-bar-inner">
    <select id="bulk-action" form="entity-form" name="cleanup_action">
      <option value="stopword">Mark as Stopword</option>
      <option value="include">Include in Hawaiian set</option>
      <option value="review">Review only</option>
      <option value="note">Note only</option>
      <option value="clear">Clear override</option>
    </select>
    <input type="text" form="entity-form" name="category" placeholder="Category">
    <input type="text" form="entity-form" name="note" placeholder="Note">
    <button type="submit" form="entity-form" id="apply-btn" onclick="return confirmApply()">Apply to selected</button>
    <span id="selected-count" class="muted" style="font-size:0.9rem;">0 selected</span>
  </div>
</div>

<script>
var currentPage = 1;
var isLoading = false;
var hasMore = true;
var totalEntities = <?php echo $entityTotal; ?>;
var perPage = 100;
var activeStatusFilter = '';

function setStatusFilter(btn, status) {
    document.querySelectorAll('#status-filters .sfilt').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    activeStatusFilter = status;
    filterEntities();
}

function filterEntities() {
    var q = document.getElementById('entity-filter').value.toLowerCase();
    var rows = document.querySelectorAll('.entity-row');
    var visible = 0;
    rows.forEach(function(row) {
        var statusOk = activeStatusFilter === '' || row.getAttribute('data-status') === activeStatusFilter;
        var name = row.getAttribute('data-name') || '';
        var id = row.getAttribute('data-id') || '';
        var textOk = name.indexOf(q) !== -1;
        var show = statusOk && textOk;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('visible-count').textContent = visible + ' / ' + rows.length;
    updateSelectedCount();
}

function toggleAll() {
    var checked = document.getElementById('select-all').checked;
    var rows = document.querySelectorAll('.entity-row');
    rows.forEach(function(row) {
        if (row.style.display !== 'none') {
            row.querySelector('.entity-checkbox').checked = checked;
        }
    });
    updateSelectedCount();
}

function searchEntities() {
    var q = document.getElementById('entity-filter').value.trim();
    if (q === '') { location.href = location.pathname; return; }

    var url = '<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>?format=json&search=' + encodeURIComponent(q);
    var loader = document.getElementById('scroll-loader');
    loader.style.display = 'block';

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loader.style.display = 'none';
            var tbody = document.getElementById('entity-tbody');
            tbody.innerHTML = '';

            if (!data.entities || data.entities.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted);">No entities match "' + escHtml(q) + '"</td></tr>';
                document.getElementById('scroll-end').style.display = 'none';
                document.getElementById('visible-count').textContent = '0 / 0';
                return;
            }

            data.entities.forEach(function(ent) {
                var labels = (ent.labels || []).filter(function(l) { return l !== 'GraphEntity'; }).join(', ');
                var statusVal = 'unclassified';
                var statusHtml = '<span class="pill">unclassified</span>';
                if (ent.override_status) {
                    statusVal = ent.override_status;
                    statusHtml = '<span class="pill ' + ent.override_status + '">' + escHtml(ent.override_label || ent.override_status) + '</span>';
                }
                var tr = document.createElement('tr');
                tr.className = 'entity-row';
                tr.setAttribute('data-name', (ent.name || '').toLowerCase());
                tr.setAttribute('data-id', (ent.id || '').toLowerCase());
                tr.setAttribute('data-status', statusVal);
                tr.innerHTML =
                    '<td class="check-col"><input type="checkbox" name="entity_ids[]" value="' + escAttr(ent.name) + '" class="entity-checkbox"></td>' +
                    '<td><strong>' + escHtml(ent.name) + '</strong></td>' +
                    '<td style="font-size:0.85rem;color:var(--muted);">' + escHtml(ent.id) + '</td>' +
                    '<td><span style="font-size:0.85rem;">' + escHtml(labels) + '</span></td>' +
                    '<td class="num">' + escHtml(numberFmt(ent.mention_count)) + '</td>' +
                    '<td>' + statusHtml + '</td>';
                tbody.appendChild(tr);
                tr.querySelector('.entity-checkbox').addEventListener('change', updateSelectedCount);
            });

            document.getElementById('scroll-end').style.display = 'none';
            hasMore = false;
            currentPage = 1;
            filterEntities();
        })
        .catch(function(err) {
            console.error('Search failed:', err);
            loader.style.display = 'none';
        });
}

function updateSelectedCount() {
    var checked = document.querySelectorAll('.entity-checkbox:checked').length;
    document.getElementById('selected-count').textContent = checked + ' selected';
}

function confirmApply() {
    var checked = document.querySelectorAll('.entity-checkbox:checked');
    if (checked.length === 0) {
        alert('Select at least one entity.');
        return false;
    }
    var sel = document.getElementById('bulk-action');
    var label = sel.options[sel.selectedIndex].text;
    return confirm('Apply "' + label + '" to ' + checked.length + ' selected entit' + (checked.length === 1 ? 'y' : 'ies') + '?');
}

function loadMoreEntities() {
    if (isLoading || !hasMore) return;
    isLoading = true;
    document.getElementById('scroll-loader').style.display = 'block';

    var nextPage = currentPage + 1;
    var url = '<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>?format=json&page=' + nextPage + '&per_page=' + perPage;

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.entities || data.entities.length === 0) {
                hasMore = false;
                document.getElementById('scroll-loader').style.display = 'none';
                document.getElementById('scroll-end').style.display = 'block';
                isLoading = false;
                return;
            }

            var tbody = document.getElementById('entity-tbody');
            data.entities.forEach(function(ent) {
                var labels = (ent.labels || []).filter(function(l) { return l !== 'GraphEntity'; }).join(', ');
                var statusVal = 'unclassified';
                var statusHtml = '<span class="pill">unclassified</span>';
                if (ent.override_status) {
                    statusVal = ent.override_status;
                    var cls = ent.override_status;
                    statusHtml = '<span class="pill ' + cls + '">' + escHtml(ent.override_label || ent.override_status) + '</span>';
                }

                var tr = document.createElement('tr');
                tr.className = 'entity-row';
                tr.setAttribute('data-name', (ent.name || '').toLowerCase());
                tr.setAttribute('data-id', (ent.id || '').toLowerCase());
                tr.setAttribute('data-status', statusVal);
                tr.setAttribute('data-page', String(nextPage));
                tr.innerHTML =
                    '<td class="check-col"><input type="checkbox" name="entity_ids[]" value="' + escAttr(ent.name) + '" class="entity-checkbox"></td>' +
                    '<td><strong>' + escHtml(ent.name) + '</strong></td>' +
                    '<td style="font-size:0.85rem;color:var(--muted);">' + escHtml(ent.id) + '</td>' +
                    '<td><span style="font-size:0.85rem;">' + escHtml(labels) + '</span></td>' +
                    '<td class="num">' + escHtml(numberFmt(ent.mention_count)) + '</td>' +
                    '<td>' + statusHtml + '</td>';
                tbody.appendChild(tr);

                tr.querySelector('.entity-checkbox').addEventListener('change', updateSelectedCount);
            });

            currentPage = nextPage;
            hasMore = data.has_more;
            isLoading = false;
            document.getElementById('scroll-loader').style.display = 'none';

            if (!hasMore) {
                document.getElementById('scroll-end').style.display = 'block';
            }

            var prevTotal = document.querySelectorAll('.entity-row').length - data.entities.length;
            document.getElementById('visible-count').textContent = document.querySelectorAll('.entity-row:not([style*="display: none"])').length + ' / ' + document.querySelectorAll('.entity-row').length;
        })
        .catch(function(err) {
            console.error('Failed to load entities:', err);
            isLoading = false;
            document.getElementById('scroll-loader').style.display = 'none';
        });
}

function escHtml(s) {
    if (s == null) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escAttr(s) {
    return escHtml(s);
}

function numberFmt(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// Infinite scroll on the entity list container
(function() {
    var list = document.getElementById('entity-list');
    list.addEventListener('scroll', function() {
        if (list.scrollTop + list.clientHeight >= list.scrollHeight - 200) {
            loadMoreEntities();
        }
    });

    // Update visible count initially
    document.querySelectorAll('.entity-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateSelectedCount);
    });
    filterEntities();
})();
</script>
</body>
</html>
