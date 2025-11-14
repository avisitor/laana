<?php

/**
 * Noiiolelo Test Runner
 * Runs PHPUnit tests and provides clean summary output
 */

class NoiioleloTestRunner {
    private $testDir;
    private $vendorBin;
    private $resultsDir;
    
    public function __construct() {
        $this->testDir = __DIR__;
        $this->vendorBin = dirname(__DIR__) . '/vendor/bin';
        $this->resultsDir = $this->testDir . '/results';
        
        if (!is_dir($this->resultsDir)) {
            mkdir($this->resultsDir, 0755, true);
        }
    }
    
    public function run() {
        echo "\033[34m╔════════════════════════════════════════╗\033[0m\n";
        echo "\033[34m║   Noiiolelo Test Suite Runner         ║\033[0m\n";
        echo "\033[34m╚════════════════════════════════════════╝\033[0m\n\n";
        
        echo "Running PHPUnit tests...\n\n";
        
        $startTime = microtime(true);
        
        // Run PHPUnit with testdox output - we'll parse the output directly
        $command = sprintf(
            'cd %s && %s/phpunit --configuration %s --testdox --colors=never 2>/dev/null',
            escapeshellarg(dirname(__DIR__)),
            escapeshellarg($this->vendorBin),
            escapeshellarg(dirname(__DIR__) . '/phpunit.xml')
        );
        
        exec($command, $output, $exitCode);
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        // Parse testdox output
        $this->displayResults($output, $duration, $exitCode);
        
        return $exitCode;
    }
    
    private function displayResults($output, $duration, $exitCode) {
        // Parse testdox output
        $testResults = $this->parseTestdoxOutput($output);
        
        echo "\033[34m════════════════════════════════════════\033[0m\n";
        echo "\033[34m           Test Summary                 \033[0m\n";
        echo "\033[34m════════════════════════════════════════\033[0m\n\n";
        
        echo "\033[1mOverall Results:\033[0m\n";
        echo "  Total Tests:  {$testResults['total']}\n";
        echo "  \033[32m✓ Passed:\033[0m     {$testResults['passed']}\n";
        
        if ($testResults['failed'] > 0) {
            echo "  \033[31m✗ Failed:\033[0m     {$testResults['failed']}\n";
        }
        if ($testResults['errors'] > 0) {
            echo "  \033[31m✗ Errors:\033[0m     {$testResults['errors']}\n";
        }
        if ($testResults['warnings'] > 0) {
            echo "  \033[33m⚠ Warnings:\033[0m   {$testResults['warnings']}\n";
        }
        if ($testResults['risky'] > 0) {
            echo "  \033[33m⚡ Risky:\033[0m     {$testResults['risky']}\n";
        }
        if ($testResults['skipped'] > 0) {
            echo "  \033[33m⊘ Skipped:\033[0m    {$testResults['skipped']}\n";
        }
        
        echo "  Time:         {$duration}s\n\n";
        
        // Show test suites
        if (!empty($testResults['suites'])) {
            echo "\033[1mTest Suites:\033[0m\n";
            foreach ($testResults['suites'] as $suite) {
                $status = $suite['failed'] === 0 ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
                echo "  {$status} {$suite['name']}: {$suite['passed']}/{$suite['total']} passed\n";
            }
            echo "\n";
        }
        
        // Show failed tests
        if (!empty($testResults['failures'])) {
            echo "\033[1;31mFailed Tests:\033[0m\n";
            foreach ($testResults['failures'] as $failure) {
                echo "\n  \033[31m✗\033[0m {$failure['class']}::{$failure['method']}\n";
                if (!empty($failure['message'])) {
                    echo "    " . $failure['message'] . "\n";
                }
            }
            echo "\n";
        }
        
        echo "\033[34m════════════════════════════════════════\033[0m\n\n";
        
        if ($exitCode === 0) {
            echo "\033[32m✓ All tests passed successfully\033[0m\n\n";
        } else {
            echo "\033[31m✗ Some tests failed\033[0m\n\n";
        }
    }
    
    private function parseTestdoxOutput($output) {
        $results = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'errors' => 0,
            'warnings' => 0,
            'risky' => 0,
            'skipped' => 0,
            'suites' => [],
            'failures' => []
        ];
        
        $currentSuite = null;
        
        foreach ($output as $line) {
            // Parse test class names (suites)
            if (preg_match('/^([A-Z][a-zA-Z0-9_\\\\]+)$/', trim($line), $matches)) {
                $suiteName = basename(str_replace('\\', '/', $matches[1]));
                $currentSuite = [
                    'name' => $suiteName,
                    'total' => 0,
                    'passed' => 0,
                    'failed' => 0
                ];
                $results['suites'][] = &$currentSuite;
                continue;
            }
            
            // Parse test results
            if (preg_match('/^ ([✔✘]) (.+)$/', $line, $matches)) {
                $status = $matches[1];
                $testName = trim($matches[2]);
                
                $results['total']++;
                if ($currentSuite !== null) {
                    $currentSuite['total']++;
                }
                
                if ($status === '✔') {
                    $results['passed']++;
                    if ($currentSuite !== null) {
                        $currentSuite['passed']++;
                    }
                } else {
                    $results['failed']++;
                    if ($currentSuite !== null) {
                        $currentSuite['failed']++;
                        $results['failures'][] = [
                            'class' => $currentSuite['name'],
                            'method' => $testName,
                            'message' => ''
                        ];
                    }
                }
            }
            
            // Parse summary line
            if (preg_match('/Tests: (\d+), Assertions: (\d+)(?:, Failures: (\d+))?(?:, Errors: (\d+))?(?:, Warnings: (\d+))?(?:, Risky: (\d+))?(?:, Skipped: (\d+))?/', $line, $matches)) {
                $results['total'] = (int)$matches[1];
                $results['failed'] = isset($matches[3]) ? (int)$matches[3] : 0;
                $results['errors'] = isset($matches[4]) ? (int)$matches[4] : 0;
                $results['warnings'] = isset($matches[5]) ? (int)$matches[5] : 0;
                $results['risky'] = isset($matches[6]) ? (int)$matches[6] : 0;
                $results['skipped'] = isset($matches[7]) ? (int)$matches[7] : 0;
                $results['passed'] = $results['total'] - $results['failed'] - $results['errors'] - $results['skipped'];
            }
        }
        
        return $results;
    }
    
    private function formatFailureMessage($message) {
        $lines = explode("\n", trim($message));
        $formatted = "";
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            // Highlight important parts
            if (strpos($line, 'Expected') !== false) {
                $formatted .= "    \033[33m" . trim($line) . "\033[0m\n";
            } else if (strpos($line, 'Actual') !== false || strpos($line, 'Got') !== false) {
                $formatted .= "    \033[33m" . trim($line) . "\033[0m\n";
            } else {
                $formatted .= "    " . trim($line) . "\n";
            }
        }
        
        return $formatted;
    }
}

// Run the tests
$runner = new NoiioleloTestRunner();
$exitCode = $runner->run();
exit($exitCode);
