<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Qualification of an existing same-host Forge runtime, never an installer or startup editor. */
final class SameHostDeployment
{
    private ?\Closure $heartbeat = null;

    public const PROTOCOL = 'same-host-pelican-v1';
    public const LAUNCH_FILES = ['run.sh', 'startserver.sh', 'start.sh', 'start_server.sh', 'settings.sh', 'variables.txt',
        'user_jvm_args.txt', 'unix_args.txt', 'forge-auto-install.txt', 'server_starter.conf', 'minecraft_server.jar'];

    public function paths(ManagedServer $server): array
    {
        if ($server->transport !== 'local' || ($server->baseline_provenance['transport'] ?? null) !== 'local' ||
            ($server->target_metadata['prepared']['transport'] ?? null) !== 'local') {
            throw new RuntimeException('Prepare both releases with the approved same-host local backend. Remote deployment remains disabled.');
        }
        if (! function_exists('posix_geteuid') || posix_geteuid() === 0) { throw new RuntimeException('Deployment requires an unprivileged Panel/queue account.'); }
        $entries = (new LocalWorker($server))->configuration();
        $entry = $entries[$server->pelican_server_uuid];
        $id = $server->target_metadata['prepared']['node_operation_id'] ?? '';
        if (! preg_match('/^[a-f0-9]{32}$/D', $id) || $server->prepared_root !== 'local:'.$id) {
            throw new RuntimeException('Target stage identity is missing or changed; prepare the target again.');
        }
        foreach ([$entry['root'], $entry['staging'], $entry['staging'].'/'.$id, $entry['staging'].'/'.$id.'/files'] as $path) {
            if (realpath($path) !== $path || is_link($path) || ! is_dir($path)) { throw new RuntimeException('Same-host volume or retained stage is missing, moved or unsafe.'); }
        }
        if ($entry['root'] === $entry['staging'] || str_starts_with($entry['staging'], $entry['root'].'/') || str_starts_with($entry['root'], $entry['staging'].'/')) { throw new RuntimeException('Private transaction staging must be outside the live volume.'); }
        foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $fields = preg_split('/\s+/', $line);
            $mount = strtr($fields[4] ?? '', ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
            if (str_starts_with($mount, $entry['root'].'/')) { throw new RuntimeException('Nested mounts inside the UUID volume need independently qualified backup/restore coverage; same-host deployment is blocked.'); }
        }
        $parent = $entry['staging'].'/'.$id;
        $status = json_decode($this->read($parent, 'operation.json'), true, flags: JSON_THROW_ON_ERROR);
        $digest = $server->target_metadata['prepared']['manifest_sha256'] ?? '';
        if (($status['state'] ?? '') !== 'completed' || ! preg_match('/^[a-f0-9]{64}$/D', $digest) ||
            ($status['manifest_sha256'] ?? '') !== $digest || $this->hash($parent, 'manifest.json') !== $digest) {
            throw new RuntimeException('Retained target manifest no longer matches its verified preparation receipt.');
        }
        return ['live' => $entry['root'], 'stage' => $parent.'/files', 'transactions' => $entry['staging'], 'manifest_sha256' => $digest];
    }

