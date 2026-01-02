<?php

namespace Noiiolelo;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;

class EmbeddingClient {
    private HttpClient $httpClient;
    private string $baseUrl;

    public function __construct(?string $baseUrl = null) {
        if ($baseUrl === null) {
            $baseUrl = $_ENV['EMBEDDING_SERVICE_URL']
                ?? getenv('EMBEDDING_SERVICE_URL')
                ?? 'http://localhost:5000';
        }
        $this->httpClient = new HttpClient([
            'timeout' => 300,
            'connect_timeout' => 10,
            'read_timeout' => 300,
            'http_errors' => true,
            'verify' => false,
        ]);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function embedText(string $text, string $prefix = 'query: '): ?array {
        $payload = ['json' => ['text' => $text, 'prefix' => $prefix]];
        try {
            $response = $this->httpClient->post($this->baseUrl . '/embed', $payload);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['embedding'] ?? null;
        } catch (GuzzleException $e) {
            error_log("EmbeddingClient::embedText failed: " . $e->getMessage());
            return null;
        }
    }

    public function embedSentences(array $sentences, string $prefix = 'passage: '): ?array {
        $payload = ['json' => ['sentences' => $sentences, 'prefix' => $prefix]];
        try {
            $sentenceCount = count($sentences);
            $estimatedTime = max(60, $sentenceCount * 2);
            $response = $this->httpClient->post($this->baseUrl . '/embed_sentences', array_merge($payload, [
                'timeout' => min(600, $estimatedTime),
                'read_timeout' => min(600, $estimatedTime),
            ]));
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['embeddings'] ?? null;
        } catch (GuzzleException $e) {
            error_log("EmbeddingClient::embedSentences failed: " . $e->getMessage());
            return null;
        }
    }
}
