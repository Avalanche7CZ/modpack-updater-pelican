<?php

declare(strict_types=1);
spl_autoload_register(function (string $class): void {
    $prefix = 'Arcturus\\ModpackManager\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
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
