<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';
require_once __DIR__ . '/Assertions.php';
require_once __DIR__ . '/SkippedTest.php';
require_once __DIR__ . '/TestFixtures.php';

use Smstark\Backtester\Tests\SkippedTest;

$passed = 0;
$failed = 0;
$skipped = 0;

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    if (basename($file) === 'SkippedTest.php') {
        continue;
    }
    $tests = require $file;
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
            echo "PASS  $name\n";
        } catch (SkippedTest $skip) {
            $skipped++;
            echo "SKIP  $name ({$skip->getMessage()})\n";
        } catch (Throwable $error) {
            $failed++;
            echo "FAIL  $name\n      {$error->getMessage()}\n";
        }
    }
}

echo sprintf("\nResult: %d passed, %d failed, %d skipped.\n", $passed, $failed, $skipped);
exit($failed > 0 ? 1 : 0);
