<?php

namespace HawaiianSearch;

require_once __DIR__ . '/Timer.php';
require_once __DIR__ . '/TimerFactory.php';

echo "=== Simple Timer Test ===\n";

// Test basic timer creation
echo "Creating timer...\n";
$timer = TimerFactory::timer('test_operation');
echo "Timer created\n";

usleep(100000); // 0.1 seconds
echo "Operation completed\n";

unset($timer); // Force destructor call
echo "Timer destroyed\n";

// Check timing data
$factory = TimerFactory::getInstance();
$timings = $factory->getTimings();
echo "Timings: " . var_export($timings, true) . "\n";

echo "Test complete\n";
