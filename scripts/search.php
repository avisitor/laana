#!/usr/bin/php
<?php
/**
 * Noiʻiʻōlelo Command-Line Search Tool
 * 
 * A comprehensive CLI query tool that works with all three search providers
 * (Elasticsearch, Laana/MySQL, Postgres) and supports both sentence and document searches.
 */

require_once __DIR__ . '/../lib/provider.php';
require_once __DIR__ . '/../env-loader.php';

// ANSI color codes for output
define('COLOR_RESET', "\033[0m");
define('COLOR_BOLD', "\033[1m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_CYAN', "\033[36m");
define('COLOR_GRAY', "\033[90m");

class SearchCLI {
    private $provider;
    private $providerName;
    private $showColors = true;
    
    public function __construct(string $providerName) {
        $this->providerName = $providerName;
        $this->provider = getProvider($providerName);
        $this->showColors = function_exists('posix_isatty') && posix_isatty(STDOUT);
    }
    
    private function color(string $text, string $color): string {
        return $this->showColors ? ($color . $text . COLOR_RESET) : $text;
    }
    
    public function run(array $args): int {
        $config = $this->parseArgs($args);
        
        if ($config['help']) {
            $this->showHelp();
            return 0;
        }
        
        if ($config['modes']) {
            $this->showModes();
            return 0;
        }
        
        if (empty($config['query'])) {
            echo "Error: Query term required (use --query=TERM)\n";
            $this->showUsage();
            return 1;
        }
        
        return $this->executeSearch($config);
    }
    
    private function parseArgs(array $args): array {
        $config = [
            'query' => '',
            'mode' => null,
            'type' => 'sentences',  // sentences or documents
            'order' => null,  // auto-detect based on mode
            'limit' => 10,
            'offset' => 0,
            'metrics' => false,
            'help' => false,
            'modes' => false,
            'provider' => null,  // Will trigger provider change if set
            'snippet-length' => 200,
            'no-colors' => false,
        ];
        
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $config['help'] = true;
            } elseif ($arg === '--modes' || $arg === '--list-modes') {
                $config['modes'] = true;
            } elseif ($arg === '--metrics') {
                $config['metrics'] = true;
            } elseif ($arg === '--documents' || $arg === '--docs') {
                $config['type'] = 'documents';
            } elseif ($arg === '--sentences') {
                $config['type'] = 'sentences';
            } elseif ($arg === '--no-colors') {
                $config['no-colors'] = true;
                $this->showColors = false;
            } elseif (preg_match('/^--query=(.+)$/', $arg, $m)) {
                $config['query'] = $m[1];
            } elseif (preg_match('/^--mode=(.+)$/', $arg, $m)) {
                $config['mode'] = $m[1];
            } elseif (preg_match('/^--provider=(.+)$/', $arg, $m)) {
                $config['provider'] = $m[1];
            } elseif (preg_match('/^--order=(.+)$/', $arg, $m)) {
                $config['order'] = $m[1];
            } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
                $config['limit'] = (int)$m[1];
            } elseif (preg_match('/^--offset=(\d+)$/', $arg, $m)) {
                $config['offset'] = (int)$m[1];
            } elseif (preg_match('/^--snippet-length=(\d+)$/', $arg, $m)) {
                $config['snippet-length'] = (int)$m[1];
            }
        }
        
        // Handle provider change if requested
        if ($config['provider'] !== null && $config['provider'] !== $this->providerName) {
            if (!isValidProvider($config['provider'])) {
                throw new \Exception("Invalid provider '{$config['provider']}'. Valid: " . implode(', ', array_keys(getKnownProviders())));
            }
            $this->providerName = $config['provider'];
            $this->provider = getProvider($config['provider']);
        }
        $config['provider'] = $this->providerName;
        
        // Auto-detect default mode if not specified
        if ($config['mode'] === null) {
            $modes = $this->provider->getAvailableSearchModes();
            $modeKeys = array_keys($modes);
            // Prefer 'exact' for Laana/Postgres, 'match' for Elasticsearch
            if (in_array('exact', $modeKeys)) {
                $config['mode'] = 'exact';
            } elseif (in_array('match', $modeKeys)) {
                $config['mode'] = 'match';
            } else {
                $config['mode'] = $modeKeys[0] ?? 'any';
            }
        }
        
        // Auto-detect default order
        if ($config['order'] === null) {
            if (in_array($config['mode'], ['hybrid', 'hybriddoc', 'vector'])) {
                $config['order'] = 'relevance';
            } else {
                // Don't use random ordering - it forces scanning all matches
                // Just use natural index order for better performance
                $config['order'] = null;
            }
        }
        
        return $config;
    }
    
    private function executeSearch(array $config): int {
        $startTime = microtime(true);
        
        echo $this->color("Provider: ", COLOR_BOLD) . $this->providerName . "\n";
        echo $this->color("Query: ", COLOR_BOLD) . $config['query'] . "\n";
        echo $this->color("Mode: ", COLOR_BOLD) . $config['mode'] . "\n";
        echo $this->color("Type: ", COLOR_BOLD) . $config['type'] . "\n";
        echo $this->color("Order: ", COLOR_BOLD) . $config['order'] . "\n";
        echo str_repeat("─", 70) . "\n\n";
        
        try {
            if ($config['type'] === 'documents') {
                $results = $this->searchDocuments($config);
            } else {
                $results = $this->searchSentences($config);
            }
            
            $elapsed = microtime(true) - $startTime;
            
            if (empty($results)) {
                echo $this->color("No results found.\n", COLOR_YELLOW);
                return 0;
            }
            
            $this->displayResults($results, $config);
            
            echo "\n" . str_repeat("─", 70) . "\n";
            echo $this->color(sprintf("Found %d results in %.3fs\n", count($results), $elapsed), COLOR_GRAY);
            
            return 0;
            
        } catch (Exception $e) {
            echo $this->color("Error: " . $e->getMessage() . "\n", COLOR_YELLOW);
            return 1;
        }
    }
    
    private function searchSentences(array $config): array {
        $options = [
            'orderby' => $config['order'],
            'limit' => $config['limit']  // Pass actual limit for query optimization
        ];
        
        // Use page number 0 to get results (Laana uses 0-based pages)
        // Or convert offset to page number if offset is specified
        $pageNumber = ($config['offset'] > 0) ? floor($config['offset'] / $this->provider->pageSize) : 0;
        
        $results = $this->provider->getSentences(
            $config['query'],
            $config['mode'],
            $pageNumber,
            $options
        );
        
        // Limit results
        return array_slice($results, 0, $config['limit']);
    }
    
    private function searchDocuments(array $config): array {
        // Try to use document search if available
        if (method_exists($this->provider, 'searchDocuments')) {
            return $this->provider->searchDocuments(
                $config['query'],
                $config['mode'],
                $config['limit'],
                $config['offset']
            );
        }
        
        // Fallback: get sentences grouped by source
        $options = ['orderby' => $config['order']];
        $pageNumber = 1;
        $allResults = $this->provider->getSentences(
            $config['query'],
            $config['mode'],
            $pageNumber,
            $options
        );
        
        // Group by source and create document results
        $docs = [];
        $seen = [];
        foreach ($allResults as $sentence) {
            $sourceId = $sentence['sourceid'] ?? null;
            if (!$sourceId || isset($seen[$sourceId])) continue;
            $seen[$sourceId] = true;
            
            $docs[] = [
                'sourceid' => $sourceId,
                'sourcename' => $sentence['sourcename'] ?? '',
                'groupname' => $sentence['groupname'] ?? '',
                'date' => $sentence['date'] ?? '',
                'authors' => $sentence['authors'] ?? '',
                'text' => $sentence['hawaiiantext'] ?? '',
                'match_type' => 'sentence',
            ];
            
            if (count($docs) >= $config['limit']) break;
        }
        
        return $docs;
    }
    
    private function displayResults(array $results, array $config): void {
        $resultNum = $config['offset'] + 1;
        
        foreach ($results as $result) {
            if ($config['type'] === 'documents') {
                $this->displayDocumentResult($result, $resultNum, $config);
            } else {
                $this->displaySentenceResult($result, $resultNum, $config);
            }
            $resultNum++;
            echo "\n";
        }
    }
    
    private function displaySentenceResult(array $result, int $num, array $config): void {
        echo $this->color("[$num] ", COLOR_BOLD);
        
        // Sentence text
        $text = $result['hawaiiantext'] ?? $result['text'] ?? '';
        echo $this->color($text, COLOR_CYAN) . "\n";
        
        // Metadata
        $meta = [];
        if (!empty($result['sentenceid'])) {
            $meta[] = $this->color("SID:", COLOR_GRAY) . " " . $result['sentenceid'];
        }
        if (!empty($result['sourceid'])) {
            $meta[] = $this->color("Source:", COLOR_GRAY) . " " . $result['sourceid'];
        }
        if (!empty($result['sourcename'])) {
            $meta[] = $this->color("Name:", COLOR_GRAY) . " " . $result['sourcename'];
        }
        if (!empty($result['date'])) {
            $meta[] = $this->color("Date:", COLOR_GRAY) . " " . $result['date'];
        }
        if (!empty($result['groupname'])) {
            $meta[] = $this->color("Group:", COLOR_GRAY) . " " . $result['groupname'];
        }
        
        if (!empty($meta)) {
            echo "  " . implode(" | ", $meta) . "\n";
        }
        
        // Metrics if requested
        if ($config['metrics']) {
            $this->displayMetrics($result);
        }
    }
    
    private function displayDocumentResult(array $result, int $num, array $config): void {
        echo $this->color("[$num] ", COLOR_BOLD);
        
        // Document title/name
        $title = $result['sourcename'] ?? $result['title'] ?? 'Untitled';
        echo $this->color($title, COLOR_GREEN) . "\n";
        
        // Metadata
        $meta = [];
        if (!empty($result['sourceid'])) {
            $meta[] = $this->color("ID:", COLOR_GRAY) . " " . $result['sourceid'];
        }
        if (!empty($result['authors'])) {
            $meta[] = $this->color("Authors:", COLOR_GRAY) . " " . $result['authors'];
        }
        if (!empty($result['date'])) {
            $meta[] = $this->color("Date:", COLOR_GRAY) . " " . $result['date'];
        }
        if (!empty($result['groupname'])) {
            $meta[] = $this->color("Group:", COLOR_GRAY) . " " . $result['groupname'];
        }
        
        if (!empty($meta)) {
            echo "  " . implode(" | ", $meta) . "\n";
        }
        
        // Snippet - prefer pre-formatted snippet, fall back to extracting from text
        if (!empty($result['snippet'])) {
            echo "  " . $this->color($result['snippet'], COLOR_BLUE) . "\n";
        } elseif (!empty($result['text'])) {
            $snippet = $this->extractSnippet($result['text'], $config['query'], $config['snippet-length']);
            echo "  " . $this->color($snippet, COLOR_BLUE) . "\n";
        }
        
        // Metrics if requested
        if ($config['metrics']) {
            $this->displayMetrics($result);
        }
    }
    
    private function extractSnippet(string $text, string $query, int $maxLength): string {
        // Find query position
        $pos = mb_stripos($text, $query);
        
        if ($pos === false) {
            // Query not found, return beginning
            $snippet = mb_substr($text, 0, $maxLength);
        } else {
            // Center snippet around query
            $start = max(0, $pos - (int)($maxLength / 2));
            $snippet = mb_substr($text, $start, $maxLength);
            
            if ($start > 0) {
                $snippet = '...' . $snippet;
            }
        }
        
        if (mb_strlen($text) > mb_strlen($snippet)) {
            $snippet .= '...';
        }
        
        return $snippet;
    }
    
    private function displayMetrics(array $result): void {
        $metrics = [];
        
        if (isset($result['hawaiian_word_ratio'])) {
            $metrics[] = sprintf("Hawaiian ratio: %.2f", $result['hawaiian_word_ratio']);
        }
        if (isset($result['word_count'])) {
            $metrics[] = sprintf("Words: %d", $result['word_count']);
        }
        if (isset($result['length'])) {
            $metrics[] = sprintf("Length: %d", $result['length']);
        }
        if (isset($result['entity_count'])) {
            $metrics[] = sprintf("Entities: %d", $result['entity_count']);
        }
        if (isset($result['score'])) {
            $metrics[] = sprintf("Score: %.3f", $result['score']);
        }
        
        if (!empty($metrics)) {
            echo "  " . $this->color("[Metrics] ", COLOR_GRAY) . implode(" | ", $metrics) . "\n";
        }
    }
    
    private function showModes(): void {
        $modes = $this->provider->getAvailableSearchModes();
        
        echo $this->color("Available search modes for {$this->providerName}:\n\n", COLOR_BOLD);
        
        foreach ($modes as $mode => $description) {
            echo $this->color(sprintf("  %-15s", $mode), COLOR_GREEN);
            echo " - " . $description . "\n";
        }
    }
    
    private function showUsage(): void {
        echo "\nUsage: php search.php --query=TERM [OPTIONS]\n";
        echo "       php search.php --modes\n";
        echo "       php search.php --help\n\n";
    }
    
    private function showHelp(): void {
        echo $this->color("Noiʻiʻōlelo Command-Line Search Tool\n", COLOR_BOLD);
        echo str_repeat("=", 70) . "\n\n";
        
        echo "A comprehensive CLI query tool that works with all three search providers.\n\n";
        
        $this->showUsage();
        
        echo "Options:\n";
        echo "  " . $this->color("--query=TERM", COLOR_GREEN) . "          Search query term (required)\n";
        echo "  " . $this->color("--mode=MODE", COLOR_GREEN) . "           Search mode (default: auto-detect)\n";
        echo "  " . $this->color("--sentences", COLOR_GREEN) . "           Search sentences (default)\n";
        echo "  " . $this->color("--documents", COLOR_GREEN) . "           Search documents\n";
        echo "  " . $this->color("--order=ORDER", COLOR_GREEN) . "         Sort order: random|relevance|date (default: auto)\n";
        echo "  " . $this->color("--limit=N", COLOR_GREEN) . "             Number of results (default: 10)\n";
        echo "  " . $this->color("--offset=N", COLOR_GREEN) . "            Starting offset (default: 0)\n";
        echo "  " . $this->color("--metrics", COLOR_GREEN) . "             Show quality metrics for results\n";
        echo "  " . $this->color("--snippet-length=N", COLOR_GREEN) . "    Snippet length for documents (default: 200)\n";
        echo "  " . $this->color("--no-colors", COLOR_GREEN) . "           Disable colored output\n";
        echo "  " . $this->color("--modes", COLOR_GREEN) . "               List available search modes\n";
        echo "  " . $this->color("--help", COLOR_GREEN) . "                Show this help message\n\n";
        
        echo "Environment:\n";
        echo "  Set PROVIDER in .env file to choose default provider:\n";
        echo "    PROVIDER=Elasticsearch  (default)\n";
        echo "    PROVIDER=Laana          (MySQL backend)\n";
        echo "    PROVIDER=Postgres       (PostgreSQL backend)\n\n";
        
        echo "Examples:\n";
        echo "  # Basic sentence search\n";
        echo "  php search.php --query='aloha'\n\n";
        echo "  # Document search with specific mode\n";
        echo "  php search.php --query='Hawaii' --documents --mode=phrase\n\n";
        echo "  # Search with metrics and custom limit\n";
        echo "  php search.php --query='mahalo' --metrics --limit=5\n\n";
        echo "  # Regex search\n";
        echo "  php search.php --query='h[ao]le' --mode=regex\n\n";
        echo "  # List available modes for current provider\n";
        echo "  php search.php --modes\n\n";
    }
}

// Main execution
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

try {
    // Load environment
    $env = loadEnv(__DIR__ . '/../.env');
    $providerName = $env['PROVIDER'] ?? 'Elasticsearch';
    
    // Allow provider override via environment variable
    if (isset($_SERVER['PROVIDER'])) {
        $providerName = $_SERVER['PROVIDER'];
    }
    
    // Check for --provider in args first (highest priority)
    foreach ($argv as $arg) {
        if (preg_match('/^--provider=(.+)$/', $arg, $m)) {
            $providerName = $m[1];
            break;
        }
    }
    
    // Validate provider
    if (!isValidProvider($providerName)) {
        echo "Error: Invalid provider '$providerName'\n";
        echo "Valid providers: " . implode(', ', array_keys(getKnownProviders())) . "\n";
        exit(1);
    }
    
    $cli = new SearchCLI($providerName);
    $exitCode = $cli->run(array_slice($argv, 1));
    exit($exitCode);
    
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}

