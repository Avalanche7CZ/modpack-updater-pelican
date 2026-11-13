<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Native;

use App\Models\Server;
use Arcturus\ModpackManager\Modpacks\Providers\GtnhProvider;
use RuntimeException;
use ZipArchive;

/** Explicit pack-only update of a running GTNH 1.7.10 Crucible installation. */
final class CrucibleRuntime
{
    public const STRATEGY = 'verified_gtnh_crucible_runtime_reuse';
    private const MINECRAFT = '1.7.10';
    private const FORGE = '10.13.4.1614';
    private const FORGE_JAR = 'forge-1.7.10-10.13.4.1614-1.7.10-universal.jar';

    public static function matchesUpstream(array $manifest): bool
    {
        return ($manifest['provider'] ?? null) === GtnhProvider::SOURCE && ($manifest['project'] ?? null) === GtnhProvider::SOURCE
            && ($manifest['runtime']['minecraft'] ?? null) === self::MINECRAFT
            && isset($manifest['files'][self::FORGE_JAR])
            && ($manifest['runtime']['loader'] ?? 'forge') === 'forge'
            && ($manifest['runtime']['loader_version'] ?? self::FORGE) === self::FORGE;
    }

    public function plan(Server $server, Wings $files, array $current, array $requested, array $base, array $target, array $mutations): array
    {
        if (($current['loader_confidence'] ?? '') !== 'verified_boot' || ($current['loader'] ?? null) !== 'crucible' ||
            ($current['minecraft'] ?? null) !== self::MINECRAFT || ! self::matchesUpstream($base) || ! self::matchesUpstream($target) ||
            ($requested['loader'] ?? null) !== 'forge' || ($requested['loader_version'] ?? null) !== self::FORGE ||
            ($requested['minecraft'] ?? null) !== self::MINECRAFT || isset($target['runtime_build'])) {
            throw new RuntimeException('Keeping Crucible requires a verified running Crucible 1.7.10 server and official GTNH baseline and target packs with the same Forge 10.13.4.1614 runtime.');
        }

        $java = $current['java'] ?? null;
        $minimum = $requested['java'] ?? null;
        $maximum = $requested['java_max'] ?? null;
        if (($current['java_confidence'] ?? '') !== 'verified' || ! is_int($java) || ! is_int($minimum) || ! is_int($maximum)
            || $java < $minimum || $java > $maximum) {
            throw new RuntimeException('The verified Java version must be within the selected GTNH server ZIP’s Java range to keep Crucible.');
        }

        [$jar, $argFiles] = self::launcher($server->startup);
        $protected = [$jar, ...$argFiles, self::FORGE_JAR, 'lwjgl3ify-forgePatches.jar', 'minecraft_server.1.7.10.jar'];
        foreach (array_keys($mutations) as $path) {
            if (in_array($path, $protected, true) || str_starts_with($path, 'libraries/') || $path === 'libraries') {
                throw new RuntimeException('Keeping Crucible conflicts with a planned change to '.$path.'. Keep that runtime path local and compare again.');
            }
        }

        $retained = [$jar => ['sha256' => $this->inspectJar($files, $jar), 'mode' => $files->permissions($jar)]];
        foreach ($argFiles as $path) {
            // Do not expand nested argfiles or accept an argfile that selects a different launcher/classpath.
            $text = $files->read($path, 65536);
            self::checkJvmOptions(self::tokens($text, true));
            $retained[$path] = ['sha256' => hash('sha256', $text), 'mode' => $files->permissions($path)];
        }
        ksort($retained);

        return [
            'strategy' => self::STRATEGY,
            'target' => ['minecraft' => self::MINECRAFT, 'loader' => 'crucible', 'loader_version' => null, 'java' => $java, 'runtime_prepared' => true],
            'upstream_runtime' => array_intersect_key($requested, array_flip(['minecraft', 'loader', 'loader_version', 'java', 'java_max'])),
            'startup' => $server->startup,
            'image' => $server->image,
            'dependency_hashes' => [],
            'retained_launcher_files' => $retained,
        ];
    }

