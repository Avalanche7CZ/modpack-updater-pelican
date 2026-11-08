<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Native\Files;
use Arcturus\ModpackManager\Native\Transaction;
use Arcturus\ModpackManager\Native\Recovery;

// Disposable in-memory Wings model, not a live-Wings qualification.
final class NativeFixture implements Files
{
    public array $data = ['config/a.cfg' => 'old', 'mods/custom.jar' => 'custom', 'world/region/r.0.0.mca' => 'world', 'backups/archive.zip' => 'archive'];
    public array $directories = ['config', 'mods', 'world', 'world/region', 'backups'];
    public array $hashes = [];
    public ?string $fail = null;
    public array $modes = ['config/a.cfg' => 0755];
    public function permissions(string $path, ?int $mode = null): ?int { if (! isset($this->data[$path])) { return null; } if ($mode !== null) { $this->modes[$path] = $mode; } return $this->modes[$path] ?? 0644; }
    public function listing(string $directory = ''): array {
        $prefix = $directory === '' ? '' : $directory.'/'; $rows = [];
        foreach ([...array_keys($this->data), ...$this->directories] as $path) {
            if (! str_starts_with($path, $prefix)) { continue; } $name = substr($path, strlen($prefix));
            if ($name === '' || str_contains($name, '/')) { continue; }
            $rows[$name] = ['name' => $name, 'file' => isset($this->data[$path]), 'directory' => in_array($path, $this->directories, true), 'symlink' => false];
        }
        return array_values($rows);
    }
    public function stat(string $path): ?array { return isset($this->data[$path]) || in_array($path, $this->directories, true) ? ['name' => basename($path)] : null; }
    public function hash(string $path): ?string { $this->hashes[] = $path; return isset($this->data[$path]) ? hash('sha256', $this->data[$path]) : null; }
    public function upload(string $localFile, string $path, string $sha256): void { $this->data[$path] = file_get_contents($localFile); check($this->hash($path) === $sha256, 'Upload mismatch'); }
    public function mkdir(string $path): void { $parts = explode('/', $path); $parent = ''; foreach ($parts as $part) { $parent = $parent === '' ? $part : $parent.'/'.$part; if (! in_array($parent, $this->directories)) { $this->directories[] = $parent; } } }
    public function rename(string $from, string $to): void {
        if ($this->fail === $to) { throw new RuntimeException('Injected partial write failure'); }
        $this->data[$to] = $this->data[$from]; $this->modes[$to] = $this->modes[$from] ?? 0644; unset($this->data[$from], $this->modes[$from]);
    }
    public function delete(string $path): void {
        if ($this->fail === $path) { return; } // Model Wings denylist no-op/204.
        foreach (array_keys($this->data) as $p) { if ($p === $path || str_starts_with($p, $path.'/')) { unset($this->data[$p]); } }
        $this->directories = array_values(array_filter($this->directories, fn ($p) => $p !== $path && ! str_starts_with($p, $path.'/')));
    }
}
$stage = sys_get_temp_dir().'/arcturus-native-'.bin2hex(random_bytes(8)); mkdir($stage); mkdir($stage.'/config');
file_put_contents($stage.'/config/a.cfg', 'new'); file_put_contents($stage.'/config/b.cfg', 'added');
try {
    $files = new NativeFixture; $tx = new Transaction($files); $journal = [];
    $before = ['config/a.cfg' => hash('sha256', 'old'), 'config/b.cfg' => null];
    $after = ['config/a.cfg' => hash('sha256', 'new'), 'config/b.cfg' => hash('sha256', 'added')];
    $log = function ($row) use (&$journal) { $journal[] = $row; };
    $tx->apply(str_repeat('a', 32), $stage, $before, $after, [], $log);
    check($files->permissions('config/a.cfg') === 0755, 'Replacement lost existing executable permission bits');
    check($files->data['config/a.cfg'] === 'new' && $files->data['config/b.cfg'] === 'added', 'Native changes were not installed');
    check($files->data['mods/custom.jar'] === 'custom' && $files->data['world/region/r.0.0.mca'] === 'world', 'Custom/world data changed');
    check(! array_filter($files->hashes, fn ($p) => str_starts_with($p, 'world/')), 'World data entered hashing scope');
    check(end($journal)['step'] === 'files_verified', 'Durable completion checkpoint missing');
    $files = new NativeFixture; $snapshot = $files->data; $files->fail = 'config/b.cfg';
    rejects(fn () => (new Transaction($files))->apply(str_repeat('b', 32), $stage, $before, $after, [], $log), 'Partial file failure went unreported');
    check($files->permissions('config/a.cfg') === 0755, 'Replacement lost existing executable permission bits');
    check($files->data['config/a.cfg'] === 'new', 'Fixture did not exercise a partial deployment');
    $authorized = 0; $files->fail = null;
    (new Recovery($files))->clearForRestore(['backups'], function () use (&$authorized) { $authorized++; }, $log);
    check($files->data === ['backups/archive.zip' => 'archive'] && $authorized >= 1, 'Selective clear failed to preserve excluded backup folder');
    // Simulated native backup restore truncate=false. Existing excluded directories survive.
    $files->data = [...$files->data, ...array_diff_key($snapshot, ['backups/archive.zip' => true])];
    (new Transaction($files))->verify($before);
    check($files->data == $snapshot, 'Exact original files were not recovered');
    $files = new NativeFixture; $files->fail = 'world';
    rejects(fn () => (new Recovery($files))->clearForRestore(['backups'], fn () => null, $log), 'Incomplete clear must prevent overlay restore/start');
    $files = new NativeFixture;
    rejects(fn () => (new Transaction($files))->apply(str_repeat('c', 32), $stage, ['world/region/r.0.0.mca' => hash('sha256', 'world')], ['world/region/r.0.0.mca' => null], [], $log), 'Protected world entered an apply plan');
    $files = new NativeFixture;
    $change = ['path' => 'mods/custom.jar', 'action' => 'disable_requires_enforcement', 'local_sha256' => hash('sha256', 'custom'), 'target_sha256' => null];
    $scope = Transaction::scope([$change]);
    (new Transaction($files))->apply(str_repeat('d', 32), $stage, $scope['before'], $scope['after'], [], $log, $scope['moves']);
    check(! isset($files->data['mods/custom.jar']) && $files->data['mods/custom.jar.disabled'] === 'custom', 'Native disable did not preserve exact original bytes');
    check(in_array('disable_verified', array_column($journal, 'step')), 'Disabled-mod checkpoint missing');
    $files = new NativeFixture; $files->data['mods/custom.jar.disabled'] = 'existing disabled'; $snapshot = $files->data;
    rejects(fn () => (new Transaction($files))->apply(str_repeat('e', 32), $stage, $scope['before'], $scope['after'], [], $log, $scope['moves']), 'Existing disabled destination was overwritten');
    check($files->data === $snapshot, 'Collision changed live data');
    rejects(fn () => Transaction::scope([$change, [...$change, 'path' => 'mods/custom.jar.disabled', 'action' => 'remove']]), 'Overlapping disable and remove accepted');
    rejects(fn () => Transaction::scope([[...$change, 'path' => 'config/custom.jar']]), 'Non-mod disable accepted');
    $files = new NativeFixture; $files->fail = 'mods/custom.jar.disabled';
    rejects(fn () => (new Transaction($files))->apply(str_repeat('f', 32), $stage, $scope['before'], $scope['after'], [], $log, $scope['moves']), 'Failed disable went unreported');
    check($files->data['mods/custom.jar'] === 'custom', 'Failed rename lost original mod');
    echo "Native ordered file deployment, partial failure and exclusion-preserving recovery fixtures passed\n";
} finally { unlink($stage.'/config/a.cfg'); unlink($stage.'/config/b.cfg'); rmdir($stage.'/config'); rmdir($stage); }
