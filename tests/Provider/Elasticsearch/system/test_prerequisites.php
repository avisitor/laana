<?php

require_once __DIR__ . '/../BaseTest.php';

/**
 * Pre-flight checks to ensure test environment is properly configured
 * This test should run first before any other tests
 */
class TestPrerequisites extends BaseTest
{
    private $errors = [];
    private $warnings = [];
    
    protected function execute()
    {
        // Load .env file first if it exists
        $this->loadEnvironmentFile();
        
        $this->checkEnvironmentFile();
        $this->checkElasticsearchConnection();
        $this->checkEmbeddingService();
        
        // Report all issues
        if (!empty($this->errors)) {
            echo "\n[CRITICAL ERRORS] - Cannot run tests:\n";
            foreach ($this->errors as $error) {
                echo "  * $error\n";
            }
            echo "\nPlease fix these issues before running tests.\n";
            exit(1); // Exit immediately with error code
        }
        
        if (!empty($this->warnings)) {
            echo "\n[WARNINGS]:\n";
            foreach ($this->warnings as $warning) {
                echo "  * $warning\n";
            }
            echo "\n";
        }
        
        echo "[OK] All prerequisites met - ready to run tests\n\n";
    }
    
    private function loadEnvironmentFile()
    {
        $envFile = TEST_BASE_PATH . '/.env';
        
        if (!file_exists($envFile)) {
            // Will be reported in checkEnvironmentFile
            return;
        }
        
        try {
            $dotenv = \Dotenv\Dotenv::createImmutable(TEST_BASE_PATH);
            $dotenv->safeLoad();
        } catch (\Exception $e) {
            // Might already be loaded, that's okay
        }
    }
    
    private function checkEnvironmentFile()
    {
        $envFile = TEST_BASE_PATH . '/.env';
        
        if (!file_exists($envFile)) {
            $this->errors[] = "Missing .env file at: $envFile";
            return;
        }
        
        $this->log("✓ .env file exists");
        
        // Check if .env file has required variables
        $envContent = file_get_contents($envFile);
        
        // Check for API_KEY (for Elasticsearch) - REQUIRED
        if (!preg_match('/^API_KEY\s*=/m', $envContent)) {
            $this->errors[] = ".env file missing API_KEY variable (required)";
        } else {
            $this->log("✓ API_KEY found in .env");
        }
        
        // Check for EMBEDDING_SERVICE_URL - REQUIRED
        if (!preg_match('/^EMBEDDING_SERVICE_URL\s*=/m', $envContent)) {
            $this->errors[] = ".env file missing EMBEDDING_SERVICE_URL variable (required for vector search tests)";
            $this->errors[] = "  Add: EMBEDDING_SERVICE_URL=http://localhost:8001";
        } else {
            $this->log("✓ EMBEDDING_SERVICE_URL found in .env");
        }
        
        // Check for ELASTICSEARCH_HOST - OPTIONAL (has default)
        if (!preg_match('/^ELASTICSEARCH_HOST\s*=/m', $envContent)) {
            $this->log("  (ELASTICSEARCH_HOST not in .env, using default: https://localhost:9200)");
        } else {
            $this->log("✓ ELASTICSEARCH_HOST found in .env");
        }
    }
    
    private function checkElasticsearchConnection()
    {
        $this->log("Testing Elasticsearch connection...");
        
        $host = $_ENV['ELASTICSEARCH_HOST'] ?? getenv('ELASTICSEARCH_HOST') ?: 'https://localhost:9200';
        $apiKey = $_ENV['API_KEY'] ?? getenv('API_KEY') ?: null;
        
        if (!$apiKey) {
            $this->errors[] = "API_KEY not found in environment variables - cannot connect to Elasticsearch";
            $this->errors[] = "  Make sure .env file exists at: " . TEST_BASE_PATH . "/php/.env";
            return;
        }
        
        try {
            // Try to connect using the ElasticsearchClient
            $client = \Elastic\Elasticsearch\ClientBuilder::create()
                ->setHosts([$host])
                ->setApiKey($apiKey)
                ->setSSLVerification(false)
                ->build();
            
            $info = $client->info();
            $version = $info['version']['number'] ?? 'unknown';
            $clusterName = $info['cluster_name'] ?? 'unknown';
            
            $this->log("✓ Elasticsearch is reachable at $host");
            $this->log("  - Version: $version");
            $this->log("  - Cluster: $clusterName");
            
        } catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e) {
            $this->errors[] = "Elasticsearch connection failed: " . $e->getMessage();
            $this->errors[] = "  Host: $host";
            $this->errors[] = "  Check if Elasticsearch is running and credentials are correct";
        } catch (\Exception $e) {
            $this->errors[] = "Elasticsearch connection error: " . $e->getMessage();
            $this->errors[] = "  Host: $host";
        }
    }
    
    private function checkEmbeddingService()
    {
        $this->log("Testing embedding service connection...");
        
        $embeddingUrl = $_ENV['EMBEDDING_SERVICE_URL'] ?? getenv('EMBEDDING_SERVICE_URL') ?: null;
        
        if (!$embeddingUrl) {
            $this->errors[] = "EMBEDDING_SERVICE_URL not set in environment";
            $this->errors[] = "  Make sure .env file has: EMBEDDING_SERVICE_URL=http://localhost:8001";
            return;
        }
        
        // Try to connect to embedding service
        try {
            $ch = curl_init($embeddingUrl . '/health');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $this->log("✓ Embedding service is reachable at $embeddingUrl");
                
                // Try to get embedding dimensions
                try {
                    $embeddingClient = new \HawaiianSearch\EmbeddingClient();
                    $testVector = $embeddingClient->embedText("test");
                    if ($testVector && is_array($testVector)) {
                        $dims = count($testVector);
                        $this->log("  - Embedding dimensions: $dims");
                    }
                } catch (\Exception $e) {
                    $this->errors[] = "Embedding service reachable but test embedding failed: " . $e->getMessage();
                }
            } else {
                $this->errors[] = "Embedding service not reachable at $embeddingUrl (HTTP $httpCode)";
                if ($error) {
                    $this->errors[] = "  Error: $error";
                }
                $this->errors[] = "  Make sure the embedding service is running";
            }
        } catch (\Exception $e) {
            $this->errors[] = "Cannot connect to embedding service: " . $e->getMessage();
            $this->errors[] = "  URL: $embeddingUrl";
            $this->errors[] = "  Make sure the embedding service is running";
        }
    }
}
