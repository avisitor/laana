<?php

define('TEST_BASE_PATH', __DIR__);

require_once __DIR__ . '/../../../vendor/autoload.php';

// Global setting for tests
$verbose = false;

class TestRunner {
    private $testGroups = [];
    private $verbose = false;
    private $basePath;
    
    public function __construct() {
        $this->basePath = __DIR__;
        $this->scanTestFiles();
    }
    
    private function scanTestFiles() {
        $directories = [
            'document_query' => 'Document Query Tests',
            'indexing' => 'Indexing Tests', 
            'integration' => 'Integration Tests',
            'production' => 'Production Tests',
            'sentence_query' => 'Sentence Query Tests',
            'system' => 'System Tests',
            'unit' => 'Unit Tests'
        ];
        
        foreach ($directories as $dir => $description) {
            $testDir = $this->basePath . '/' . $dir;
            if (is_dir($testDir)) {
                $this->testGroups[$dir] = [
                    'description' => $description,
                    'tests' => []
                ];
                
                $files = glob($testDir . '/test_*.php');
                foreach ($files as $file) {
                    $testName = basename($file, '.php');
                    $this->testGroups[$dir]['tests'][$testName] = $file;
                }
            }
        }
    }
    
    public function showUsage() {
        echo "Hawaiian Search System - Test Runner\n";
        echo "=====================================\n\n";
        echo "Usage: php run_tests.php [options]\n\n";
        echo "Options:\n";
        echo "  --help, -h        Show this help message\n";
        echo "  --list, -l        List all test groups and tests\n";
        echo "  --verbose, -v     Verbose output\n";
        echo "  --group=GROUP     Run all tests in a specific group\n";
        echo "  --test=TEST       Run a specific test (full path or test name)\n";
        echo "  --all             Run all tests\n\n";
        echo "Examples:\n";
        echo "  php run_tests.php --list\n";
        echo "  php run_tests.php --group=document_query\n";
        echo "  php run_tests.php --test=test_comprehensive_search_modes\n";
        echo "  php run_tests.php --group=document_query --verbose\n";
        echo "  php run_tests.php --all\n\n";
    }
    
    public function listTests() {
        echo "Available Test Groups and Tests:\n";
        echo "================================\n\n";
        
        foreach ($this->testGroups as $groupName => $group) {
            echo "Group: {$groupName} - {$group['description']}\n";
            echo str_repeat('-', 50) . "\n";
            
            if (empty($group['tests'])) {
                echo "  (No tests found)\n";
            } else {
                foreach ($group['tests'] as $testName => $testFile) {
                    echo "  • {$testName}\n";
                }
            }
            echo "\n";
        }
    }
    
    public function runTest($testFile, $testName = null) {
        global $verbose;
        $verbose = $this->verbose;
        
        if (!file_exists($testFile)) {
            echo "ERROR: Test file not found: {$testFile}\n";
            return false;
        }
        
        $displayName = $testName ?: basename($testFile, '.php');
        
        // Set up include path for the test
        $originalIncludePath = get_include_path();
        set_include_path($this->basePath . PATH_SEPARATOR . $originalIncludePath);
        
        // Capture output
        ob_start();
        $startTime = microtime(true);
        
        try {
            // Include BaseTest if not already included
            if (!class_exists('BaseTest')) {
                require_once $this->basePath . '/BaseTest.php';
            }
            
            // Include and run the test
            $testResult = null;
            
            // Include the test file to make the class available
            require_once $testFile;

            // Derive class name from file name (e.g., test_basic_search.php -> TestBasicSearch)
            $className = str_replace(' ', '', ucwords(str_replace('_', ' ', basename($testFile, '.php'))));

            if (!class_exists($className)) {
                throw new Exception("Test class {$className} not found in {$testFile}");
            }

            // Instantiate and run the test
            $testInstance = new $className();
            if (!($testInstance instanceof BaseTest)) {
                throw new Exception("Test class {$className} must extend BaseTest");
            }

            $testResult = $testInstance->run($this->verbose);
            
            $output = ob_get_clean();
            echo $output;
            
            // Restore include path
            set_include_path($originalIncludePath);
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            if ($testResult['success']) {
                echo "{$displayName}: PASS ({$duration}ms)\n";
                if ($this->verbose) {
                    echo "  Assertions: {$testResult['assertions']}\n";
                }
                return true;
            } else {
                echo "{$displayName}: FAIL ({$duration}ms)\n";
                foreach ($testResult['failures'] as $failure) {
                    echo "  - {$failure}\n";
                }
                return false;
            }
            
        } catch (Exception $e) {
            ob_end_clean();
            set_include_path($originalIncludePath);
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            if ($this->verbose) {
                echo "\nRunning test: {$displayName}\n";
                echo "File: {$testFile}\n";
                echo "Duration: {$duration}ms\n";
                echo "FAILED: " . $e->getMessage() . "\n";
                echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
            } else {
                echo "{$displayName}: FAIL - " . $e->getMessage() . "\n";
            }
            return false;
        } catch (Error $e) {
            ob_end_clean();
            set_include_path($originalIncludePath);
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            if ($this->verbose) {
                echo "\nRunning test: {$displayName}\n";
                echo "File: {$testFile}\n";
                echo "Duration: {$duration}ms\n";  
                echo "ERROR: " . $e->getMessage() . "\n";
                echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
            } else {
                echo "{$displayName}: ERROR - " . $e->getMessage() . "\n";
            }
            return false;
        }
    }
    
