<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Runtime;

use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Native\Preparation;
use Arcturus\ModpackManager\Native\Wings;
use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\RuntimeInspector;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use RuntimeException;

/** Builds in private scratch before approval. Neither the installer nor PHP receives a live-volume mapping. */
final class RuntimeBuild
{
    public const SCHEMA = 'isolated-official-installer-v1';
    public function __construct(private readonly InstallerArtifact $artifacts, private readonly InstallerSandbox $sandbox) {}

    public function prepare(ManagedServer $server, array $manifest, string $nodeId, string $manifestDigest, ?\Closure $heartbeat = null): array
    {
        $preparation = new Preparation($server); $source = $preparation->stage($nodeId, $manifestDigest);
        $texts = [];
        foreach ($manifest['files'] as $path => $hash) {
            if ((in_array($path, SameHostDeployment::LAUNCH_FILES, true) && ! str_ends_with($path, '.jar')) || str_ends_with($path, '/unix_args.txt')) {
                if (count($texts) >= 64 || filesize($this->regular($source, $path)) > 1048576 || (new DirectoryInventory)->hashPaths($source, [$path]) !== [$path => $hash]) { throw new RuntimeException('Target runtime evidence is excessive or changed.'); }
                $texts[$path] = file_get_contents($source.'/'.$path);
            }
        }
        $target = (new RuntimeInspector)->inspect(array_keys($manifest['files']), $texts, '', '');
        $target = array_replace($target, array_filter($manifest['runtime'] ?? [], fn ($v) => $v !== null));
        if (! in_array($target['loader'] ?? null, ['forge', 'neoforge'], true) || empty($target['minecraft']) || empty($target['loader_version'])) { return ['manifest' => $manifest, 'node_id' => $nodeId, 'manifest_sha256' => $manifestDigest]; }
        $files = new Wings($server->pelicanServer, app(DaemonFileRepository::class), $heartbeat);
        $current = (new RuntimeInspector)->fromStartupLog($files->read('logs/latest.log', 4194304));
        if (($current['loader'] ?? null) === 'crucible' && \Arcturus\ModpackManager\Native\CrucibleRuntime::matchesUpstream($manifest)) {
            // A pack-only update never needs an isolated installation of the bundled legacy Forge runtime.
            // Qualification still requires an opt-in and verifies the active launcher and both manifests.
            return ['manifest' => $manifest, 'node_id' => $nodeId, 'manifest_sha256' => $manifestDigest];
        }
        $same = true; foreach (['minecraft', 'loader', 'loader_version'] as $key) { if ($current[$key] !== $target[$key]) { $same = false; } }
        if ($same) { return ['manifest' => $manifest, 'node_id' => $nodeId, 'manifest_sha256' => $manifestDigest]; }
        $coordinate = $target['loader'] === 'forge' ? 'minecraftforge/forge/'.$target['minecraft'].'-'.$target['loader_version'] : 'neoforged/neoforge/'.$target['loader_version'];
        $args = 'libraries/net/'.$coordinate.'/unix_args.txt';
        $core = 'libraries/net/'.$coordinate.'/'.($target['loader'] === 'forge' ? 'forge-'.$target['minecraft'].'-'.$target['loader_version'] : 'neoforge-'.$target['loader_version']).'-server.jar';
        if (isset($manifest['files'][$args], $manifest['files'][$core])) {
            preg_match_all('~libraries/[A-Za-z0-9_./+-]+\.jar~', $texts[$args] ?? '', $matches);
            if ($matches[0] && ! array_diff($matches[0], array_keys($manifest['files']))) { return ['manifest' => $manifest, 'node_id' => $nodeId, 'manifest_sha256' => $manifestDigest]; }
        }
        $entry = $preparation->configuration()[$server->pelican_server_uuid];
        $cache = dirname($entry['staging']).'/runtime-cache';
        $artifact = $this->artifacts->obtain($target, $cache);
        $profile = (new InstallerProfile)->inspect($artifact, $target);
        $build = $cache.'/'.hash('sha256', self::SCHEMA.' '.$artifact['sha256']);
        $receiptPath = $build.'/runtime.json';
        $receipt = null;
        if (is_link($build) || is_link($receiptPath)) { throw new RuntimeException('Runtime build cache cannot contain links.'); }
        if (is_file($receiptPath) && filesize($receiptPath) <= 4194304) {
            $receipt = json_decode(file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
            if (($receipt['builder'] ?? '') !== self::SCHEMA || ($receipt['installer_sha256'] ?? '') !== $artifact['sha256'] || ($receipt['profile_sha256'] ?? '') !== $profile['profile_sha256']) { throw new RuntimeException('Cached runtime receipt differs from the approved installer.'); }
            if ((new DirectoryInventory)->hashPaths($build.'/files', array_keys($receipt['files']), $heartbeat) !== $receipt['files']) { throw new RuntimeException('Cached generated runtime changed; retain it for review.'); }
        }
        if ($receipt === null) {
            if (file_exists($build)) { throw new RuntimeException('An incomplete runtime build cache exists; retain it for review.'); }
            if (disk_free_space($cache) < 2147483648) { throw new RuntimeException('Private Panel storage needs at least 2 GiB free for installer preparation.'); }
            $available = file_get_contents('/proc/meminfo');
            if (preg_match('/^MemAvailable:\s+([0-9]+) kB$/m', $available ?: '', $memory) && (int) $memory[1] * 1024 < 3221225472) { throw new RuntimeException('Isolated installer preparation needs at least 3 GiB available memory; no live files were changed.'); }
            if (is_file('/sys/fs/cgroup/memory.max') && is_file('/sys/fs/cgroup/memory.current')) {
                $max = trim(file_get_contents('/sys/fs/cgroup/memory.max')); $used = trim(file_get_contents('/sys/fs/cgroup/memory.current'));
                if (ctype_digit($max) && ctype_digit($used) && (int) $max - (int) $used < 3221225472) { throw new RuntimeException('The Panel container has insufficient memory headroom for isolated runtime preparation.'); }
            }
            $work = $cache.'/.building-'.basename($build);
            if (is_link($work) || (file_exists($work) && ! is_dir($work))) { throw new RuntimeException('Retained installer workspace is unsafe.'); }
            if (! is_dir($work) && ! mkdir($work, 0700)) { throw new RuntimeException('Cannot create installer workspace.'); }
            $intent = ['builder' => self::SCHEMA, 'installer_sha256' => $artifact['sha256'], 'profile_sha256' => $profile['profile_sha256']];
            $intentPath = $work.'/.build-input.json';
            if (is_link($intentPath)) { throw new RuntimeException('Installer input receipt cannot be a link.'); }
            if (is_file($intentPath)) { if (json_decode(file_get_contents($intentPath), true, flags: JSON_THROW_ON_ERROR) !== $intent) { throw new RuntimeException('Retained installer input differs from the verified artifact.'); } }
            elseif (file_put_contents($intentPath, json_encode($intent, JSON_THROW_ON_ERROR), LOCK_EX) === false) { throw new RuntimeException('Cannot save installer input receipt.'); }
            $lockPath = $work.'/.build.lock'; if (is_link($lockPath)) { throw new RuntimeException('Installer lock path cannot be a link.'); }
            $lock = fopen($lockPath, 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another isolated build owns this runtime workspace.'); }
            try {
            if (file_exists($work.'/files')) { throw new RuntimeException('Runtime cache publication was interrupted. Retain the private workspace for review before retrying; no live files were changed.'); }
            $major = $this->sandbox->run($artifact, $work, max(RuntimePlan::minimumJava($target['minecraft']), (int) ($target['java'] ?? 0)), $heartbeat);
            $generated = (new DirectoryInventory)->hashTree($work.'/libraries', 20000, 4294967296);
            $hashes = []; foreach ($generated as $path => $hash) { $hashes['libraries/'.$path] = $hash; }
            ksort($hashes);
            if (($hashes[$profile['args']] ?? null) !== $profile['args_sha256'] || ! isset($hashes[$profile['core']])) { throw new RuntimeException('Installer completed without the exact target arguments and generated server runtime.'); }
            $jar = new \ZipArchive;
            if ($jar->open($work.'/'.$profile['core']) !== true) { throw new RuntimeException('Generated server runtime is not a valid JAR.'); }
            $hasClasses = $jar->numFiles > 0; $jar->close();
            if (! $hasClasses) { throw new RuntimeException('Generated server runtime is empty.'); }
            $receipt = ['builder' => self::SCHEMA, 'installer_sha256' => $artifact['sha256'], 'profile_sha256' => $profile['profile_sha256'], 'build_java' => $major, 'files' => $hashes];
            if (! mkdir($work.'/files', 0700) || ! rename($work.'/libraries', $work.'/files/libraries')) { throw new RuntimeException('Cannot retain the verified runtime output.'); }
            $receiptRaw = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($work.'/runtime.json', $receiptRaw, LOCK_EX) !== strlen($receiptRaw)) { throw new RuntimeException('Cannot save complete runtime receipt.'); }
            if (! rename($work, $build)) { throw new RuntimeException('Cannot commit complete runtime build cache.'); }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        $derived = substr(hash('sha256', self::SCHEMA.' '.$manifestDigest.' '.json_encode($receipt, JSON_THROW_ON_ERROR)), 0, 32);
        $parent = $entry['staging'].'/'.$derived;
        $result = $manifest;
        foreach ($receipt['files'] as $path => $hash) {
            if (isset($result['files'][$path]) && $result['files'][$path] !== $hash) { throw new RuntimeException('Published server-pack runtime conflicts with the exact official installer output: '.$path); }
            $result['files'][$path] = $hash;
        }
        ksort($result['files']);
        $result['runtime'] = array_replace($manifest['runtime'] ?? [], array_intersect_key($target, array_flip(['minecraft', 'loader', 'loader_version', 'java'])));
        $result['runtime_build'] = $receipt;
        $result['artifacts'][] = array_intersect_key($artifact, array_flip(['url', 'sha256', 'hashes']));
        $raw = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); $digest = hash('sha256', $raw);
        if (! file_exists($parent)) {
            $temp = $entry['staging'].'/.derived-'.bin2hex(random_bytes(12));
            if (! mkdir($temp, 0700) || ! mkdir($temp.'/files', 0700)) { throw new RuntimeException('Cannot create a private derived target stage.'); }
            foreach ($result['files'] as $path => $hash) {
                $fromRoot = isset($manifest['files'][$path]) ? $source : $build.'/files';
                $from = $this->regular($fromRoot, $path);
                $to = $temp.'/files/'.$path;
                if (! is_dir(dirname($to)) && ! mkdir(dirname($to), 0700, true)) { throw new RuntimeException('Cannot create a derived stage directory.'); }
                if (! link($from, $to) && ! copy($from, $to)) { throw new RuntimeException('Cannot assemble the private runtime stage.'); }
                $heartbeat?->__invoke();
            }
            $completion = json_encode(['state' => 'completed', 'manifest_sha256' => $digest, 'runtime_builder' => self::SCHEMA], JSON_THROW_ON_ERROR);
            if (file_put_contents($temp.'/manifest.json', $raw, LOCK_EX) !== strlen($raw) || file_put_contents($temp.'/operation.json', $completion, LOCK_EX) !== strlen($completion)) { throw new RuntimeException('Cannot save the complete derived stage receipt.'); }
            if (! rename($temp, $parent)) { throw new RuntimeException('Cannot commit the verified derived target stage.'); }
        }
        $preparation->stage($derived, $digest);
        return ['manifest' => $result, 'node_id' => $derived, 'manifest_sha256' => $digest];
    }

    private function regular(string $root, string $path): string
    {
        \Arcturus\ModpackManager\Services\UpdatePlanner::assertPath($path);
        $current = $root;
        foreach (explode('/', $path) as $part) { $current .= '/'.$part; if (is_link($current)) { throw new RuntimeException('Private stage contains a symlink.'); } }
        if (! is_file($current) || realpath($current) !== $current) { throw new RuntimeException('A private runtime stage file is missing or unsafe.'); }
        return $current;
    }
}
