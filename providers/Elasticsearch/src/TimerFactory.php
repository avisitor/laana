<?php

namespace HawaiianSearch;

require_once __DIR__ . '/Timer.php';

/**
 * Singleton TimerFactory for centralized timing management
 * Collects timing data from all Timer instances and provides reporting
 */
class TimerFactory
{
    private static ?TimerFactory $instance = null;
    private array $timings = [];
    private array $timerCounts = [];
    private bool $enabled = true;
    
    private function __construct()
    {
        // Register this factory with the Timer class
        Timer::setFactory($this);
    }
    
    /**
     * Get the singleton instance
     */
    public static function getInstance(): TimerFactory
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Create a new timer with the given name
     * Usage: $timer = TimerFactory::timer('operation_name');
     */
    public static function timer(string $name): TimerInterface
    {
        $factory = self::getInstance();
        if (!$factory->enabled) {
            return new NullTimer($name); // No-op timer when disabled
        }
        return new Timer($name);
    }
    
    /**
     * Enable or disable timing collection
     */
    public static function setEnabled(bool $enabled): void
    {
        self::getInstance()->enabled = $enabled;
    }
    
    /**
     * Record timing data (called by Timer destructor)
     * Accumulates multiple runs of the same timer by name
     */
    public function recordTime(string $name, float $duration): void
    {
        if (!isset($this->timings[$name])) {
            $this->timings[$name] = 0;
            $this->timerCounts[$name] = 0;
        }
        $this->timings[$name] += $duration;
        $this->timerCounts[$name]++;
    }
    
    /**
     * Get all timing data
     */
    public function getTimings(): array
    {
        return $this->timings;
    }
    
    /**
     * Get timer counts (how many times each timer was run)
     */
    public function getTimerCounts(): array
    {
        return $this->timerCounts;
    }
    
    /**
     * Get average time per timer execution
     */
    public function getAverageTimings(): array
    {
        $averages = [];
        foreach ($this->timings as $name => $totalTime) {
            $count = $this->timerCounts[$name] ?? 1;
            $averages[$name] = $totalTime / $count;
        }
        return $averages;
    }
    
    /**
     * Get total elapsed time
     */
    public function getTotalTime(): float
    {
        return array_sum($this->timings);
    }
    
    /**
     * Reset all timing data
     */
    public function reset(): void
    {
        $this->timings = [];
        $this->timerCounts = [];
    }
    
    /**
     * Print detailed timing report
     */
    public function printReport(callable $printFunction = null): void
    {
        $print = $printFunction ?: function($msg) { echo $msg . "\n"; };
        
        $print(str_repeat("=", 60));
        $print("⏱️  DETAILED TIMING BREAKDOWN");
        $print(str_repeat("=", 60));
        
        $totalTime = $this->getTotalTime();
        
        if ($totalTime == 0) {
            $print("No timing data available.");
            return;
        }
        
        // Sort by time spent (descending)
        $sortedTimings = $this->timings;
        arsort($sortedTimings);
        
        foreach ($sortedTimings as $operation => $time) {
            if ($time > 0) {
                $percentage = ($time / $totalTime) * 100;
                $count = $this->timerCounts[$operation] ?? 1;
                $average = $time / $count;
                
                if ($count > 1) {
                    $print(sprintf("%-25s: %8.3fs (%5.1f%%) [%dx, avg %.3fs]", 
                        ucwords(str_replace('_', ' ', $operation)), 
                        $time, 
                        $percentage,
                        $count,
                        $average
                    ));
                } else {
                    $print(sprintf("%-25s: %8.3fs (%5.1f%%)", 
                        ucwords(str_replace('_', ' ', $operation)), 
                        $time, 
                        $percentage
                    ));
                }
            }
        }
        
        $print(str_repeat("-", 60));
        $print(sprintf("%-25s: %8.3fs (100.0%%)", "TOTAL", $totalTime));
        $print(str_repeat("=", 60));
    }
    
