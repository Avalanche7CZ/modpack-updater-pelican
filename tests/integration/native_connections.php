<?php
declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';

use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Native\Wings;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

$process = new Process(['python3', '-u', __DIR__.'/fixtures/wings_connections.py']);
$process->start();
try {
    $process->waitUntil(fn ($type, $output) => trim($output) !== '');
    $port = (int) trim($process->getOutput());
    check($port > 0, 'Loopback fixture did not start');
    $node = new Node;
    $node->forceFill(['scheme' => 'http', 'fqdn' => '127.0.0.1', 'daemon_connect' => $port,
        'daemon_token' => 'fixture', 'daemon_token_id' => 'fixture']);
    $server = new Server;
    $server->forceFill(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
    $server->setRelation('node', $node);
    $wings = new Wings($server, app(DaemonFileRepository::class));
    for ($i = 0; $i < 20; $i++) {
        check($wings->hash('candidate.jar') === hash('sha256', 'fixture'), 'Real streamed hash differs');
    }
    $stats = Http::get('http://127.0.0.1:'.$port.'/stats')->json();
    check($stats['listings'] === 40, 'Before and after metadata checks were skipped');
    check($stats['connections'] === 1, 'Directory reads did not reuse their TCP connection');
    echo "Real loopback HTTP: 20 verified file streams, 40 metadata reads over one listing connection passed\n";
    $GLOBALS['arcturus_integration_passed'] = true;
} finally {
    $process->stop();
}
