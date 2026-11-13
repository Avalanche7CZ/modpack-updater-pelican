<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Native\CrucibleRuntime;

$startup = rtrim(file_get_contents(__DIR__.'/fixtures/gtnh-crucible/startup.txt'), "\r\n");
$expected = ['Crucible-1.7.10-server.jar', ['java9args.txt']];
check(CrucibleRuntime::launcher($startup) === $expected, 'The configured GTNH startup was rejected');
check(CrucibleRuntime::launcher(str_replace('mkdir -p logs; ', '', $startup)) === $expected, 'exec java was rejected');
check(CrucibleRuntime::launcher('java -Xms4G -jar @java9args.txt -jar Crucible-1.7.10-server.jar nogui') === $expected, 'The legacy direct launcher was rejected');

foreach ([
    str_replace('mkdir -p logs', 'mkdir -p logs/subdir', $startup),
    str_replace('mkdir -p logs', 'mkdir -p logs; echo done', $startup),
    str_replace('mkdir -p logs;', 'mkdir -p logs &&', $startup),
    str_replace('logs/gc.log', 'logs/../gc.log', $startup),
    str_replace('logs/gc.log', '/tmp/gc.log', $startup),
    str_replace('logs/gc.log', 'logs/*.log', $startup),
    str_replace('gc*', 'class*', $startup),
    str_replace('-Xms4G', '-Dtest=*', $startup),
    str_replace('-Xms4G', '-cp libraries/*', $startup),
    str_replace('-Xms4G', '-javaagent:agent.jar', $startup),
    str_replace('@java9args.txt', '@../java9args.txt', $startup),
    $startup.'; echo done',
] as $invalid) {
    try {
        CrucibleRuntime::launcher($invalid);
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'Panel startup'), 'Startup failure did not name its source');
        continue;
    }
    throw new RuntimeException('An unsupported launcher was accepted: '.$invalid);
}

echo "GTNH Crucible startup, GC logging and rejection of unsupported commands passed\n";
