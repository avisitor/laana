<?php
namespace Noiiolelo;

require_once __DIR__ . '/../db/PostgresFuncs.php';
require_once __DIR__ . '/SearchProviderInterface.php';
require_once __DIR__ . '/LaanaSearchProvider.php';

class PostgresSearchProvider extends LaanaSearchProvider implements SearchProviderInterface
{
    public function __construct($options) {
        // Replace underlying Laana with PostgresLaana
        $this->laana = new \PostgresLaana();
        $this->pageSize = $this->laana->pageSize;
    }

    public function getName(): string {
        return 'Postgres';
    }

    // Explicitly declare available modes to ensure UI population
    public function getAvailableSearchModes(): array
    {
        return [
            'exact' => 'Match exact phrase',
            'any' => 'Match any of the words',
            'all' => 'Match all words in any order',
            'near' => 'Words adjacent in order',
            'regex' => 'Regular expression search',
            'hybrid' => 'Hybrid: keyword + vector + quality',
        ];
    }

    public function search(string $query, string $mode, int $limit, int $offset): array {
        $pattern = strtolower($mode);
        if ($pattern === 'hybrid') {
            $url = getenv('EMBED_SERVICE_URL');
            if (!$url) {
                throw new \RuntimeException('EMBED_SERVICE_URL not configured for hybrid search');
            }
            $payload = json_encode(['inputs' => [$query], 'normalize' => true]);
            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 20
                ]
            ];
            $ctx = stream_context_create($opts);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                throw new \RuntimeException('Failed to contact embedding service');
            }
            $data = json_decode($resp, true);
            $embedding = null;
            if (isset($data[0]) && is_array($data[0])) $embedding = $data[0];
            if (!$embedding && isset($data['embeddings'][0])) $embedding = $data['embeddings'][0];
            if (!$embedding) {
                throw new \RuntimeException('No embedding returned for hybrid search');
            }
            $parts = array_map(function($x){ return sprintf('%.6f', (float)$x); }, $embedding);
            $opts = ['query_vec' => '[' . implode(',', $parts) . ']'];
        } else {
            $opts = [];
        }
        // Map limit/offset to pageNumber using provider page size
        $pageNumber = ($this->pageSize > 0) ? intdiv($offset, $this->pageSize) : 0;
        return $this->laana->getSentences($query, $pattern, $pageNumber, $opts);
    }
}

?>
