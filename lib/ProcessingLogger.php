<?php
namespace Noiiolelo;

/**
 * Trait for logging document processing operations
 * Can be used by both MySQL-based and Elasticsearch-based implementations
 */
trait ProcessingLogger {
    
    /**
     * Start a processing log entry
     * Must be implemented by the using class to handle storage-specific logic
     */
    abstract protected function startProcessingLogImpl($operationType, $sourceID, $groupname, $parserKey, $metadata);
    
    /**
     * Complete a processing log entry
     * Must be implemented by the using class to handle storage-specific logic
     */
    abstract protected function completeProcessingLogImpl($logID, $status, $sentencesCount, $errorMessage);
    
    /**
     * Get processing logs with optional filters
     * Must be implemented by the using class to handle storage-specific logic
     */
    abstract protected function getProcessingLogsImpl($options = []);
    
    /**
     * Public interface for starting a processing log
     */
    public function startProcessingLog($operationType, $sourceID = null, $groupname = null, $parserKey = null, $metadata = null) {
        return $this->startProcessingLogImpl($operationType, $sourceID, $groupname, $parserKey, $metadata);
    }
    
    /**
     * Public interface for completing a processing log
     */
    public function completeProcessingLog($logID, $status = 'completed', $sentencesCount = 0, $errorMessage = null) {
        return $this->completeProcessingLogImpl($logID, $status, $sentencesCount, $errorMessage);
    }
    
    /**
     * Public interface for retrieving processing logs
     */
    public function getProcessingLogs($options = []) {
        return $this->getProcessingLogsImpl($options);
    }
    
    /**
     * Execute a processing operation with automatic logging
     * 
     * @param string $operationType Type of operation being performed
     * @param callable $operation The operation to execute
     * @param array $context Additional context for logging (sourceID, groupname, parserKey, metadata)
     * @return mixed The result of the operation
     */
    public function loggedOperation($operationType, callable $operation, array $context = []) {
        $logID = $this->startProcessingLog(
            $operationType,
            $context['sourceID'] ?? null,
            $context['groupname'] ?? null,
            $context['parserKey'] ?? null,
            $context['metadata'] ?? null
        );
        
        try {
            $result = $operation();
            
            // Determine sentence count from result if it's numeric
            $sentencesCount = is_numeric($result) ? $result : 0;
            
            if ($logID) {
                $this->completeProcessingLog($logID, 'completed', $sentencesCount);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            if ($logID) {
                $this->completeProcessingLog($logID, 'failed', 0, $e->getMessage());
            }
            throw $e;
        }
    }
}
