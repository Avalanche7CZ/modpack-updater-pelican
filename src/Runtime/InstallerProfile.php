<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Runtime;

use Arcturus\ModpackManager\Services\UpdatePlanner;
use RuntimeException;
use ZipArchive;

/** Authoritative installer identity and required generated output, read without executing the JAR. */
final class InstallerProfile
{
    public function inspect(array $artifact, array $target): array
    {
        if (is_link($artifact['path']) || ! hash_equals($artifact['sha256'], hash_file('sha256', $artifact['path']))) { throw new RuntimeException('Installer artifact changed before profile inspection.'); }
        $zip = new ZipArchive;
        if ($zip->open($artifact['path']) !== true) { throw new RuntimeException('Installer is not a readable JAR archive.'); }
        try {
            $raw = $this->entry($zip, 'install_profile.json');
            $profile = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
            $version = json_decode($this->entry($zip, 'version.json'), true, 128, JSON_THROW_ON_ERROR);
            if (($profile['spec'] ?? null) !== 1 || ($profile['minecraft'] ?? null) !== $target['minecraft'] || ($version['inheritsFrom'] ?? null) !== $target['minecraft']) {
                throw new RuntimeException('Installer profile does not prove the exact requested Minecraft version.');
            }
            $flag = $target['loader'] === 'forge' ? '--fml.forgeVersion' : '--fml.neoForgeVersion';
            $game = $version['arguments']['game'] ?? [];
            $index = array_search($flag, $game, true);
            if ($index === false || ($game[$index + 1] ?? null) !== $target['loader_version']) { throw new RuntimeException('Installer profile does not prove the exact requested loader version.'); }
            $patched = $profile['data']['PATCHED']['server'] ?? null;
            if (! is_string($patched) || ! preg_match('/^\[([A-Za-z0-9_.-]+):([A-Za-z0-9_.-]+):([A-Za-z0-9_.+-]+):server\]$/D', $patched, $m)) {
                throw new RuntimeException('Installer does not declare a supported generated server runtime.');
            }
            $core = 'libraries/'.str_replace('.', '/', $m[1]).'/'.$m[2].'/'.$m[3].'/'.$m[2].'-'.$m[3].'-server.jar';
            UpdatePlanner::assertPath($core);
            $coordinate = $target['loader'] === 'forge' ? 'minecraftforge/forge/'.$target['minecraft'].'-'.$target['loader_version'] : 'neoforged/neoforge/'.$target['loader_version'];
            $args = 'libraries/net/'.$coordinate.'/unix_args.txt';
            $argumentText = $this->entry($zip, 'data/unix_args.txt');
            $declared = (new \Arcturus\ModpackManager\Services\RuntimeInspector)->fromStartupLog($argumentText);
            foreach (['minecraft', 'loader', 'loader_version'] as $key) { if (($declared[$key] ?? null) !== $target[$key]) { throw new RuntimeException('Installer launcher arguments do not match the requested runtime.'); } }
            if ($core !== 'libraries/net/'.$coordinate.'/'.($target['loader'] === 'forge' ? 'forge-'.$target['minecraft'].'-'.$target['loader_version'] : 'neoforge-'.$target['loader_version']).'-server.jar') {
                throw new RuntimeException('Installer generated-server coordinate differs from the requested runtime.');
            }
            return ['profile_sha256' => hash('sha256', $raw), 'args' => $args, 'args_sha256' => hash('sha256', $argumentText), 'core' => $core];
        } finally { $zip->close(); }
    }

    private function entry(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if (! $stat || $stat['size'] > 1048576 || ($text = $zip->getFromName($name)) === false) { throw new RuntimeException('Installer metadata is missing or too large.'); }
        return $text;
    }
}