    public function qualify(ManagedServer $server, array $target, array $plan, ?\Closure $heartbeat = null): array
    {
        $this->heartbeat = $heartbeat;
        $paths = $this->paths($server);
        if (! (app(\App\Services\Servers\ServerConfigurationStructureService::class) instanceof \Arcturus\ModpackManager\Services\Pelican\GuardedServerConfiguration)) { throw new RuntimeException('The per-server Wings deployment guard is not installed. Restart Panel/queue workers after plugin upgrade.'); }
        $pelican = $server->pelicanServer;
        if (! $pelican || $pelican->status !== null || $pelican->isInConflictState()) {
            throw new RuntimeException('Pelican server is unavailable, suspended, transferring or busy.');
        }
        if ($pelican->mounts->isNotEmpty()) { throw new RuntimeException('Additional container mounts can shadow runtime/world files. This workflow qualifies UUID-volume-only servers; review mount backup and runtime coverage separately.'); }
        $texts = []; $files = array_keys($target['files']);
        foreach (self::LAUNCH_FILES as $path) {
            if (str_ends_with($path, '.jar')) { continue; }
            if (isset($target['files'][$path])) { $texts[$path] = $this->read($paths['stage'], $path); }
        }
        $requested = (new RuntimeInspector)->inspect($files, $texts, (string) $pelican->startup, '', null);
        foreach (['minecraft', 'loader', 'loader_version'] as $key) {
            $requested[$key] ??= $target['runtime'][$key] ?? null;
        }
        if (($requested['loader'] ?? '') !== 'forge' || ($requested['minecraft'] ?? '') !== '1.20.1') {
            throw new RuntimeException('Manual same-host deployment currently qualifies Forge 1.20.1 with Java 17. Other runtime migrations require separate qualification.');
        }
        $version = $requested['loader_version'] ?? '';
        if (! preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $version)) { throw new RuntimeException('Target Forge version is unknown; inspect the verified server pack.'); }
        $args = 'libraries/net/minecraftforge/forge/1.20.1-'.$version.'/unix_args.txt';
        $currentArgs = $this->read($paths['live'], $args);
        $launchHashes = [$args => $this->hash($paths['live'], $args)];
        preg_match_all('~libraries/[A-Za-z0-9_./+\-]+\.jar~', $currentArgs, $references);
        if (! $references[0]) { throw new RuntimeException('Forge argument file has no verifiable runtime dependencies. Prepare the exact target Forge runtime outside the live update transaction.'); }
        foreach (array_unique($references[0]) as $jar) { $launchHashes[$jar] = $this->hash($paths['live'], $jar); }
        $log = $this->read($paths['live'], 'logs/latest.log', 262144, true);
        $observed = (new RuntimeInspector)->fromStartupLog($log);
        if (($observed['minecraft'] ?? null) !== '1.20.1' || ($observed['loader_version'] ?? null) !== $version || ($observed['java'] ?? null) !== 17) {
            throw new RuntimeException('Current startup log does not independently confirm target Minecraft/Forge and Java 17. Boot the known-working server, then compare again. A Docker image hint is insufficient.');
        }
        $details = app(PelicanPowerController::class)->details($pelican);
        $uptime = $details['utilization']['uptime'] ?? null;
        $logPath = $this->regular($paths['live'], 'logs/latest.log');
        if (($details['state'] ?? '') !== 'running' || ! is_numeric($uptime) || $uptime <= 0 ||
            filemtime($logPath) < time() - (int) ceil($uptime / 1000) - 30) {
            throw new RuntimeException('Running Wings container uptime must corroborate the current startup log. Start the known-working installation and compare again.');
        }
        if (($details['configuration']['invocation'] ?? null) !== (string) $pelican->startup ||
            ($details['configuration']['container']['image'] ?? null) !== (string) $pelican->image ||
            ! $pelican->updated_at || $pelican->updated_at->timestamp > time() - (int) ceil($uptime / 1000) + 30) {
            throw new RuntimeException('Panel/Wings launch settings changed or cannot be tied to this boot. Boot the known-working installation with its current startup/image settings before comparing.');
        }
        $imageHint = (new RuntimeInspector)->inspect([], [], '', (string) $pelican->image)['java'];
        if ($imageHint !== 17 && ! preg_match('/@sha256:[a-f0-9]{64}$/D', (string) $pelican->image)) {
            throw new RuntimeException('The next boot needs the same verified Java 17 image: use a Java 17 image reference or a pinned digest corroborated by the current startup log.');
        }
        $variables = $this->panelVariables($pelican);
        foreach ($variables as $key => $value) {
            if ((string) ($details['configuration']['environment'][$key] ?? '') !== (string) $value) { throw new RuntimeException('Panel/Wings egg variables differ from this boot; restart the known-working server and compare again.'); }
        }
        $startup = (string) $pelican->startup;
        foreach ($variables as $key => $value) { $startup = str_replace('{{'.$key.'}}', (string) $value, $startup); }
        $launchText = $startup;
        foreach (self::LAUNCH_FILES as $path) {
            if (is_file($paths['live'].'/'.$path)) {
                $launchHashes[$path] = $this->hash($paths['live'], $path);
                if (! str_ends_with($path, '.jar')) { $launchText .= "\n".$this->read($paths['live'], $path); }
            }
        }
        if (! str_contains($launchText, $args) && ! (str_contains($launchText, 'minecraft_server.jar') && isset($launchHashes['forge-auto-install.txt']))) {
            throw new RuntimeException('Active Forge launcher cannot be resolved from the unchanged Pelican startup settings. Review custom launchers explicitly.');
        }
        foreach (ModUpgradePlanner::mutations($plan['changes']) as $change) {
            if (! in_array($change['action'], ['add', 'update', 'remove', 'replace_with_override', 'remove_with_override', 'disable_requires_enforcement'], true)) { continue; }
            $path = $change['path'];
            if (isset($launchHashes[$path])) { throw new RuntimeException('Working runtime/launcher file would change: '.$path.'. Choose Keep local or prepare and qualify a runtime migration separately.'); }
            $this->writableParent($paths['live'], $path);
        }
        // Re-hash all target files: completion alone is not proof that staging remains intact.
        $actual = (new DirectoryInventory)->hashPaths($paths['stage'], array_keys($target['files']), $heartbeat);
        $expected = $target['files']; ksort($expected);
        if ($actual !== $expected) { throw new RuntimeException('Staged files differ from the authenticated target manifest. Prepare the target again.'); }
        $this->requireSpace($paths['live'], $paths['stage'], $plan['changes']);
        app(PelicanBackupManager::class)->requireReady($pelican);
        if (($server->backup_mode ?? 'pelican') !== 'pelican') { throw new RuntimeException('Select the Pelican backup backend for same-host deployment.'); }
        $exclusions = BackupExclusions::normalize($server->backup_excluded_directories ?? []);
        BackupExclusions::requireSafeForWorld($exclusions, $this->read($paths['live'], 'server.properties'));
        if (! is_writable($paths['live']) || ! is_writable($paths['transactions'])) { throw new RuntimeException('The UUID root and private transaction staging require scoped queue-account write access for atomic files and exclusion preservation.'); }
        foreach ($exclusions as $folder) {
            $full = $paths['live'].'/'.$folder;
            if (is_link($full) || (file_exists($full) && (! is_dir($full) || stat($full)['dev'] !== stat($paths['transactions'])['dev']))) {
                throw new RuntimeException('Existing excluded backup folders must be regular directories on the transaction staging filesystem so recovery can preserve them with rename.');
            }
        }
        if (($server->runtime_report['world_path_verified'] ?? null) !== true) { throw new RuntimeException('Verify server.properties and the custom world location before deployment.'); }
        ksort($launchHashes);
        $observed['java_confidence'] = 'verified';
        $observed['loader_confidence'] = 'startup_log_and_runtime_files';
        $observed['runtime_prepared'] = true;
        $identity = $this->panelFingerprint($pelican);
        return ['protocol' => self::PROTOCOL, 'runtime_strategy' => 'verified_installed_runtime_reuse', 'current' => $observed, 'target' => [...$observed, 'runtime_prepared' => true],
            'runtime_sha256' => hash('sha256', json_encode($launchHashes, JSON_THROW_ON_ERROR)), 'panel_sha256' => $identity,
            'manifest_sha256' => $paths['manifest_sha256'], 'launch_hashes' => $launchHashes];
    }

    private function panelVariables(\App\Models\Server $server): array
    {
        $variables = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('egg_variables') && \Illuminate\Support\Facades\Schema::hasTable('server_variables')) {
            foreach ($server->variables as $variable) { $variables[$variable->env_variable] = $variable->server_value ?? $variable->default_value; }
            ksort($variables);
        }
        return $variables;
    }

    public function panelSnapshot(\App\Models\Server $server): array
    {
        $server->unsetRelation('variables');
        return ['startup' => $server->startup, 'image' => $server->image, 'egg_id' => $server->egg_id, 'variables' => $this->panelVariables($server)];
    }

    public function panelFingerprint(\App\Models\Server $server): string
    {
        return hash('sha256', json_encode($this->panelSnapshot($server), JSON_THROW_ON_ERROR));
    }

    public function requireSpace(string $live, string $stage, array $changes): void
    {
        $growth = 0; $largest = 0;
        foreach (ModUpgradePlanner::mutations($changes) as $change) {
            if (! in_array($change['action'], ['add', 'update', 'replace_with_override'], true)) { continue; }
            $size = filesize($this->regular($stage, $change['path']));
            $old = is_file($live.'/'.$change['path']) ? filesize($live.'/'.$change['path']) : 0;
            $growth += max(0, $size - $old); $largest = max($largest, $size);
        }
        $free = disk_free_space($live);
        if ($free === false || $free < $growth + $largest + 1048576) { throw new RuntimeException('Insufficient live-filesystem space for approved growth, atomic temporary copies and the recovery journal. Free space without deleting the locked recovery backup.'); }
    }

    public function regular(string $root, string $path): string
    {
        UpdatePlanner::assertPath($path);
        $full = $root.'/'.$path;
        if (realpath($full) !== $full || is_link($full) || ! is_file($full) || ! is_readable($full)) { throw new RuntimeException('Required regular file is missing, unreadable or a symlink: '.$path); }
        return $full;
    }

    private function hash(string $root, string $path): string
    {
        $this->regular($root, $path);
        $value = (new DirectoryInventory)->hashPaths($root, [$path], $this->heartbeat)[$path] ?? null;
        if (! $value) { throw new RuntimeException('Cannot verify required runtime/stage file: '.$path); }
        return $value;
    }

    public function read(string $root, string $path, int $limit = 1048576, bool $prefix = false): string
    {
        $full = $this->regular($root, $path);
        if (! $prefix && filesize($full) > $limit) { throw new RuntimeException('Runtime metadata is too large: '.$path); }
        $file = fopen($full, 'rb');
        try { $value = fread($file, $limit); } finally { fclose($file); }
        if ($value === false) { throw new RuntimeException('Cannot read runtime metadata: '.$path); }
        return $value;
    }

    private function writableParent(string $root, string $path): void
    {
        UpdatePlanner::assertPath($path);
        $parent = dirname($root.'/'.$path);
        while (! file_exists($parent)) { $parent = dirname($parent); }
        if (realpath($parent) !== $parent || ! is_dir($parent) || ! is_writable($parent) || ! is_executable($parent)) {
            throw new RuntimeException('Update directories need scoped write/traverse access for the unprivileged queue account. Ask the host operator; never run PHP as root.');
        }
        if (fileowner($root) !== posix_geteuid()) {
            $acl = new Process(['/usr/bin/getfacl', '-cpn', '--', $parent]); $acl->setTimeout(5); $acl->run();
            if (! $acl->isSuccessful() || ! preg_match('/^default:user:'.fileowner($root).':rwx$/m', $acl->getOutput()) ||
                ! preg_match('/^default:mask::rwx$/m', $acl->getOutput())) {
                throw new RuntimeException('Managed directories require inherited default ACL write access for the Minecraft volume owner as well as the queue account. Ask the host operator to provision scoped deployment ACLs.');
            }
        }
    }
}
