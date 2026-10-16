<?php
declare(strict_types=1);

/**
 * Helpers mínimos para las pruebas (sin framework):
 *
 *     php tests/RouterTest.php
 *
 * Cada archivo de prueba hace require de este y termina con finish().
 */
$GLOBALS['failures'] = 0;

function check(string $name, bool $condition): void
{
    echo ($condition ? 'ok    ' : 'FALLA ') . $name . "\n";
    if (!$condition) {
        $GLOBALS['failures']++;
    }
}

function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}

function finish(): never
{
    echo $GLOBALS['failures'] === 0 ? "\nTodo bien\n" : "\n{$GLOBALS['failures']} falla(s)\n";
    exit($GLOBALS['failures'] === 0 ? 0 : 1);
}
