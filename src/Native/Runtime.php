<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\StartupModificationService;
use Arcturus\ModpackManager\Runtime\RuntimePlan;
use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\RuntimeInspector;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use RuntimeException;

/** Exact staged runtime files, either published or built by the verified isolated installer. */
final class Runtime
{
    public function plan(Server $server, array $current, array $target, array $manifest, string $stage, ?array $buildEvidence = null): array
    {
        foreach (['minecraft', 'loader'] as $key) { if (empty($current[$key]) || empty($target[$key])) { throw new RuntimeException('Installed or target '.$key.' is unknown; inspect the active runtime and verified server pack.'); } }
        if (($current['java_confidence'] ?? '') !== 'verified' || ! is_int($current['java'] ?? null)) { throw new RuntimeException('Actual Java is not verified in the current boot log; an image name is only a hint.'); }
        $minimum = max(RuntimePlan::minimumJava($target['minecraft']), (int) ($target['java'] ?? 0));
        $java = $current['java'] >= $minimum ? $current['java'] : $minimum;
        $image = $server->image;
        if ($java !== $current['java']) {
            $matches = array_values(array_filter(array_values($server->egg?->docker_images ?? []), fn ($value) => is_string($value) && preg_match('/(?:java|jdk)[_:.-]?'.$java.'(?:[^0-9]|$)/i', $value)));
            if (count(array_unique($matches)) !== 1) { throw new RuntimeException('Select one supported Java '.$java.' image in the server egg; no unapproved Docker image will be selected.'); }
            $image = $matches[0];
        }
        $same = true;
        foreach (['minecraft', 'loader', 'loader_version'] as $key) { if (($current[$key] ?? null) !== ($target[$key] ?? null)) { $same = false; } }
        $startup = $server->startup;
        $proof = [];
        if (! $same) {
            if (! in_array($target['loader'], ['forge', 'neoforge'], true) || $target['loader'] !== $current['loader'] || empty($target['loader_version'])) {
                throw new RuntimeException('This runtime migration is not qualified: only verified complete Forge/NeoForge runtimes of the same loader are supported. Existing Fabric/Quilt/Crucible runtimes may be reused.');
            }
            $version = $target['loader_version'];
            if (! preg_match('/^[0-9][A-Za-z0-9_.+-]{0,100}$/D', $version)) { throw new RuntimeException('Target loader coordinate is invalid.'); }
            $coordinate = $target['loader'] === 'forge' ? 'minecraftforge/forge/'.$target['minecraft'].'-'.$version : 'neoforged/neoforge/'.$version;
            $args = 'libraries/net/'.$coordinate.'/unix_args.txt';
            if (! isset($manifest[$args])) { throw new RuntimeException($target['preparation_blocker'] ?? 'The target runtime has no verified '.$args.'. Prepare the target again to build its official installer runtime.'); }
            if ((new DirectoryInventory)->hashPaths($stage, [$args]) !== [$args => $manifest[$args]]) { throw new RuntimeException('Target argument file failed integrity verification.'); }
            $text = file_get_contents($stage.'/'.$args);
            if (strlen($text) > 1048576 || str_contains($text, '${') || str_contains($text, '{{') || preg_match('/@[A-Za-z0-9_.\/+-]+/', $text)) { throw new RuntimeException('Target argument file contains unresolved or nested dependencies; runtime migration is blocked.'); }
            $declared = (new RuntimeInspector)->fromStartupLog($text);
            foreach (['minecraft', 'loader', 'loader_version'] as $key) { if ($declared[$key] !== ($target[$key] ?? null)) { throw new RuntimeException('Target argument file does not prove the declared '.$key.'.'); } }
            preg_match_all('~libraries/[A-Za-z0-9_./+-]+\.jar~', $text, $matches);
            $dependencies = array_values(array_unique($matches[0]));
            if ($dependencies === []) { throw new RuntimeException('Target runtime argument file declares no verifiable libraries.'); }
            // Bootstrap classpaths do not necessarily name the patched server JAR.
            $core = 'libraries/net/'.$coordinate.'/'.($target['loader'] === 'forge' ? 'forge-'.$target['minecraft'].'-'.$version : 'neoforge-'.$version).'-server.jar';
            foreach ([$args, $core, ...$dependencies] as $path) {
                if (! isset($manifest[$path])) { throw new RuntimeException($target['preparation_blocker'] ?? 'Target runtime dependency is absent from the verified stage: '.$path.'. Prepare the target again.'); }
                $proof[$path] = $manifest[$path];
            }
            $actual = (new DirectoryInventory)->hashPaths($stage, array_keys($proof)); $expected = $proof; ksort($expected);
            if ($actual !== $expected) { throw new RuntimeException('Target runtime dependencies do not match their verified manifest.'); }
            $startup = self::switchArguments($startup, $args);
        }
        if ($buildEvidence !== null) {
            if (($buildEvidence['builder'] ?? '') !== \Arcturus\ModpackManager\Runtime\RuntimeBuild::SCHEMA || ! is_array($buildEvidence['files'] ?? null)) { throw new RuntimeException('Runtime build evidence is invalid.'); }
            foreach ($buildEvidence['files'] as $path => $hash) {
                if (! str_starts_with($path, 'libraries/') || ($manifest[$path] ?? null) !== $hash) { throw new RuntimeException('Generated runtime is outside its verified target manifest.'); }
                $proof[$path] = $hash;
            }
        }
        return ['strategy' => $same ? 'verified_installed_runtime_reuse' : ($buildEvidence ? 'verified_installer_runtime_migration' : 'verified_published_runtime_migration'),
            'target' => [...$target, 'java' => $java, 'runtime_prepared' => true], 'startup' => $startup, 'image' => $image, 'dependency_hashes' => $proof];
    }

