<?php

namespace HawaiianSearch;

/**
 * Example usage of the new Timer system with accumulation
 * 
 * This shows how the RAII Timer pattern simplifies timing code:
 * - No need to manually track start/end times
 * - No need to maintain a timing array
 * - Timers automatically register and report through the singleton
 * - Multiple runs of the same timer name accumulate automatically
 * - Scoped timing ensures accurate measurements
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting TimerExample...\n";

require_once __DIR__ . '/Timer.php';
echo "Timer.php loaded\n";

require_once __DIR__ . '/TimerFactory.php';
echo "TimerFactory.php loaded\n";

echo "Timer classes loaded successfully\n";

echo "=== Timer System Example with Accumulation ===\n";

// Example 1: Multiple runs of the same operation accumulate
echo "\n1. Multiple API calls (same timer name accumulates):\n";
for ($i = 0; $i < 3; $i++) {
    $timer = TimerFactory::timer('api_call');
    usleep(50000 + rand(0, 30000)); // 0.05-0.08 seconds
    echo "   API call " . ($i + 1) . " completed\n";
    // Timer automatically stops at end of iteration
}

// Example 2: Different operations with different names
echo "\n2. Different operations:\n";
{
    $timer = TimerFactory::timer('database_query');
    usleep(100000); // 0.1 seconds
    echo "   Database query completed\n";
    unset($timer); // Force destructor call
}

{
    $timer = TimerFactory::timer('file_processing');
    usleep(75000); // 0.075 seconds
    echo "   File processing completed\n";
    unset($timer); // Force destructor call
}

// Example 3: Nested timing with accumulation
echo "\n3. Process multiple documents (nested timing):\n";
for ($docNum = 1; $docNum <= 2; $docNum++) {
    echo "   Processing document $docNum:\n";
    $docTimer = TimerFactory::timer('document_processing'); // Will accumulate across documents
    
    {
        $fetchTimer = TimerFactory::timer('fetch_text'); // Will accumulate
        usleep(30000);
        echo "     - Text fetched\n";
        unset($fetchTimer); // Force destructor call
    }
    
    // Process multiple sentences per document
    for ($sentNum = 1; $sentNum <= 2; $sentNum++) {
        $sentenceTimer = TimerFactory::timer('sentence_embedding'); // Will accumulate
        usleep(20000);
        echo "     - Sentence $sentNum embedded\n";
        unset($sentenceTimer); // Force destructor call
    }
    
    {
        $indexTimer = TimerFactory::timer('elasticsearch_indexing'); // Will accumulate
        usleep(15000);
        echo "     - Document indexed\n";
        unset($indexTimer); // Force destructor call
    }
    
    unset($docTimer); // Force destructor call
}

// Example 4: Show that timing can be disabled
echo "\n4. Disabled timing test:\n";
TimerFactory::setEnabled(false);
{
    $timer = TimerFactory::timer('disabled_operation');
    usleep(100000); // This won't be recorded
    echo "   Operation completed (but not timed)\n";
    unset($timer); // Force destructor call
}
TimerFactory::setEnabled(true);

// Print comprehensive report showing accumulation
echo "\n" . str_repeat("=", 70) . "\n";
$factory = TimerFactory::getInstance();
$factory->printReport();

// Show detailed statistics
echo "\n📊 DETAILED STATISTICS:\n";
$counts = $factory->getTimerCounts();
$averages = $factory->getAverageTimings();

foreach ($counts as $operation => $count) {
    if ($count > 1) {
        echo sprintf("%-25s: %d runs, avg %.3fs\n", 
            ucwords(str_replace('_', ' ', $operation)),
            $count,
            $averages[$operation]
        );
    }
}

echo "\n=== Example Complete ===\n";
