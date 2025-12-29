<?php
/**
 * LoggingTrait - Reusable logging functionality for classes
 * 
 * Provides standardized logging methods that can be included in any class.
 * Requires the class to define:
 *   - $logName property (class name for logging)
 *   - $funcName property (current function name for logging context)
 * 
 * Usage:
 *   class MyClass {
 *       use LoggingTrait;
 *       protected $logName = "MyClass";
 *       protected $funcName = "";
 *       private $debug = false;
 *   }
 */

trait LoggingTrait {
    /**
     * Format a log message with context
     */
    protected function formatLog($obj, $prefix = "") {
        if ($prefix && !is_string($prefix)) {
            $prefix = json_encode($prefix);
        }
        if ($this->funcName) {
            $func = $this->logName . ":" . $this->funcName;
            $prefix = ($prefix) ? "$func:$prefix" : $func;
        }
        return $prefix;
    }
    
    /**
     * Log a message with context
     */
    public function log($obj, $prefix = "") {
        $prefix = $this->formatLog($obj, $prefix);
        $this->debuglog($obj, $prefix);
    }
    
    /**
     * Print object for debugging (only if debug mode enabled)
     */
    public function debugPrint($obj, $prefix = "") {
        if ($this->debug) {
            $text = $this->formatLog($obj, $prefix);
            $this->printObject($obj, $text);
        }
    }
    
    public function verbosePrint($obj, $prefix = "") {
        if ($this->verbose) {
            $text = $this->formatLog($obj, $prefix);
            $this->printObject($obj, $text);
        }
    }
    
    /**
     * Set debug mode
     */
    private function setDebug($debug) {
        $this->debug = $debug;
        $this->setGlobalDebug($debug);
    }

    /**
     * Output a message to stdout (can be suppressed by setting $verbose to false)
     */
    public function output($message) {
        echo $message;
    }

    /**
     * Output a message with newline to stdout
     */
    protected function outputLine($message) {
        $this->output($message . "\n");
    }

    /**
     * Core logging implementation - logs a message to error log file
     * Self-contained - does not call global functions
     */
    protected function debuglog($msg, $intro = "") {
        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            // Format the message inline without calling global formatLogMessage
            if (is_object($msg) || is_array($msg)) {
                $msg = var_export($msg, true);
            }
            $defaultTimezone = 'Pacific/Honolulu';
            $now = new DateTimeImmutable("now", new DateTimeZone($defaultTimezone));
            $now = $now->format('Y-m-d H:i:s');
            $out = "$now " . ($_SERVER['SCRIPT_NAME'] ?? 'CLI');
            if ($intro) {
                $out .= " $intro:";
            }
            $formattedMsg = "$out $msg";
            
            // Log to error log file (configured in php.ini)
            error_log($formattedMsg);
        }
        return;
    }

    /**
     * Print object to stdout with formatting (for debug output only)
     * Self-contained - does not call global functions
     */
    protected function printObject($obj, $intro = '') {
        // Format the message inline
        if (is_object($obj) || is_array($obj)) {
            $msg = var_export($obj, true);
        } else {
            $msg = $obj;
        }
        $defaultTimezone = 'Pacific/Honolulu';
        $now = new DateTimeImmutable("now", new DateTimeZone($defaultTimezone));
        $now = $now->format('Y-m-d H:i:s');
        $out = "$now " . ($_SERVER['SCRIPT_NAME'] ?? 'CLI');
        if ($intro) {
            $out .= " $intro:";
        }
        echo "$out $msg\n";
    }

    /**
     * Format a log message with timestamp and script name
     */
    protected function formatLogMessage($msg, $intro = "") {
        if (is_object($msg) || is_array($msg)) {
            $msg = var_export($msg, true);
        }
        $defaultTimezone = 'Pacific/Honolulu';
        $now = new DateTimeImmutable("now", new DateTimeZone($defaultTimezone));
        $now = $now->format('Y-m-d H:i:s');
        $out = "$now " . $_SERVER['SCRIPT_NAME'];
        if ($intro) {
            $out .= " $intro:";
        }
        return "$out $msg";
    }

    /**
     * Set global debug flag (for backward compatibility with legacy code)
     */
    protected function setGlobalDebug($debug) {
        global $debugFlag;
        $debugFlag = $debug;
    }
}