    /** Called only after exact target identity and all runtime dependency hashes are verified. */
    public static function switchArguments(string $startup, string $args): string
    {
        \Arcturus\ModpackManager\Services\UpdatePlanner::assertPath($args);
        if (! preg_match('~^libraries/net/(?:minecraftforge/forge|neoforged/neoforge)/[A-Za-z0-9_.+-]+/unix_args\.txt$~D', $args)) {
            throw new RuntimeException('Target runtime argument coordinate is unsupported.');
        }
        if (! preg_match('/^java[ \t]+/', $startup) || preg_match('/[;&|<>`$\r\n]/', $startup)) {
            throw new RuntimeException('Runtime migration requires a direct Java launcher without shell commands. Custom script migrations remain blocked.');
        }
        $pattern = '~(?<!\S)@(?:\./)?(?:unix_args\.txt|libraries/net/(?:minecraftforge/forge|neoforged/neoforge)/[A-Za-z0-9_.+-]+/unix_args\.txt)(?=\s|$)~';
        $next = preg_replace($pattern, '@'.$args, $startup, -1, $count);
        if ($count !== 1) { throw new RuntimeException('Runtime migration requires exactly one unquoted @unix_args.txt or @libraries launcher reference. Compare the active startup command.'); }
        return $next;
    }

    public function syncAndRequire(Server $server, array $expected): void
    {
        $power = app(\Arcturus\ModpackManager\Services\PelicanPowerController::class);
        $power->sync($server); $until = microtime(true) + 15;
        do {
            $configuration = $power->details($server)['configuration'] ?? [];
            if (($configuration['invocation'] ?? null) === $expected['startup'] && ($configuration['container']['image'] ?? null) === $expected['image']) { return; }
            sleep(1);
        } while (microtime(true) < $until);
        throw new RuntimeException('Wings did not acknowledge the exact approved startup and Docker image. Keep Minecraft stopped.');
    }

    public function installSettings(Server $server, array $original, array $plan): array
    {
        $this->requireSnapshot($server, $original);
        $expected = [...$original, 'startup' => $plan['startup'], 'image' => $plan['image']];
        app(StartupModificationService::class)->setUserLevel(User::USER_LEVEL_ADMIN)->handle($server,
            ['startup' => $plan['startup'], 'docker_image' => $plan['image'], 'skip_scripts' => (bool) $server->skip_scripts]);
        $this->requireSnapshot($server->refresh(), $expected);
        return $expected;
    }

    public function restoreSettings(Server $server, array $original, array $expected): void
    {
        $actual = app(SameHostDeployment::class)->panelSnapshot($server->refresh());
        if ($actual === $original) { return; }
        if ($actual !== $expected) { throw new RuntimeException('Startup/image/variables changed outside the approved transaction. Keep Minecraft stopped and review settings manually.'); }
        app(StartupModificationService::class)->setUserLevel(User::USER_LEVEL_ADMIN)->handle($server,
            ['startup' => $original['startup'], 'docker_image' => $original['image'], 'skip_scripts' => (bool) $server->skip_scripts]);
        $this->requireSnapshot($server->refresh(), $original);
    }

    public function requireSnapshot(Server $server, array $expected): void
    {
        if (app(SameHostDeployment::class)->panelSnapshot($server->refresh()) !== $expected) { throw new RuntimeException('Pelican startup, image, egg or variables drifted from the approved snapshot.'); }
    }
}