    /** Parse only the direct Java launcher forms we can prove. Never evaluate a command. */
    public static function launcher(string $startup): array
    {
        $tokens = self::tokens($startup);
        if (array_shift($tokens) !== 'java') { throw self::unsupportedLauncher(); }
        $argFiles = []; $jvm = [];
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            // Some Crucible eggs spell the JVM options as `-jar @java9args.txt -jar Crucible...jar`.
            // Allow that form only if the argument file expands to JVM options and a separate JAR follows.
            if ($token === '-jar' && str_starts_with($tokens[$i + 1] ?? '', '@')) {
                $token = $tokens[++$i];
            } elseif ($token === '-jar') {
                $jar = $tokens[++$i] ?? '';
                if (! preg_match('~^(?:\./)?(Crucible(?:-[A-Za-z0-9_.+-]+)?\.jar)$~iD', $jar, $match)
                    || ! in_array(array_slice($tokens, $i + 1), [[], ['nogui'], ['--nogui']], true)) {
                    throw self::unsupportedLauncher();
                }
                self::checkJvmOptions($jvm);
                return [$match[1], $argFiles];
            }
            if (preg_match('~^@(?:\./)?([A-Za-z0-9_][A-Za-z0-9_.-]*\.txt)$~D', $token, $match)) {
                self::checkJvmOptions($jvm);
                $jvm = [];
                if (count($argFiles) >= 4 || in_array($match[1], $argFiles, true)) { throw self::unsupportedLauncher(); }
                $argFiles[] = $match[1];
            } else {
                $jvm[] = $token;
            }
        }

        throw self::unsupportedLauncher();
    }

    private static function tokens(string $text, bool $argFile = false): array
    {
        if ($argFile) { $text = preg_replace('/^[ \t]*#[^\r\n]*/m', '', $text); }
        if (! preg_match($argFile ? '~^[A-Za-z0-9_.,:/=+%\- \t\r\n]*$~D' : '~^[A-Za-z0-9_.,:/=@+%\- \t]+$~D', $text)) {
            throw self::unsupportedLauncher();
        }

        return preg_split('/[ \t\r\n]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    private static function checkJvmOptions(array $tokens): void
    {
        $access = '(?:add-opens|add-exports|add-reads)';
        $package = '[A-Za-z0-9_.]+(?:/[A-Za-z0-9_.]+)?=[A-Za-z0-9_.,-]+';
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (preg_match('/^--'.$access.'$/D', $token)) {
                if (! preg_match('~^'.$package.'$~D', $tokens[++$i] ?? '')) { throw self::unsupportedLauncher(); }
                continue;
            }
            if (! preg_match('~^(?:-D[A-Za-z0-9_.-]+(?:=[A-Za-z0-9_.,+%-]*)?|-X(?:ms|mx|ss)[0-9]+[kKmMgG]?|-XX:[A-Za-z0-9_.,=+%-]+|--illegal-access=(?:warn|permit|deny|debug)|--'.$access.'='.$package.')$~D', $token)) {
                throw self::unsupportedLauncher();
            }
        }
    }

    private static function unsupportedLauncher(): RuntimeException
    {
        return new RuntimeException('Keeping Crucible requires a direct java command with one root Crucible JAR and simple JVM options or root JVM argument files. Inspect the active startup; custom wrappers or classpaths are not qualified.');
    }

    private function inspectJar(Wings $files, string $path): string
    {
        $temp = tmpfile();
        if ($temp === false) { throw new RuntimeException('Cannot inspect the Crucible JAR in private storage.'); }
        $zip = new ZipArchive;
        try {
            $digest = $files->copyCrucibleForInspection($path, $temp);
            if ($zip->open(stream_get_meta_data($temp)['uri'], ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('The active Crucible launcher is not a readable JAR.');
            }
            try {
                $info = $zip->statName('META-INF/MANIFEST.MF');
                $text = $info && $info['size'] <= 65536 ? $zip->getFromIndex($info['index']) : false;
            } finally { $zip->close(); }
            $main = preg_split("/\n\n/", str_replace(["\r\n", "\r"], "\n", $text ?: ''), 2)[0];
            $main = str_replace("\n ", '', $main);
            foreach (['Implementation-Title: Crucible', 'Forge-Version: '.self::FORGE, 'Main-Class: cpw.mods.fml.relauncher.ServerLaunchWrapper'] as $line) {
                [$key] = explode(':', $line, 2);
                if (! in_array($line, explode("\n", $main), true) || preg_match_all('/^'.preg_quote($key, '/').':/mi', $main) !== 1) {
                    throw new RuntimeException('The active Crucible JAR does not prove the expected Crucible / Forge 10.13.4.1614 launcher.');
                }
            }
        } finally { fclose($temp); }

        return $digest;
    }

    public static function verify(Files $files, array $expected): void
    {
        foreach ($expected as $path => $proof) {
            if ($files->hash($path) !== $proof['sha256'] || $files->permissions($path) !== $proof['mode']) {
                throw new RuntimeException('Retained Crucible launcher file drifted: '.$path.'. Inspect the launcher before restarting.');
            }
        }
    }
}
