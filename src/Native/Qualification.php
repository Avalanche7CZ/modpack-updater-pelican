<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\ManifestComparison;
use Arcturus\ModpackManager\Services\PelicanBackupManager;
use Arcturus\ModpackManager\Services\RuntimeInspector;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use RuntimeException;

final class Qualification
{
    public const PROTOCOL = 'native-wings-pelican-v1';

    public function stage(ManagedServer $server): string
    {
        $prepared = $server->target_metadata['prepared'] ?? [];
        if (($prepared['transport'] ?? '') !== 'native' || $server->prepared_root !== 'native:'.($prepared['node_operation_id'] ?? '')) {
            throw new RuntimeException('Prepare this target with Native Pelican/Wings. Existing verified upstream baselines and protection policies can be retained.');
        }
        return (new Preparation($server))->stage($prepared['node_operation_id'], $prepared['manifest_sha256']);
    }

    public function qualify(ManagedServer $server, array $target, array $plan, ?\Closure $heartbeat = null, array $base = []): array
    {
        if (! app(\App\Services\Servers\ServerConfigurationStructureService::class) instanceof \Arcturus\ModpackManager\Services\Pelican\GuardedServerConfiguration) { throw new RuntimeException('Restart Panel/queue workers after plugin update; the native deployment guard is not bound.'); }
        $pelican = $server->pelicanServer;
        if ($pelican->mounts()->exists() || ($pelican->egg?->file_denylist ?? []) !== []) { throw new RuntimeException('Native recovery requires a volume without additional mounts or egg filesystem denylist entries. Review those settings before enabling updates.'); }
        $files = new Wings($pelican, app(DaemonFileRepository::class), $heartbeat);
        $excluded = BackupExclusions::normalize($server->backup_excluded_directories ?? BackupExclusions::DEFAULT_FOLDERS);
        $properties = $files->read('server.properties');
        if (($server->file_inventory['properties_sha256'] ?? null) !== hash('sha256', $properties)) { throw new RuntimeException('server.properties changed during comparison; inspect the world path and compare again.'); }
        BackupExclusions::requireSafeForWorld($excluded, $properties);
        foreach ($files->listing() as $row) {
            if ($row['symlink'] && $row['name'] !== 'unix_args.txt') { throw new RuntimeException('Native full recovery cannot recreate the root symlink '.$row['name'].'. Keep this server on the legacy backend until symlink-preserving native recovery is qualified.'); }
            if (in_array($row['name'], $excluded, true) && ! $row['directory']) { throw new RuntimeException('An excluded backup folder is not a directory.'); }
        }
        foreach ($target['files'] as $path => $hash) { foreach ($excluded as $folder) { if ($path === $folder || str_starts_with($path, $folder.'/')) { throw new RuntimeException('A backup exclusion is owned by the target upstream manifest.'); } } }
        $stage = $this->stage($server);
        $paths = ManifestComparison::installs($plan['changes']);
        $hashes = (new DirectoryInventory)->hashPaths($stage, $paths, $heartbeat);
        foreach ($paths as $path) { if (($hashes[$path] ?? null) !== ($target['files'][$path] ?? null)) { throw new RuntimeException('Native staged install does not match the verified manifest: '.$path); } }
        if (in_array('server.properties', $paths, true) && \Arcturus\ModpackManager\Services\SafetyPaths::roots(file_get_contents($stage.'/server.properties')) !== \Arcturus\ModpackManager\Services\SafetyPaths::roots($properties)) {
            throw new RuntimeException('Changing the world directory through a modpack update is not supported. Keep local server.properties and review the world migration separately.');
        }
        $scope = Transaction::scope($plan['changes']);
        foreach ($scope['moves'] as $from => $to) {
            if ($files->stat($to) !== null) { throw new RuntimeException('Disabled mod destination already exists: '.$to.'. Review it in Pelican Files and compare again.'); }
        }
        $details = app(\Arcturus\ModpackManager\Services\PelicanPowerController::class)->details($pelican);
        $uptime = $details['utilization']['uptime'] ?? null;
        $modified = $files->stat('logs/latest.log')['modified'] ?? null;
        if (($details['state'] ?? '') !== 'running' || ! is_numeric($uptime) || $uptime <= 0 || ! is_string($modified) || strtotime($modified) === false ||
            strtotime($modified) < time() - (int) ceil($uptime / 1000) - 30 ||
            ($details['configuration']['invocation'] ?? null) !== $pelican->startup ||
            ($details['configuration']['container']['image'] ?? null) !== $pelican->image ||
            ! $pelican->updated_at || $pelican->updated_at->timestamp > time() - (int) ceil($uptime / 1000) + 30) {
            throw new RuntimeException('Boot the original installation with its current Panel startup/image, then compare. Wings uptime and the log must corroborate that boot.');
        }
        $current = [...($server->runtime_report ?? []), 'loader_confidence' => 'verified_boot', 'startup' => $pelican->startup, 'image' => $pelican->image];
        $boot = (new RuntimeInspector)->fromStartupLog($files->read('logs/latest.log', 4194304));
        if ($boot['minecraft'] === null || $boot['loader'] === null || ($boot['loader_version'] === null && $boot['loader'] !== 'crucible')) { throw new RuntimeException('The current boot log does not prove the active Minecraft and loader runtime.'); }
        foreach (['minecraft', 'loader', 'loader_version'] as $key) { $current[$key] = $boot[$key]; }
        if ($boot['java'] !== null) { $current['java'] = $boot['java']; $current['java_confidence'] = 'verified'; }
        else { $current['java_confidence'] = 'unknown'; }
        $texts = [];
        $runtimePaths = array_keys($target['files']);
        foreach ($runtimePaths as $path) {
            if (! in_array($path, SameHostDeployment::LAUNCH_FILES, true) && ! str_ends_with($path, '/unix_args.txt')) { continue; }
            if (str_ends_with($path, '.jar')) { continue; }
            if (count($texts) >= 64) { throw new RuntimeException('Too many target launch files; select an unambiguous server pack.'); }
            if ((new DirectoryInventory)->hashPaths($stage, [$path]) !== [$path => $target['files'][$path]] || filesize($stage.'/'.$path) > 1048576) { throw new RuntimeException('Target launch evidence failed integrity or size verification.'); }
            $texts[$path] = file_get_contents($stage.'/'.$path);
        }
        $requested = (new RuntimeInspector)->inspect($runtimePaths, $texts, '', '');
        $requested = array_replace($requested, array_filter($target['runtime'] ?? [], fn ($v) => $v !== null));
        if ($server->keep_existing_crucible_runtime) {
            $runtime = (new CrucibleRuntime)->plan($pelican, $files, $current, $requested, $base, $target, $scope['after']);
        } else {
            if ($current['loader'] === 'crucible' && CrucibleRuntime::matchesUpstream($base) && CrucibleRuntime::matchesUpstream($target)) {
                throw new RuntimeException('GTNH ships a Forge launcher, but this server is running Crucible. Select Keep existing Crucible runtime to compare without migrating the launcher.');
            }
            $runtime = (new Runtime)->plan($pelican, $current, $requested, $target['files'], $stage, $target['runtime_build'] ?? null);
        }
        $willInstall = array_flip($paths);
        foreach ($runtime['dependency_hashes'] as $path => $hash) {
            if (! isset($willInstall[$path]) && $files->hash($path) !== $hash) { throw new RuntimeException('A required target runtime dependency is protected, absent or locally different: '.$path.'. Resolve its policy before migrating.'); }
        }
        app(PelicanBackupManager::class)->requireReady($pelican);
        $snapshot = app(SameHostDeployment::class)->panelSnapshot($pelican);
        return ['protocol' => self::PROTOCOL, 'runtime_strategy' => $runtime['strategy'], 'current' => $current, 'target' => $runtime['target'],
            'runtime_plan' => $runtime, 'panel_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'properties_sha256' => hash('sha256', $properties), 'staged_hashes' => $hashes, 'disabled_mod_moves' => $scope['moves']];
    }
}
