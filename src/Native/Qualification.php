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
        // A current file/configuration plan is independent of whether that installation can boot.
        $current = $this->fileRuntime($server, $files, $base);
        $bootEvidence = $this->liveBootEvidence($server, $files, $current);
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
                throw new RuntimeException('GTNH ships a Forge launcher, but the configured launcher is Crucible. Select Keep existing Crucible runtime to compare without migrating the launcher.');
            }
            $runtime = (new Runtime)->plan($pelican, $current, $requested, $target['files'], $stage, $target['runtime_build'] ?? null);
        }
        $willInstall = array_flip($paths);
        foreach ($runtime['dependency_hashes'] as $path => $hash) {
            if (! isset($willInstall[$path]) && $files->hash($path) !== $hash) { throw new RuntimeException('A required target runtime dependency is protected, absent or locally different: '.$path.'. Resolve its policy before migrating.'); }
        }
        app(PelicanBackupManager::class)->requireReady($pelican);
        $snapshot = app(SameHostDeployment::class)->panelSnapshot($pelican);
        return ['protocol' => self::PROTOCOL, 'boot_evidence' => $bootEvidence, 'runtime_strategy' => $runtime['strategy'], 'current' => $current, 'target' => $runtime['target'],
            'runtime_plan' => $runtime, 'panel_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'properties_sha256' => hash('sha256', $properties), 'staged_hashes' => $hashes, 'disabled_mod_moves' => $scope['moves']];
    }

    /** Automation keeps its previous live-start qualification; manual approval may use file evidence. */
    public static function requireAutomaticBoot(array $qualification): void
    {
        if (($qualification['boot_evidence']['verified'] ?? false) !== true) {
            throw new RuntimeException('Automatic native deployment requires the original server running with current boot evidence. Review and manually approve an offline installation.');
        }
    }

    private function fileRuntime(ManagedServer $server, Wings $files, array $base): array
    {
        $pelican = $server->pelicanServer;
        if ($server->keep_existing_crucible_runtime || preg_match('/Crucible[^\s]*\.jar/i', $pelican->startup)) {
            return (new CrucibleRuntime)->inspect($pelican, $files);
        }
        // Only scoped launcher/configuration candidates; never recursively inspect worlds or follow links.
        $paths = array_keys($server->file_inventory['files'] ?? []); $texts = [];
        foreach (array_unique([...array_keys($base['files']), ...$paths, ...SameHostDeployment::LAUNCH_FILES]) as $path) {
            if (! in_array($path, SameHostDeployment::LAUNCH_FILES, true) && ! str_ends_with($path, '/unix_args.txt')) { continue; }
            if (str_ends_with($path, '.jar')) { continue; }
            $meta = $path === 'unix_args.txt' ? $files->linkMetadata($path) : $files->stat($path);
            if ($meta === null || ($meta['symlink'] ?? false)) { continue; }
            if (count($texts) >= 64) { throw new RuntimeException('Too many current launcher files; the active startup is ambiguous.'); }
            $texts[$path] = $files->read($path, 1048576); $paths[] = $path;
        }
        $current = (new RuntimeInspector)->inspect(array_values(array_unique($paths)), $texts, $pelican->startup, $pelican->image);
        if (empty($current['minecraft']) || empty($current['loader'])) {
            throw new RuntimeException('Cannot identify the current Minecraft/loader from Pelican Startup and scoped launcher files: '.$pelican->startup.'. '.implode('; ', $current['warnings']));
        }
        return $current;
    }

    /** Optional evidence for automation, not a prerequisite of manually approved offline work. */
    private function liveBootEvidence(ManagedServer $server, Wings $files, array $current): array
    {
        $pelican = $server->pelicanServer;
        $details = app(\Arcturus\ModpackManager\Services\PelicanPowerController::class)->details($pelican);
        $state = $details['state'] ?? 'unknown';
        if (! in_array($state, ['running', 'offline', 'exited'], true)) {
            throw new RuntimeException('Wings reports '.$state.'; comparison requires a stable running or stopped server.');
        }
        if ($state !== 'running') { return ['verified' => false]; }
        $uptime = $details['utilization']['uptime'] ?? null;
        if (! is_numeric($uptime) || $uptime <= 0 || ! $pelican->updated_at) { return ['verified' => false]; }
        $started = time() - (int) ceil($uptime / 1000);
        if ($pelican->updated_at->timestamp > $started + 30 ||
            ($details['configuration']['invocation'] ?? null) !== $pelican->startup ||
            ($details['configuration']['container']['image'] ?? null) !== $pelican->image) { return ['verified' => false]; }
        // A launcher replaced after the running instance began cannot borrow its old boot proof.
        foreach ($current['launcher_files'] ?? [] as $path => $proof) {
            $modified = $files->stat($path)['modified'] ?? null;
            if (! is_string($modified) || strtotime($modified) === false || strtotime($modified) > $started) { return ['verified' => false]; }
        }
        $meta = $files->stat('logs/latest.log');
        if ($meta === null || ($meta['size'] ?? 0) > 4194304 || ! is_string($meta['modified'] ?? null) || strtotime($meta['modified']) === false || strtotime($meta['modified']) < $started - 30) { return ['verified' => false]; }
        try { $text = $files->read('logs/latest.log', 4194304); }
        catch (TextChangedDuringInspection) { return ['verified' => false]; }
        $boot = (new RuntimeInspector)->fromStartupLog($text);
        if (! preg_match('/\bDone \([\d.]+s\)!/i', $text)) { return ['verified' => false]; }
        foreach (['minecraft', 'loader', 'loader_version', 'java'] as $key) {
            if (! isset($current[$key])) { continue; }
            if ($boot[$key] === null) { return ['verified' => false]; }
            if ($boot[$key] !== $current[$key]) {
                throw new RuntimeException('Fresh running boot reports '.$key.' '.(string) $boot[$key].' but configured launcher/image declares '.(string) $current[$key].'. Review the actual startup and Java range before comparison.');
            }
        }
        return ['verified' => true];
    }

}
