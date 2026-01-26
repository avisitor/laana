<?php

abstract class OpenSearchBaseTest {


    protected $testName;
    protected $assertions = 0;
    protected $failures = [];
    
    public function __construct($testName = '') {
        $this->testName = $testName ?: get_class($this);
    }
    
    public function run($verbose = false) {
        $this->setUp();
        
        try {
            $this->execute();
        } catch (Exception $e) {
            $this->failures[] = "Exception thrown: " . $e->getMessage() . "\n" . $e->getTraceAsString();
        } finally {
            $this->tearDown();
        }
        
        $result = [
            'assertions' => $this->assertions,
            'failures' => $this->failures,
            'success' => empty($this->failures)
        ];
        
        return $result;
    }
    
    abstract protected function execute();
    
    protected function setUp() {
        // Override in subclasses
    }
    
    protected function tearDown() {
        // Override in subclasses
    }
    
    protected function fail($message = '') {
        $this->failures[] = $message ?: "Test failed";
        throw new Exception($message ?: "Test failed");
    }
    
    protected function markTestSkipped($message = '') {
        throw new Exception("SKIPPED: " . ($message ?: "Test skipped"));
    }
    
    protected function log($message, $level = "info") {
        echo "[" . date("H:i:s") . "] " . strtoupper($level) . ": " . $message . "\n";
    }
    
    protected function assert($condition, $message = '') {
        $this->assertions++;
        
        if (!$condition) {
            $this->failures[] = $message ?: "Assertion failed";
            return false;
        }
        
        return true;
    }
    
    protected function assertEquals($expected, $actual, $message = '') {
        $this->assertions++;
        
        if ($expected !== $actual) {
            $msg = $message ?: "Expected '$expected', got '$actual'";
            $this->failures[] = $msg;
            return false;
        }
        
        return true;
    }
    
    protected function assertTrue($condition, $message = '') {
        return $this->assert($condition, $message ?: 'Expected true');
    }
    
    protected function assertFalse($condition, $message = '') {
        return $this->assert(!$condition, $message ?: 'Expected false');
    }
    
    protected function assertContains($needle, $haystack, $message = '') {
        $this->assertions++;
        
        $found = false;
        if (is_string($haystack)) {
            $found = strpos($haystack, $needle) !== false;
        } elseif (is_array($haystack)) {
            $found = in_array($needle, $haystack, true);
        }
        
        if (!$found) {
            $this->failures[] = $message ?: "Expected to find '$needle'";
            return false;
        }
        
        return true;
    }
    
    protected function assertNotContains($needle, $haystack, $message = '') {
        $this->assertions++;
        
        $found = false;
        if (is_string($haystack)) {
            $found = strpos($haystack, $needle) !== false;
        } elseif (is_array($haystack)) {
            $found = in_array($needle, $haystack, true);
        }
        
        if ($found) {
            $this->failures[] = $message ?: "Expected not to find '$needle'";
            return false;
        }
        
        return true;
    }
    
    protected function assertNotEmpty($value, $message = '') {
        $this->assert(!empty($value), $message ?: "Expected non-empty value");
    }

    protected function assertEmpty($value, $message = 'Empty assertion failed') {
        if (!empty($value)) {
            $this->assert(false, $message . " (got: " . print_r($value, true) . ")");
        }
    }

    protected function assertInstanceOf($expectedClass, $object, $message = '') {
        $this->assertions++;
        
        if (!($object instanceof $expectedClass)) {
            $actualClass = is_object($object) ? get_class($object) : gettype($object);
            $msg = $message ?: "Expected instance of $expectedClass, got $actualClass";
            $this->failures[] = $msg;
            return false;
        }
        
        return true;
    }

    protected function assertArrayHasKey($key, $array, $message = '') {
        $this->assertions++;
        
        if (!is_array($array) || !array_key_exists($key, $array)) {
            $this->failures[] = $message ?: "Array does not contain key '$key'";
            return false;
        }
        
        return true;
    }

    protected function assertIsArray($value, $message = '') {
        $this->assertions++;
        
        if (!is_array($value)) {
            $this->failures[] = $message ?: "Value is not an array";
            return false;
        }
        
        return true;
    }