    private function parseTestResults($output, $testName) {
        $results = [];
        
        // Special handling for comprehensive search modes test
        if (strpos($testName, 'comprehensive_search_modes') !== false) {
            // Extract individual mode results
            if (preg_match_all('/✓ Mode \'([^\']+)\' passed with (\d+) results/', $output, $matches)) {
                foreach ($matches[1] as $i => $mode) {
                    $results[] = "{$mode}: PASS ({$matches[2][$i]} results)";
                }
            }
            
            if (preg_match_all('/✗ ([^:]+): (.+)/', $output, $matches)) {
                foreach ($matches[1] as $i => $mode) {
                    $results[] = "{$mode}: FAIL - " . trim($matches[2][$i]);
                }
            }
        }
        
        return $results;
    }
    
    public function runGroup($groupName) {
        if (!isset($this->testGroups[$groupName])) {
            echo "ERROR: Test group '{$groupName}' not found.\n";
            echo "Available groups: " . implode(', ', array_keys($this->testGroups)) . "\n";
            return false;
        }
        
        // Run prerequisites check first (except when running system group itself)
        if ($groupName !== 'system') {
            echo "\n--- Pre-flight Checks ---\n";
            if (isset($this->testGroups['system']['tests']['test_prerequisites'])) {
                $prereqFile = $this->testGroups['system']['tests']['test_prerequisites'];
                $this->runTest($prereqFile, 'test_prerequisites');
                // If prerequisites fail, the test will exit with code 1
            }
        }
        
        $group = $this->testGroups[$groupName];
        echo "Running test group: {$groupName} - {$group['description']}\n";
        echo str_repeat('=', 60) . "\n";
        
        if (empty($group['tests'])) {
            echo "No tests found in group '{$groupName}'\n";
            return true;
        }
        
        $passed = 0;
        $failed = 0;
        
        foreach ($group['tests'] as $testName => $testFile) {
            if ($this->runTest($testFile, $testName)) {
                $passed++;
            } else {
                $failed++;
            }
        }
        
        echo "\nGroup '{$groupName}' Summary:\n";
        echo "  Passed: {$passed}\n";
        echo "  Failed: {$failed}\n";
        echo "  Total:  " . ($passed + $failed) . "\n\n";
        
        return $failed === 0;
    }
    
