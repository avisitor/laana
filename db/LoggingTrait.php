<?php
namespace Noiiolelo;
use DateTimeImmutable;
use DateTimeZone;

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
     * Format a log message with funcName, using json if not a string
     */
    protected function formatLog($msg, $prefix = "") {
        if ($msg && !is_string($msg)) {
            $msg = json_encode($msg);
        }
        $context = "";
        if ($this->logName) {
            $context = $this->logName . ":";
        }
        if ($this->funcName) {
            $context .= $this->funcName . ":";
        }
        if( $prefix ) {
            $context .= $prefix;
        }
        if( $msg ) {
            if( $context ) {
                $msg = $context . ":" . $msg;
            }
        } else {
            $msg = $context;
        }
        return $msg;
    }
    
    /**
     * Format a log message with timestamp and script name
     */
    protected function formatLogMessage($msg, $intro = "") {
        if ($msg && !is_string($msg)) {
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
     * Log a message with context
     */
    public function log($obj, $prefix = "") {
        $prefix = $this->formatLog( $obj, $prefix );
        $this->debuglog( $prefix );
    }

    /*
     * Print a message to stdout, using var_export if not a string
     */
    public function print($msg, $prefix = "") {
        if ($msg && !is_string($msg)) {
            $msg = var_export($msg, true);
        }
        if( $prefix ) {
            $msg = "$prefix: $msg";
        }
        $this->outputLine($msg);
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
            $this->print($obj, $prefix);
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
     * Set global debug flag (for backward compatibility with legacy code)
     */
    protected function setGlobalDebug($debug) {
        global $debugFlag;
        $debugFlag = $debug;
    }

    /**
     * Output a message to stdout
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
     * Print object to stdout with formatting (for debug output only)
     */
    protected function printObject($obj, $intro = '') {
        echo( $this->formatLogMessage( $obj, $intro ) );
    }

    /**
     * Core logging implementation - logs a message to error log file
     */
    protected function debuglog($msg, $intro = "") {
        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            // Log to error log file (configured in php.ini)
            error_log( $this->formatLogMessage( $msg, $intro ) );
        }
        return;
    }
}
