<?php
declare(strict_types=1);

require_once __DIR__ . '/../env-loader.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use HawaiianSearch\HawaiianWordLoader;
use HawaiianSearch\WordCleanupStore;

use Authorization\AuthorizationClient;

$userEmail = (string)($_SESSION['auth_email'] ?? $_SESSION['email'] ?? $_SESSION['username'] ?? '');

$auth = new AuthorizationClient();
$auth->checkAuth($userEmail);

$overrideFile = WordCleanupStore::getOverridesFilePath();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        $words = parseWords((string)($_POST['words'] ?? ''));
        $cleanupAction = trim((string)($_POST['cleanup_action'] ?? 'review'));
        $category = trim((string)($_POST['category'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($action === 'save_words') {
            if (empty($words)) {
                throw new \RuntimeException('Enter at least one word or phrase.');
            }

            if ($cleanupAction === 'clear') {
                WordCleanupStore::removeEntries($words, $overrideFile);
                $message = 'Removed ' . count($words) . ' override(s).';
            } else {
                WordCleanupStore::upsertEntries($words, $cleanupAction, $category, $note, $overrideFile);
                $message = 'Saved ' . count($words) . ' override(s).';
            }
        } elseif ($action === 'remove_word') {
            WordCleanupStore::removeEntries($words, $overrideFile);
            $message = 'Removed the selected override.';
        }

        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?saved=1');
        exit;
    } catch (\Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

if (isset($_GET['saved'])) {
    $message = $message ?? 'Cleanup overrides updated.';
}

$overrides = WordCleanupStore::loadOverrides($overrideFile);
$summary = WordCleanupStore::getSummary($overrideFile);
$effectiveWordSet = [];

try {
    $effectiveWordSet = HawaiianWordLoader::loadAsHashSet(__DIR__ . '/../hawaiian_words.txt');
} catch (\Throwable $throwable) {
    $effectiveWordSet = [];
}

$actionLabels = [
    'stopword' => 'Stopword',
    'include' => 'Include in Hawaiian set',
    'review' => 'Review only',
    'note' => 'Note only',
    'clear' => 'Clear override',
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Word Cleanup</title>
  <style>
    :root { --bg: #f5efe4; --panel: #fffdf8; --border: #d8ccb8; --ink: #1f1b16; --muted: #6d6254; --accent: #7f4f24; --accent-2: #2f5d62; }
    body { margin: 0; font-family: Georgia, serif; background: linear-gradient(180deg, #f7f1e6, #efe7db 65%, #f7f1e6); color: var(--ink); }
    main { max-width: 1360px; margin: 0 auto; padding: 24px; }
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
    pre { background: #2d2823; color: #f8f0e4; padding: 14px; border-radius: 10px; overflow-x: auto; }
    code { background: #efe5d6; padding: 2px 6px; border-radius: 6px; }
    a { color: var(--accent); }
  </style>
</head>
<body>
<main>
  <section class="hero">
    <h1>Word Cleanup</h1>
    <p class="muted">Mark stopwords, force words back into the Hawaiian set, or attach cleanup notes while keeping the base dictionary file intact.</p>
    <p class="muted">Overrides file: <code><?php echo h($overrideFile); ?></code></p>
    <p><a href="/ops/graphs?tab=word-cleanup">Graphs Hub</a> · <a href="/ops/graphs?tab=neo4j-qa">Neo4j QA Tab</a> · <a href="/">Home</a></p>
  </section>

  <?php if ($message !== null): ?>
    <div class="notice success"><?php echo h($message); ?></div>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <div class="notice error"><?php echo h($error); ?></div>
  <?php endif; ?>

  <div class="grid">
    <section class="panel">
      <h2>Bulk Update</h2>
      <form method="post">
        <input type="hidden" name="action" value="save_words">
        <label for="words">Words or phrases, one per line. Commas on the same line are treated as alternatives.</label>
        <textarea id="words" name="words" placeholder="ua&#10;ka&#10;hele ʻōlelo"></textarea>
        <div class="row">
          <div>
            <label for="cleanup_action">Cleanup action</label>
            <select id="cleanup_action" name="cleanup_action">
              <?php foreach ($actionLabels as $value => $label): ?>
                <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="category">Category / note label</label>
            <input type="text" id="category" name="category" placeholder="particle, loanword, proper noun">
          </div>
        </div>
        <label for="note">Note</label>
        <textarea id="note" name="note" placeholder="Why is this word being changed?"></textarea>
        <div class="actions">
          <button type="submit">Save cleanup entries</button>
          <a class="button-link secondary" href="/ops/word_cleanup.php">Refresh</a>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Summary</h2>
      <table>
        <tbody>
          <?php foreach ($summary as $key => $value): ?>
            <tr>
              <td><?php echo h(ucfirst((string)$key)); ?></td>
              <td class="num"><?php echo h((string)$value); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted">Effective Hawaiian word set size after overrides: <strong><?php echo h((string)count($effectiveWordSet)); ?></strong></p>
      <p class="muted">Re-submit a word with a new action to recategorize it; use <span class="pill">stopword</span> to exclude it or <span class="pill">include</span> to force it back in.</p>
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
              <td><span class="pill"><?php echo h((string)($entry['action'] ?? 'review')); ?></span></td>
              <td><?php echo h((string)($entry['category'] ?? '')); ?></td>
              <td><?php echo h((string)($entry['note'] ?? '')); ?></td>
              <td><?php echo h((string)($entry['updated_at'] ?? '')); ?></td>
              <td>
                <div class="table-actions">
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="remove_word">
                    <input type="hidden" name="words" value="<?php echo h((string)($entry['word'] ?? '')); ?>">
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
</body>
</html>
