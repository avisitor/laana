<?php

namespace Noiiolelo\Tests;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Records the runtime message of every skipped test to a JSONL sidecar.
 *
 * PHPUnit's JUnit logger emits bare <skipped/> elements without the skip
 * message, so tests/junit_to_json.py cannot report WHY a test was skipped
 * and falls back to guessing from test source code — which misattributes
 * skips thrown from shared helpers (e.g. getTestProvider() reporting
 * "Provider Elasticsearch was not available" was rendered with whatever
 * markTestSkipped string happened to sit in the test method body).
 *
 * Enabled when NOIIOLELO_SKIP_LOG names a file (run-tests.sh sets it and
 * passes the same path to junit_to_json.py via --skip-log). Without the
 * env var the extension is a no-op, so plain `vendor/bin/phpunit` runs are
 * unaffected.
 */
final class SkipLogExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $path = getenv('NOIIOLELO_SKIP_LOG');
        if (!is_string($path) || $path === '') {
            return;
        }

        $facade->registerSubscriber(new class ($path) implements SkippedSubscriber {
            private string $path;

            public function __construct(string $path)
            {
                $this->path = $path;
            }

            public function notify(Skipped $event): void
            {
                $test = $event->test();
                assert($test instanceof Test);
                file_put_contents(
                    $this->path,
                    json_encode([
                        'class' => $test->className(),
                        'method' => $test->methodName(),
                        'message' => $event->message(),
                    ]) . "\n",
                    FILE_APPEND
                );
            }
        });
    }
}