    protected function assertGreaterThan($expected, $actual, $message = '') {
        $this->assertions++;
        
        if ($actual <= $expected) {
            $this->failures[] = $message ?: "Expected $actual to be greater than $expected";
            return false;
        }
        
        return true;
    }

    protected function assertMatchesRegularExpression($pattern, $string, $message = '') {
        $this->assertions++;
        
        if (!preg_match($pattern, $string)) {
            $this->failures[] = $message ?: "String '$string' does not match pattern '$pattern'";
            return false;
        }
        
        return true;
    }

    protected function assertNotNull($value, $message = '') {
        $this->assert($value !== null, $message ?: "Expected non-null value");
    }

    protected function assertNull($value, $message = '') {
        $this->assert($value === null, $message ?: "Expected null value");
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assert(strpos($haystack, $needle) !== false, $message ?: "Failed asserting that '{$haystack}' contains '{$needle}'.");
    }

    public function createMock($originalClassName)
    {
        return $this->getMockBuilder($originalClassName)
                    ->disableOriginalConstructor()
                    ->disableOriginalClone()
                    ->disableArgumentCloning()
                    ->disallowMockingUnknownTypes()
                    ->getMock();
    }

    public function getMockBuilder($originalClassName)
    {
        return new class {
            public function disableOriginalConstructor() { return $this; }
            public function disableOriginalClone() { return $this; }
            public function disableArgumentCloning() { return $this; }
            public function disallowMockingUnknownTypes() { return $this; }
            public function getMock() {
                return new class {
                    public function __call($method, $args) {
                        return null;
                    }
                };
            }
        };
    }

    // Test environment helpers
    protected function createTempDirectory() {
        $tempDir = sys_get_temp_dir() . '/hawaiian_test_' . uniqid();
        
        if (!mkdir($tempDir, 0755, true)) {
            throw new Exception("Could not create temp directory: $tempDir");
        }
        
        return $tempDir;
    }
    
    protected function removeTempDirectory($path) {
        if (!is_dir($path)) {
            return;
        }
        
        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $fullPath = "$path/$file";
            if (is_dir($fullPath)) {
                $this->removeTempDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }
        
        rmdir($path);
    }
    
    protected function createTempFile($content = '', $extension = 'txt') {
        $tempFile = tempnam(sys_get_temp_dir(), 'hawaiian_test_') . '.' . $extension;
        
        if ($content !== '') {
            file_put_contents($tempFile, $content);
        }
        
        return $tempFile;
    }
    
    protected function loadFixture($filename, $format = "json") {
        $fixturePath = __DIR__ . "/fixtures/" . $filename;
        
        if (!file_exists($fixturePath)) {
            throw new Exception("Fixture file not found: $fixturePath");
        }
        
        if ($format === "json") {
            $content = file_get_contents($fixturePath);
            return json_decode($content, true);
        } elseif ($format === "txt") {
            return file_get_contents($fixturePath);
        } elseif ($format === "csv") {
            $rows = [];
            if (($handle = fopen($fixturePath, "r")) !== FALSE) {
                while (($data = fgetcsv($handle)) !== FALSE) {
                    $rows[] = $data;
                }
                fclose($handle);
            }
            return $rows;
        }
        
        throw new Exception("Unsupported fixture format: $format");
    }

    protected function saveArrayAsFile($array, $format = 'json', $filename = null) {
        $filename = $filename ?: ('test_data_' . uniqid());
        $outputPath = sys_get_temp_dir() . '/' . $filename . '.' . $format;
        
        if ($format === 'json') {
            $data = json_encode($array, JSON_PRETTY_PRINT);
        } elseif ($format === 'csv') {
            $handle = fopen('php://temp', 'w+');
            foreach ($array as $row) {
                fputcsv($handle, $row);
            }
            rewind($handle);
            $data = stream_get_contents($handle);
            fclose($handle);
        } else {
            file_put_contents($outputPath, $data);
        }
        
        return $outputPath;
    }
}
