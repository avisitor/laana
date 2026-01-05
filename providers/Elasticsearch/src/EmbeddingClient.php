<?php

namespace HawaiianSearch;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;

class EmbeddingClient {
    public const MODEL_SMALL = 'intfloat/multilingual-e5-small';
    public const MODEL_LARGE = 'intfloat/multilingual-e5-large-instruct';

    private HttpClient $httpClient;
    private string $baseUrl;

    public function __construct(string $baseUrl = null) {
        // Use environment variable if available, otherwise default to localhost
        if ($baseUrl === null) {
            $baseUrl = $_ENV['EMBEDDING_SERVICE_URL'] ?? getenv('EMBEDDING_SERVICE_URL') ?: 'http://localhost:5000';
        }
        
        // Configure HTTP client with generous timeouts for large document processing
        $this->httpClient = new HttpClient([
            'timeout' => 300,          // 5 minutes total timeout
            'connect_timeout' => 10,    // 10 seconds to establish connection
            'read_timeout' => 300,      // 5 minutes to read response
            'http_errors' => true,      // Throw exceptions on HTTP errors
            'verify' => false,         // Disable SSL verification for self-signed certs
        ]);
        $this->baseUrl = $baseUrl;
    }

    public function getHttpClient(): HttpClient {
        return $this->httpClient;
    }

    public function getBaseUrl(): string {
        return $this->baseUrl;
    }

    /**
     * @param string $text
     * @param string $prefix
     * @param string $modelName
     * @return array|null
     * @throws GuzzleException
     */
    public function embedText(string $text, string $prefix = 'query: ', string $modelName = self::MODEL_SMALL): ?array {
        $payload = [
            'json' => [
                'text' => $text,
                'prefix' => $prefix,
                'model' => $modelName
            ]
        ];

        try {
            $response = $this->httpClient->post($this->baseUrl . '/embed', $payload);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['embedding'] ?? null;
        } catch (GuzzleException $e) {
            // Log the error with context for debugging
            error_log("EmbeddingClient::embedText failed for text length " . strlen($text) . " using model $modelName: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param array $sentences
     * @param string $prefix
     * @param string $modelName
     * @return array|null
     * @throws GuzzleException
     */
    public function embedSentences(array $sentences, string $prefix = 'passage: ', string $modelName = self::MODEL_SMALL): ?array {
        $payload = [
            'json' => [
                'sentences' => $sentences,
                'prefix' => $prefix,
                'model' => $modelName
            ]
        ];

        try {
            // For large batches of sentences, we may need extra time
            $sentenceCount = count($sentences);
            $estimatedTime = max(60, $sentenceCount * 2); // At least 1 minute, or 2 seconds per sentence
            $timeoutOptions = [
                'timeout' => min(600, $estimatedTime), // Cap at 10 minutes
                'read_timeout' => min(600, $estimatedTime)
            ];
            
            $response = $this->httpClient->post($this->baseUrl . '/embed_sentences', array_merge($payload, $timeoutOptions));
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['embeddings'] ?? null;
        } catch (GuzzleException $e) {
            // Log the error with context for debugging
            error_log("EmbeddingClient::embedSentences failed for " . count($sentences) . " sentences using model $modelName: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param string $sentence
     * @return array|null
     * @throws GuzzleException
     */
    public function analyzeSentence(string $sentence): ?array {
        $payload = [
            'json' => [
                'sentence' => $sentence,
            ]
        ];

        try {
            $response = $this->httpClient->post($this->baseUrl . '/analyze', $payload);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            error_log("EmbeddingClient::analyzeSentence failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get health status of the embedding service
     * @return array|null
     */
    public function getHealth(): ?array {
        try {
            $response = $this->httpClient->get($this->baseUrl . '/health', ['timeout' => 10]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            error_log("EmbeddingClient::getHealth failed: " . $e->getMessage());
            return null;
        }
    }
}
