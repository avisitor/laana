<?php
namespace Noiiolelo\Providers\Postgres;

use Noiiolelo\SearchProviderInterface;
use Noiiolelo\Providers\MySQL\MySQLProvider;
use HawaiianSearch\EmbeddingClient;

class PostgresProvider extends MySQLProvider implements SearchProviderInterface
{
    private $embeddingClient;
    
    public function __construct($options) {
        // Replace underlying Laana with PostgresLaana
        $this->laana = new \PostgresLaana();
        $this->pageSize = $this->laana->pageSize;
        $this->embeddingClient = new EmbeddingClient();
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
        $opts = ['limit' => $limit];
        
        if ($pattern === 'hybrid') {
            $embedding = $this->embeddingClient->embedText($query, 'query: ');
            if (!$embedding) {
                throw new \RuntimeException('Failed to get embedding for hybrid search');
            }
            $parts = array_map(function($x){ return sprintf('%.6f', (float)$x); }, $embedding);
            $opts['query_vec'] = '[' . implode(',', $parts) . ']';
        }
        
        // Map limit/offset to pageNumber using provider page size
        $pageNumber = ($this->pageSize > 0) ? intdiv($offset, $this->pageSize) : 0;
        return $this->laana->getSentences($query, $pattern, $pageNumber, $opts);
    }
    
    public function searchDocuments(string $query, string $mode, int $limit, int $offset): array {
        $pattern = strtolower($mode);
        $opts = ['limit' => $limit];
        
        if ($pattern === 'hybrid') {
            $embedding = $this->embeddingClient->embedText($query, 'query: ');
            if (!$embedding) {
                throw new \RuntimeException('Failed to get embedding for hybrid search');
            }
            $parts = array_map(function($x){ return sprintf('%.6f', (float)$x); }, $embedding);
            $opts['query_vec'] = '[' . implode(',', $parts) . ']';
        }
        
        $pageNumber = floor($offset / $limit);
        
        // Use getDocuments method if available
        if (method_exists($this->laana, 'getDocuments')) {
            return $this->laana->getDocuments($query, $pattern, $pageNumber, $opts);
        }
        
        // Fallback to parent implementation
        return parent::searchDocuments($query, $mode, $limit, $offset);
    }
}

?>
