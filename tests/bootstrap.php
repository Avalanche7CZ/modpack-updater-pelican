<?php

declare(strict_types=1);
require_once __DIR__.'/../bin/autoload.php';
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $f, string $message): void
{
    try {
        $f();
    } catch (Exception $e) {
        return;
    } throw new RuntimeException($message);
}
function config(?string $key = null, mixed $default = null): mixed
{
    return $GLOBALS['test_config'][$key] ?? $default;
}
