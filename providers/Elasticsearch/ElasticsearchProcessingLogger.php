<?php
namespace Noiiolelo\Providers\Elasticsearch;

use Noiiolelo\ProcessingLogger;

/**
 * Elasticsearch implementation of processing logging
 * Stores processing logs in an Elasticsearch index
 */
class ElasticsearchProcessingLogger {
    use ProcessingLogger;
    
    private $client;
    private $indexName;
    
    public function __construct($elasticsearchClient, $indexName = 'processing-logs') {
        $this->client = $elasticsearchClient;
        $this->indexName = $indexName;
    }
    
    protected function startProcessingLogImpl($operationType, $sourceID = null, $groupname = null, $parserKey = null, $metadata = null) {
        try {
            $logDoc = [
                'operation_type' => $operationType,
                'source_id' => $sourceID,
                'groupname' => $groupname,
                'parser_key' => $parserKey,
                'status' => 'started',
                'sentences_count' => 0,
                'started_at' => date('c'),
                'completed_at' => null,
                'error_message' => null,
                'metadata' => $metadata
            ];
            
            // Use a unique ID that we can reference later
            $logId = uniqid('log_', true);
            $this->client->index($logDoc, $logId, $this->indexName);
            return $logId;
        } catch (\Exception $e) {
            error_log("Failed to start processing log: " . $e->getMessage());
            return null;
        }
    }
    
    protected function completeProcessingLogImpl($logID, $status = 'completed', $sentencesCount = 0, $errorMessage = null) {
        if (!$logID) return false;
        
        try {
            // First, get the existing document to preserve fields
            $existing = $this->client->getDocument($logID, $this->indexName);
            
            if ($existing) {
                // Merge the updates with existing data
                $existing['status'] = $status;
                $existing['sentences_count'] = $sentencesCount;
                $existing['completed_at'] = date('c');
                $existing['error_message'] = $errorMessage;
                
                // Re-index the complete document
                $this->client->index($existing, $logID, $this->indexName);
            } else {
                // Document doesn't exist, just log the error
                error_log("Processing log document $logID not found for update");
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to complete processing log: " . $e->getMessage());
            return false;
        }
    }
    
    protected function getProcessingLogsImpl($options = []) {
        try {
            $query = ['match_all' => (object)[]];
            $filters = [];
            
            if (isset($options['operation_type'])) {
                $filters[] = ['term' => ['operation_type' => $options['operation_type']]];
            }
            if (isset($options['groupname'])) {
                $filters[] = ['term' => ['groupname' => $options['groupname']]];
            }
            if (isset($options['status'])) {
                $filters[] = ['term' => ['status' => $options['status']]];
            }
            
            if (!empty($filters)) {
                $query = [
                    'bool' => [
                        'must' => $query,
                        'filter' => $filters
                    ]
                ];
            }
            
            $searchParams = [
                'index' => $this->indexName,
                'body' => [
                    'query' => $query,
                    'sort' => [['started_at' => ['order' => 'desc']]],
                    'size' => $options['limit'] ?? 100
                ]
            ];
            
            $response = $this->client->search($searchParams);
            $hits = $response['hits']['hits'] ?? [];
            
            return array_map(function($hit) {
                return array_merge(['log_id' => $hit['_id']], $hit['_source']);
            }, $hits);
            
        } catch (\Exception $e) {
            error_log("Failed to get processing logs: " . $e->getMessage());
            return [];
        }
    }
}
