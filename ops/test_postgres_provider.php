<?php
// Tiny harness to call Postgres provider getSentences with CLI args
// Usage:
//   env XDEBUG_MODE=off php -d xdebug.mode=off test_postgres_provider.php --word "aloha aina" --mode any --limit 10 --page 0

require_once __DIR__ . '/../lib/provider.php';

function parse_args(): array {
    $longopts = [
        'word:',
        'mode:',
        'limit:',
        'page:',
        'orderby:',
        'nodiacriticals',
        'verbose',
    ];
    $args = getopt('', $longopts);
    return [
        'word' => $args['word'] ?? 'aloha aina',
        'mode' => $args['mode'] ?? 'any',
        'limit' => isset($args['limit']) ? (string)$args['limit'] : '10',
        'page' => isset($args['page']) ? (int)$args['page'] : 0,
        'orderby' => $args['orderby'] ?? 'sourcename,hawaiianText',
        'nodiacriticals' => isset($args['nodiacriticals']),
        'verbose' => isset($args['verbose']),
    ];
}

function main() {
    $p = parse_args();
    $provider = getProvider('Postgres');

    $options = [
        'nodiacriticals' => $p['nodiacriticals'],
        'orderby' => $p['orderby'],
        'from' => '',
        'to' => '',
        'limit' => $p['limit'],
        'sentence_highlight' => true,
    ];

    if ($p['verbose']) {
        echo "Calling Postgres getSentences(word='{$p['word']}', mode='{$p['mode']}', page={$p['page']}, opts=" . json_encode($options) . ")\n";
    }

    try {
        $rows = $provider->getSentences($p['word'], $p['mode'], $p['page'], $options);
        if ($p['verbose']) {
            echo "Returned " . count($rows) . " rows\n";
        }
        foreach ($rows as $row) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
        if (empty($rows)) {
            echo "-- No rows returned --\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    }
}

main();
?>
