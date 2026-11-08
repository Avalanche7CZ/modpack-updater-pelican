<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Runtime;

use RuntimeException;

/** Pure command profiles. Native preparation runs installers through InstallerSandbox. */
final class RuntimePlan
{
    public const INSTALLER = 'arcturus-runtime-installer.jar';
    public const PROBE = 'arcturus-runtime-java.txt';
    public const MARKER = 'arcturus-runtime-ready.txt';
    public const SAVED_JVM = 'arcturus-runtime-jvm.saved';
    public const INTERNAL = [self::INSTALLER, self::PROBE, self::MARKER, self::SAVED_JVM];

    public static function minimumJava(string $minecraft): int
    {
        if (preg_match('/^26\.[0-9]+(?:\.[0-9]+)?$/D', $minecraft)) { return 25; }
        if (! preg_match('/^1\.[0-9]+(?:\.[0-9]+)?$/D', $minecraft)) { throw new RuntimeException('Unknown Minecraft version; declare an exact supported runtime.'); }
        if (version_compare($minecraft, '1.20.5', '>=')) { return 21; }
        if (version_compare($minecraft, '1.18', '>=')) { return 17; }
        return version_compare($minecraft, '1.17', '>=') ? 16 : 8;
    }

    public function make(array $current, array $requested, string $startup, string $image, array $images = []): array
    {
        $mc = (string) ($requested['minecraft'] ?? '');
        $loader = strtolower((string) ($requested['loader'] ?? ''));
        $version = (string) ($requested['loader_version'] ?? '');
        if (! in_array($loader, ['forge', 'neoforge', 'fabric', 'quilt', 'vanilla', 'crucible'], true) ||
            ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]{0,100}$/D', $version)) {
            throw new RuntimeException('The target needs an exact supported loader and loader version.');
        }
        $minimum = self::minimumJava($mc);
        $required = (int) ($requested['java'] ?? $minimum);
        if ($required < $minimum || ! in_array($required, [8, 11, 16, 17, 21, 25], true)) { throw new RuntimeException('Invalid or unsupported target Java requirement.'); }
        $sameGame = ($current['minecraft'] ?? null) === $mc;
        $java = $sameGame && ($current['java_confidence'] ?? '') === 'verified' && ($current['java'] ?? 0) >= $required ? (int) $current['java'] : $required;
        $same = $sameGame && ($current['loader'] ?? null) === $loader && ($current['loader_version'] ?? null) === $version &&
            ($current['java_confidence'] ?? '') === 'verified' && ($current['java'] ?? null) === $java;
        $target = ['minecraft' => $mc, 'loader' => $loader, 'loader_version' => $version, 'java' => $java, 'runtime_prepared' => true];
        if ($same && ! ($requested['launcher_changed'] ?? false)) {
            return ['strategy' => 'verified_installed_runtime_reuse', 'target' => $target, 'startup' => $startup, 'image' => $image, 'artifact' => null];
        }
        if ($same && ($requested['published_launcher'] ?? false)) {
            return ['strategy' => 'published_launcher_update', 'target' => $target, 'startup' => $startup, 'image' => $image, 'artifact' => null];
        }
        $probe = '[ ! -L '.self::PROBE.' ] && java -version > '.self::PROBE.' 2>&1 && ';
        if ($loader === 'crucible') {
            if (! $sameGame || ($current['loader'] ?? '') !== 'crucible' || ! preg_match('/^java\s/', $startup)) {
                throw new RuntimeException('Custom Crucible reuse needs the existing direct-Java launcher and unchanged Minecraft.');
            }
            $target['java'] = (int) ($current['java'] ?? $required);
            return ['strategy' => 'known_working_custom_launcher_reuse', 'target' => $target, 'startup' => $probe.'exec '.$startup, 'image' => $image, 'artifact' => null];
        }
        $nextImage = ($current['java'] ?? null) === $java ? $image : ($images[$java] ?? null);
        if (! is_string($nextImage) || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/@+-]{0,254}$~D', $nextImage)) {
            throw new RuntimeException('The server needs a verified Java '.$java.' Docker image supported by its Pelican configuration.');
        }
        $flags = '-Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true';
        if (preg_match('/^java\s+((?:(?:-[A-Za-z0-9_.:=+,%\/{}-]+|\{\{[A-Z0-9_]+\}\})\s+)+)(?:@|-jar\b)/', $startup, $m)) { $flags = trim($m[1]); }
        $javaCommand = 'java '.$flags;
        if ($java >= 9 && str_contains($startup, '@user_jvm_args.txt')) { $javaCommand .= ' @user_jvm_args.txt'; }
        if ($loader === 'vanilla') {
            return ['strategy' => 'managed_loader_install', 'target' => $target, 'startup' => $probe.'exec '.$javaCommand.' -jar '.self::INSTALLER.' nogui',
                'image' => $nextImage, 'artifact' => ['mojang_version' => $mc]];
        }
        if (in_array($loader, ['forge', 'neoforge'], true)) {
            $coordinate = $loader === 'forge' ? $mc.'-'.$version : $version;
            $url = $loader === 'forge' ? 'https://maven.minecraftforge.net/net/minecraftforge/forge/'.$coordinate.'/forge-'.$coordinate.'-installer.jar'
                : 'https://maven.neoforged.net/releases/net/neoforged/neoforge/'.$coordinate.'/neoforge-'.$coordinate.'-installer.jar';
            $install = 'java -jar '.self::INSTALLER.' --installServer';
            if ($loader === 'forge' && version_compare($mc, '1.17', '<')) {
                $jar = 'forge-'.$coordinate.'.jar'; $universal = 'forge-'.$coordinate.'-universal.jar';
                $launch = 'if [ -f '.escapeshellarg($jar).' ]; then exec '.$javaCommand.' -jar '.escapeshellarg($jar).' nogui; else exec '.$javaCommand.' -jar '.escapeshellarg($universal).' nogui; fi';
            } else {
                $args = 'libraries/net/'.($loader === 'forge' ? 'minecraftforge/forge/' : 'neoforged/neoforge/').$coordinate.'/unix_args.txt';
                $launch = 'exec '.$javaCommand.' @'.escapeshellarg($args).' nogui';
            }
        } elseif ($loader === 'fabric') {
            $url = 'https://maven.fabricmc.net/net/fabricmc/fabric-installer/1.1.0/fabric-installer-1.1.0.jar';
            $install = 'java -jar '.self::INSTALLER.' server -mcversion '.escapeshellarg($mc).' -loader '.escapeshellarg($version).' -downloadMinecraft';
            $launch = 'exec '.$javaCommand.' -jar fabric-server-launch.jar nogui';
        } else {
            $url = 'https://maven.quiltmc.org/repository/release/org/quiltmc/quilt-installer/0.9.1/quilt-installer-0.9.1.jar';
            $install = 'java -jar '.self::INSTALLER.' install server '.escapeshellarg($mc).' '.escapeshellarg($version).' --install-dir=. --download-server';
            $launch = 'exec '.$javaCommand.' -jar quilt-server-launch.jar nogui';
        }
        $identity = $loader.':'.$version.':'.$mc;
        $install = '[ ! -e '.self::SAVED_JVM.' ] && [ ! -L '.self::SAVED_JVM.' ] && [ ! -L user_jvm_args.txt ] && '.
            '{ if [ -f user_jvm_args.txt ]; then cp user_jvm_args.txt '.self::SAVED_JVM.'; fi; } && '.$install.' && '.
            '{ if [ -f '.self::SAVED_JVM.' ]; then mv '.self::SAVED_JVM.' user_jvm_args.txt; fi; }';
        $bootstrap = $probe.'[ ! -L '.self::MARKER.' ] && { if [ ! -f '.self::MARKER.' ] || [ "$(cat '.self::MARKER.')" != '.escapeshellarg($identity).' ]; then '.
            '{ '.$install.'; } && printf %s '.escapeshellarg($identity).' > '.self::MARKER.' || exit 1; fi; } && '.$launch;
        return ['strategy' => 'managed_loader_install', 'target' => $target, 'startup' => $bootstrap, 'image' => $nextImage, 'artifact' => ['url' => $url]];
    }
}