    public function runAll() {
        echo "Running All Tests\n";
        echo str_repeat('=', 60) . "\n";
        
        // Run prerequisites check first
        echo "\n--- Pre-flight Checks ---\n";
        if (isset($this->testGroups['system']['tests']['test_prerequisites'])) {
            $prereqFile = $this->testGroups['system']['tests']['test_prerequisites'];
            $prereqResult = $this->runTest($prereqFile, 'test_prerequisites');
            // If prerequisites fail, the test will exit with code 1
            // If we reach here, prerequisites passed
        }
        
        $totalPassed = 0;
        $totalFailed = 0;
        $failedTests = []; // Track failed tests with details
        
        foreach ($this->testGroups as $groupName => $group) {
            echo "\n--- Group: {$groupName} ---\n";
            
            foreach ($group['tests'] as $testName => $testFile) {
                // Skip prerequisites since we already ran it
                if ($groupName === 'system' && $testName === 'test_prerequisites') {
                    continue;
                }
                
                // Capture output for failed tests
                ob_start();
                $testResult = $this->runTest($testFile, $testName);
                $testOutput = ob_get_clean();
                
                // Re-output the test result
                echo $testOutput;
                
                if ($testResult) {
                    $totalPassed++;
                } else {
                    $totalFailed++;
                    // Store failed test details
                    $failedTests[] = [
                        'group' => $groupName,
                        'name' => $testName,
                        'file' => $testFile,
                        'output' => $testOutput
                    ];
                }
            }
        }
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "OVERALL SUMMARY:\n";
        echo "  Passed: {$totalPassed}\n";
        echo "  Failed: {$totalFailed}\n";
        echo "  Total:  " . ($totalPassed + $totalFailed) . "\n";
        
        // Show detailed failure information if there were any failures
        if ($totalFailed > 0) {
            echo "\n" . str_repeat('=', 60) . "\n";
            echo "FAILED TESTS DETAILS:\n";
            echo str_repeat('=', 60) . "\n";
            
            foreach ($failedTests as $i => $failedTest) {
                echo "\n" . ($i + 1) . ". {$failedTest['group']}/{$failedTest['name']}\n";
                echo "   File: {$failedTest['file']}\n";
                
                // Extract failure details from output
                $lines = explode("\n", $failedTest['output']);
                $failureInfo = [];
                $inFailureSection = false;
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // Look for failure indicators
                    if (strpos($line, 'FAIL') !== false) {
                        $failureInfo[] = "   " . $line;
                        $inFailureSection = true;
                    } elseif (strpos($line, 'ERROR') !== false) {
                        $failureInfo[] = "   " . $line;
                        $inFailureSection = true;
                    } elseif (strpos($line, '  - ') === 0) {
                        // Failure detail line
                        $failureInfo[] = "   " . $line;
                    } elseif (strpos($line, 'Exception') !== false || strpos($line, 'Error:') !== false) {
                        $failureInfo[] = "   " . $line;
                    }
                }
                
                if (!empty($failureInfo)) {
                    foreach ($failureInfo as $info) {
                        echo $info . "\n";
                    }
                } else {
                    echo "   (No specific failure details captured)\n";
                }
            }
        }
        
        return $totalFailed === 0;
    }
    
    public function findTestFile($testName) {
        // Try to find test by name in any group
        foreach ($this->testGroups as $group) {
            foreach ($group['tests'] as $name => $file) {
                if ($name === $testName || basename($file, '.php') === $testName) {
                    return $file;
                }
            }
        }
        
        // Try direct file path
        if (file_exists($testName)) {
            return $testName;
        }
        
        // Try relative to test directory
        $fullPath = $this->basePath . '/' . $testName;
        if (file_exists($fullPath)) {
            return $fullPath;
        }
        
        return null;
    }
    
    public function run($args) {
        // Parse arguments
        $options = [];
        foreach ($args as $arg) {
            if (strpos($arg, '--') === 0) {
                $parts = explode('=', $arg, 2);
                $key = substr($parts[0], 2);
                $value = isset($parts[1]) ? $parts[1] : true;
                $options[$key] = $value;
            } elseif ($arg === '-h') {
                $options['help'] = true;
            } elseif ($arg === '-l') {
                $options['list'] = true;
            } elseif ($arg === '-v') {
                $options['verbose'] = true;
            }
        }
        
        $this->verbose = isset($options['verbose']);
        
        if (isset($options['help'])) {
            $this->showUsage();
            return;
        }
        
        if (isset($options['list'])) {
            $this->listTests();
            return;
        }
        
        if (isset($options['all'])) {
            $this->runAll();
            return;
        }
        
        if (isset($options['group'])) {
            $this->runGroup($options['group']);
            return;
        }
        
        if (isset($options['test'])) {
            $testFile = $this->findTestFile($options['test']);
            if ($testFile) {
                $this->runTest($testFile);
            } else {
                echo "ERROR: Test '{$options['test']}' not found.\n";
            }
            return;
        }
        
        // Default: show usage
        $this->showUsage();
    }
}

// Main execution
if (basename($argv[0]) === basename(__FILE__)) {
    $runner = new TestRunner();
    $runner->run(array_slice($argv, 1));
}