    /**
     * Print performance insights
     */
    public function printInsights(callable $printFunction = null, int $processedDocuments = 0): void
    {
        $print = $printFunction ?: function($msg) { echo $msg . "\n"; };
        $totalTime = $this->getTotalTime();
        
        if ($totalTime == 0) return;
        
        $print("\n💡 PERFORMANCE INSIGHTS:");
        
        // Calculate unaccounted time in document processing
        if (isset($this->timings['total_document_processing']) && $this->timings['total_document_processing'] > 0) {
            $accountedTime = ($this->timings['sentence_embedding'] ?? 0) + 
                           ($this->timings['document_embedding'] ?? 0) + 
                           ($this->timings['text_processing'] ?? 0) + 
                           ($this->timings['sentence_filtering'] ?? 0) + 
                           ($this->timings['sentence_object_construction'] ?? 0) + 
                           ($this->timings['document_chunking'] ?? 0) + 
                           ($this->timings['vector_validation'] ?? 0) + 
                           ($this->timings['document_assembly'] ?? 0) + 
                           ($this->timings['source_validation'] ?? 0) + 
                           ($this->timings['metadata_extraction'] ?? 0) + 
                           ($this->timings['individual_sentence_metadata'] ?? 0);
            
            $unaccountedTime = $this->timings['total_document_processing'] - $accountedTime;
            $unaccountedPercentage = ($unaccountedTime / $totalTime) * 100;
            
            if ($unaccountedPercentage > 5) {
                $print("   ⚠️  UNACCOUNTED TIME: " . number_format($unaccountedPercentage, 1) . "% (" . number_format($unaccountedTime, 1) . "s) - need to investigate!");
            }
        }
        
        // HTTP performance insights
        $httpTime = ($this->timings['fetch_text'] ?? 0) + ($this->timings['parallel_fetch'] ?? 0);
        if ($httpTime > $totalTime * 0.3) {
            $print("   🌐 HTTP requests taking " . number_format(($httpTime/$totalTime)*100, 1) . "% of time - consider more parallel fetching");
        }
        
        // Embedding insights
        if (($this->timings['sentence_embedding'] ?? 0) > $totalTime * 0.2) {
            $print("   🔤 Sentence embedding is " . number_format((($this->timings['sentence_embedding'] ?? 0)/$totalTime)*100, 1) . "% of time - major bottleneck");
        }
        
        if (($this->timings['document_embedding'] ?? 0) > $totalTime * 0.1) {
            $print("   📄 Document embedding is " . number_format((($this->timings['document_embedding'] ?? 0)/$totalTime)*100, 1) . "% of time");
        }
        
        // Metadata insights
        if (($this->timings['metadata_extraction'] ?? 0) > $totalTime * 0.05) {
            $print("   🧠 Metadata extraction is " . number_format((($this->timings['metadata_extraction'] ?? 0)/$totalTime)*100, 1) . "% of time");
        }
        
        if (($this->timings['individual_sentence_metadata'] ?? 0) > $totalTime * 0.05) {
            $print("   🧠 Individual sentence metadata analysis is " . number_format((($this->timings['individual_sentence_metadata'] ?? 0)/$totalTime)*100, 1) . "% of time");
        }
        
        // Other insights
        if (($this->timings['hawaiian_ratio_calc'] ?? 0) > $totalTime * 0.1) {
            $print("   📊 Hawaiian ratio calculation is " . number_format((($this->timings['hawaiian_ratio_calc'] ?? 0)/$totalTime)*100, 1) . "% of time");
        }
        
        if (($this->timings['elasticsearch_indexing'] ?? 0) < $totalTime * 0.05) {
            $print("   ✅ Elasticsearch indexing is very fast (" . number_format((($this->timings['elasticsearch_indexing'] ?? 0)/$totalTime)*100, 1) . " %)");
        }
        
        // Performance estimates
        if ($processedDocuments > 0) {
            $avgTimePerDoc = $totalTime / $processedDocuments;
            $print("   📈 Average time per document: " . number_format($avgTimePerDoc, 3) . "s");
            $print("   • Estimated time for 1,000 docs: " . number_format(1000 * $avgTimePerDoc / 60, 1) . " minutes");
            $print("   • Estimated time for 20,000 docs: " . number_format(20000 * $avgTimePerDoc / 3600, 1) . " hours");
        }
    }
}

/**
 * Null Timer for when timing is disabled - no-op implementation
 */
class NullTimer implements TimerInterface
{
    private string $name;
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
    
    public function getElapsed(): float
    {
        return 0.0;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function stop(): float
    {
        return 0.0;
    }
}
