<?php
/**
 * MODASR BOT — minimal test runner (no external framework needed).
 * Run on the server from the project root:  php tests/run.php
 * Exit code 0 = all green, 1 = at least one failure.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
chdir(dirname(__DIR__));

$GLOBALS['__tests_passed'] = 0;
$GLOBALS['__tests_failed'] = 0;

function ok(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['__tests_passed']++;
        echo "  [PASS] $label\n";
    } else {
        $GLOBALS['__tests_failed']++;
        echo "  [FAIL] $label\n";
    }
}

function section(string $name): void
{
    echo "\n== $name ==\n";
}

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $testFile) {
    require $testFile;
}

echo "\n----------------------------------------\n";
echo "Passed: {$GLOBALS['__tests_passed']}  Failed: {$GLOBALS['__tests_failed']}\n";
exit($GLOBALS['__tests_failed'] > 0 ? 1 : 0);
