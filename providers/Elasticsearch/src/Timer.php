<?php

namespace HawaiianSearch;

/**
 * Interface for timing objects
 */
interface TimerInterface
{
    public function getElapsed(): float;
    public function getName(): string;
    public function stop(): float;
}

/**
 * RAII Timer class - starts timing on construction, stops on destruction
 * Automatically registers with TimerFactory for centralized reporting
 */
class Timer implements TimerInterface
{
    private string $name;
    private float $startTime;
    private bool $stopped = false;
    private static ?TimerFactory $factory = null;
    
    public function __construct(string $name)
    {
        $this->name = $name;
        $this->startTime = microtime(true);
    }
    
    public function __destruct()
    {
        // Only report if not already stopped
        if (!$this->stopped) {
            $duration = microtime(true) - $this->startTime;
            
            // Report to factory if available
            if (self::$factory) {
                self::$factory->recordTime($this->name, $duration);
            }
        }
    }
    
    /**
     * Set the global timer factory (called by TimerFactory)
     */
    public static function setFactory(?TimerFactory $factory): void
    {
        self::$factory = $factory;
    }
    
    /**
     * Get the current elapsed time without stopping the timer
     */
    public function getElapsed(): float
    {
        return microtime(true) - $this->startTime;
    }
    
    /**
     * Get the timer name
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * Manually stop the timer (usually not needed due to RAII)
     */
    public function stop(): float
    {
        if ($this->stopped) {
            return 0.0; // Already stopped, return 0
        }
        
        $duration = microtime(true) - $this->startTime;
        $this->stopped = true; // Mark as stopped to prevent destructor from reporting
        
        if (self::$factory) {
            self::$factory->recordTime($this->name, $duration);
        }
        
        return $duration;
    }
}
