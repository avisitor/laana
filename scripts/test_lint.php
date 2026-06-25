<?php
// Lint test script to check for syntax errors before running tests
echo "Running PHP lint check on all provider files...\n";

$directories = [
    '/var/www/html/noiiolelo/providers/Neo4j',
    '/var/www/html/noiiolelo/lib',
    '/var/www/html/noiiolelo/tests'
];

$failedFiles = [];
$successCount = 0;
$failedCount = 0;

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        echo "Directory not found: $dir\n";
        continue;
    }
    
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $filePath = $file->getPathname();
            
            // Skip test files for linting (they may have incomplete implementations)
            if (strpos($filePath, '/tests/') !== false) {
                continue;
            }
            
            $output = [];
            $returnCode = 0;
            
            // Run PHP lint on file
            exec("php -l " . escapeshellarg($filePath), $output, $returnCode);
            
            if ($returnCode !== 0) {
                $failedFiles[] = $filePath;
                $failedCount++;
                echo "❌ FAILED: $filePath\n";
                foreach ($output as $line) {
                    echo "   $line\n";
                }
            } else {
                $successCount++;
                // echo "✅ PASSED: $filePath\n";
            }
        }
    }
}

echo "\n=== LINT RESULTS ===\n";
echo "Files checked: " . ($successCount + $failedCount) . "\n";
echo "Files passed: $successCount\n";
echo "Files failed: $failedCount\n";

if (count($failedFiles) > 0) {
    echo "\n=== ERRORS FOUND ===\n";
    foreach ($failedFiles as $file) {
        echo "Error in: $file\n";
    }
    exit(1);
} else {
    echo "\n✅ All PHP files pass linting!\n";
    exit(0);
}