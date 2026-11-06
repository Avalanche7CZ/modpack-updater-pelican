<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Contracts/ServerFiles.php';
require_once __DIR__ . '/../src/Services/UpdatePlanner.php';
require_once __DIR__ . '/../src/Services/RuntimeEvidenceCollector.php';
require_once __DIR__ . '/../src/Services/RuntimeInspector.php';

use Arcturus\ModpackManager\Contracts\ServerFiles;
use Arcturus\ModpackManager\Services\RuntimeEvidenceCollector;
use Arcturus\ModpackManager\Services\RuntimeInspector;

final class OptionalFileFailureFixture implements ServerFiles
{
    public function listing(string $directory = ''): array
    {
        $file = fn (string $name) => ['name' => $name, 'directory' => false, 'file' => true, 'symlink' => false, 'size' => 128];
        $dir = fn (string $name) => ['name' => $name, 'directory' => true, 'file' => false, 'symlink' => false, 'size' => 0];
        return match ($directory) {
            '' => [$file('run.sh'), $file('settings.sh'), $dir('libraries'), $dir('world')],
            'libraries' => [$dir('net')],
            'libraries/net' => [$dir('neoforged')],
            'libraries/net/neoforged' => [$dir('neoforge')],
            'libraries/net/neoforged/neoforge' => [$dir('21.1.100'), $dir('21.1.172')],
            'libraries/net/neoforged/neoforge/21.1.100' => throw new RuntimeException('Fixture old version unavailable'),
            'libraries/net/neoforged/neoforge/21.1.172' => [$file('unix_args.txt')],
            default => throw new RuntimeException('Unexpected directory request '.$directory),
        };
    }

    public function read(string $path, int $limit = 1048576): string
    {
        return match ($path) {
            'run.sh' => 'java @libraries/net/neoforged/neoforge/21.1.172/unix_args.txt',
            'settings.sh' => throw new RuntimeException('Fixture optional config unreadable'),
            'libraries/net/neoforged/neoforge/21.1.172/unix_args.txt' =>
                '--fml.mcVersion 1.21.1 --fml.neoForgeVersion 21.1.172',
            default => throw new RuntimeException('Unexpected file read '.$path),
        };
    }

    public function stat(string $path): ?array { throw new LogicException('No stat needed'); }
    public function write(string $path, string $content): void { throw new LogicException('Read-only test'); }
    public function mkdir(string $path): void { throw new LogicException('Read-only test'); }
    public function rename(string $from, string $to): void { throw new LogicException('Read-only test'); }
    public function delete(string $path): void { throw new LogicException('Read-only test'); }
    public function copy(string $path): void { throw new LogicException('Read-only test'); }
    public function hashes(array $paths): array { throw new LogicException('No hash needed'); }
    public function inventory(): array { throw new LogicException('No inventory needed'); }
    public function capabilities(): array { throw new LogicException('No node worker needed'); }
}

$evidence = (new RuntimeEvidenceCollector)->collect(new OptionalFileFailureFixture());
if (($evidence['complete'] ?? true) !== false || count($evidence['warnings'] ?? []) !== 2) {
    throw new RuntimeException('Optional Wings read failures should return partial evidence with two warnings');
}
if (in_array('settings.sh', array_keys($evidence['texts']), true)) {
    throw new RuntimeException('Unreadable script was erroneously treated as verified evidence');
}
if (in_array('world/level.dat', $evidence['paths'], true)) {
    throw new RuntimeException('Collector traversed world data');
}
$report = (new RuntimeInspector)->inspect(
    $evidence['paths'], $evidence['texts'], 'bash run.sh', 'java_21', 'FTB NeoForge'
);
if (($report['loader'] ?? null) !== 'neoforge' ||
    ($report['loader_version'] ?? null) !== '21.1.172' ||
    ($report['minecraft'] ?? null) !== '1.21.1') {
    throw new RuntimeException('Incomplete optional evidence should still identify active referenced loader');
}
if (($report['java_confidence'] ?? null) === 'verified') {
    throw new RuntimeException('Runtime inspector must not claim verified Java from a Docker image hint');
}
echo "Runtime inspection resilience tests passed\n";
