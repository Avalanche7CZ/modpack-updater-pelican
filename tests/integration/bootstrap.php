<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

register_shutdown_function(function (): void {
    if (! ($GLOBALS['arcturus_integration_passed'] ?? false)) {
        fwrite(STDERR, "Integration suite did not reach its completion marker\n");
        exit(1);
    }
});
$panelSource = getenv('PELICAN_SOURCE');
if (! $panelSource || ! is_file($panelSource.'/vendor/autoload.php')) {
    throw new RuntimeException('Set PELICAN_SOURCE to a disposable rc.1 checkout with composer dependencies installed');
}
// No operator .env or existing database is read. This script only accepts a disposable source checkout.
if (is_file($panelSource.'/.env')) {
    throw new RuntimeException('Refusing a Panel checkout with an existing .env; use a disposable checkout');
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
    'APP_URL' => 'http://localhost', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'APP_DEBUG' => 'true'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
}
require $panelSource.'/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'Arcturus\\ModpackManager\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__, 2).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
$app = require $panelSource.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
});
config(['cache.stores.array' => ['driver' => 'array'], 'cache.default' => 'array']);
config(['arcturus-modpack-manager' => require dirname(__DIR__, 2).'/config/arcturus-modpack-manager.php']);
function check(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $f, string $message): void
{
    try {
        $f();
    } catch (Exception $error) {
        return;
    } throw new RuntimeException($message);
}

return $app;
