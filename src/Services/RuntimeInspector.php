<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

/** Observations only. The Docker image is a Java hint, never a runtime probe. */
final class RuntimeInspector
{
    public function inspect(array $paths, array $texts, string $startup, string $image, ?string $egg = null): array
    {
        $result = ['minecraft' => null, 'loader' => null, 'loader_version' => null, 'java' => null,
            'java_confidence' => 'unknown', 'loader_confidence' => 'unknown', 'startup' => $startup, 'image' => $image, 'egg' => $egg, 'evidence' => [], 'warnings' => []];
        if (preg_match('/(?:java|jdk)[_: -]?(8|11|16|17|21|25)(?:[^0-9]|$)/i', $image, $m)) {
            $result['java'] = (int) $m[1];
            $result['java_confidence'] = 'image_hint';
        }
        // Follow textual launch references, never evaluate shell expressions or execute scripts.
        $launchTexts = ['startup' => $startup];
        $scripts = ['run.sh', 'startserver.sh', 'start.sh', 'start_server.sh', 'start_server.bat', 'settings.sh'];
        $queue = ['startup'];
        while ($queue !== []) {
            $source = array_shift($queue);
            foreach ($scripts as $script) {
                if (! isset($texts[$script]) || isset($launchTexts[$script])) {
                    continue;
                }
                if (preg_match('~(?<![A-Za-z0-9_./-])(?:\./)?'.preg_quote($script, '~').'(?![A-Za-z0-9_./-])~', $launchTexts[$source])) {
                    $launchTexts[$script] = $this->withoutComments($texts[$script]);
                    $queue[] = $script;
                }
            }
        }
        if (count($launchTexts) === 1 && ! str_contains($startup, 'unix_args')) {
            foreach ($scripts as $script) {
                if (isset($texts[$script])) {
                    $launchTexts[$script] = $this->withoutComments($texts[$script]);
                }
            }
        }
        $references = [];
        foreach ($launchTexts as $source => $text) {
            preg_match_all('~(?:\./)?(libraries/net/(?:minecraftforge/forge|neoforged/(?:neoforge|forge))/[A-Za-z0-9_.+-]+/unix_args\.txt)~', $text, $matches);
            foreach ($matches[1] as $path) {
                if (in_array($path, $paths, true)) {
                    $references[$path] = true;
                    $result['evidence'][] = $source.' references '.$path;
                } else {
                    $result['warnings'][] = 'Referenced launcher arguments not found: '.$path;
                }
            }
        }
        foreach ($launchTexts as $text) {
            if (preg_match('~@(?:\./)?unix_args\.txt(?:[\s"\x27]|$)~', $text) && in_array('unix_args.txt', $paths, true)) {
                $references['unix_args.txt'] = true;
            }
        }
        // An explicitly referenced runtime takes precedence over old versions left in libraries/.
        $scanPaths = $references ? array_keys($references) : $paths;
        $candidates = [];
        foreach ($scanPaths as $path) {
            $candidate = null;
            if ($path === 'unix_args.txt' && isset($texts[$path])) {
                $args = $texts[$path];
                if (preg_match('/--fml\.(neoForgeVersion|forgeVersion)[=\s]+([A-Za-z0-9_.+-]+)/', $args, $m)) {
                    $candidate = ['loader' => $m[1] === 'neoForgeVersion' ? 'neoforge' : 'forge', 'loader_version' => $m[2], 'minecraft' => null];
                    if (preg_match('/--fml\.mcVersion[=\s]+(1\.[0-9]+(?:\.[0-9]+)?)/', $args, $v)) {
                        $candidate['minecraft'] = $v[1];
                    }
                }
            } elseif (preg_match('~libraries/net/(minecraftforge/forge|neoforged/neoforge|neoforged/forge)/([^/]+)/unix_args\.txt$~', $path, $m)) {
                $candidate = ['loader' => $m[1] === 'minecraftforge/forge' ? 'forge' : 'neoforge', 'loader_version' => $m[2], 'minecraft' => null];
                if (preg_match('/^(1\.[0-9]+(?:\.[0-9]+)?)-(.+)$/', $m[2], $v)) {
                    $candidate['minecraft'] = $v[1];
                    $candidate['loader_version'] = $v[2];
                }
                $args = $texts[$path] ?? '';
                if (preg_match('/--fml\.mcVersion[=\s]+["\x27]?(1\.[0-9]+(?:\.[0-9]+)?)/', $args, $v)) {
                    $candidate['minecraft'] = $v[1];
                }
            } elseif (stripos($path, 'crucible') !== false && str_ends_with(strtolower($path), '.jar')) {
                $candidate = ['loader' => 'crucible', 'loader_version' => null, 'minecraft' => '1.7.10'];
            } elseif (preg_match('~(?:^|/)forge-(1\.[0-9]+(?:\.[0-9]+)?)-([0-9.]+).*\.jar$~i', $path, $m)) {
                $candidate = ['loader' => 'forge', 'loader_version' => $m[2], 'minecraft' => $m[1]];
            } elseif (preg_match('~libraries/net/(fabricmc/fabric-loader|quiltmc/quilt-loader)/([^/]+)/[^/]+\.jar$~', $path, $m)) {
                $candidate = ['loader' => str_starts_with($m[1], 'fabricmc') ? 'fabric' : 'quilt', 'loader_version' => $m[2], 'minecraft' => null];
            } elseif (preg_match('~(?:^|/)(fabric|quilt)-server-launch(?:er)?\.jar$~', $path, $m)) {
                $candidate = ['loader' => $m[1], 'loader_version' => null, 'minecraft' => null];
            }
            if ($candidate) {
                $candidates[] = $candidate;
                $result['evidence'][] = $path;
            }
        }
        foreach (['loader', 'loader_version', 'minecraft'] as $key) {
            $values = array_values(array_unique(array_filter(array_column($candidates, $key), fn ($v) => $v !== null)));
            if (count($values) === 1) {
                $result[$key] = $values[0];
                if ($key === 'loader') {
                    $result['loader_confidence'] = 'file_evidence';
                }
            } elseif (count($values) > 1) {
                $result['warnings'][] = 'Ambiguous '.$key.' evidence; confirm the active launcher';
            }
        }
        $metadata = [];
        if (isset($texts['manifest.json'])) {
            $manifest = json_decode($texts['manifest.json'], true);
            if (($manifest['manifestType'] ?? null) === 'minecraftModpack') {
                $metadata['minecraft'] = $manifest['minecraft']['version'] ?? null;
                $loaders = array_values(array_filter($manifest['minecraft']['modLoaders'] ?? [], fn ($l) => ($l['primary'] ?? false) === true));
                if (count($loaders) === 1 && preg_match('/^(neoforge|forge|fabric|quilt)(?:-loader)?-(.+)$/', $loaders[0]['id'] ?? '', $m)) {
                    $metadata['loader'] = $m[1];
                    $metadata['loader_version'] = $m[2];
                }
            }
        }
        if (isset($texts['modrinth.index.json'])) {
            $index = json_decode($texts['modrinth.index.json'], true);
            $deps = $index['dependencies'] ?? [];
            $metadata['minecraft'] ??= $deps['minecraft'] ?? null;
            foreach (['forge', 'neoforge', 'fabric-loader', 'quilt-loader'] as $key) {
                if (isset($deps[$key])) {
                    $metadata['loader'] = str_replace('-loader', '', $key);
                    $metadata['loader_version'] = $deps[$key];
                }
            }
        }
        // TerraFirmaGreg Modern and other Forge Server Starter packs use a
        // small declarative forge-auto-install.txt instead of a direct Forge
        // launcher. Never execute the starter or its shell scripts.
        $starterConfig = false;
        if (isset($texts['forge-auto-install.txt'])) {
            $settings = [];
            foreach (['minecraftVersion', 'loaderType', 'loaderVersion'] as $setting) {
                if (preg_match('/^[ \\t]*'.preg_quote($setting, '/').'[ \\t]*=[ \\t]*([^#\\r\\n]+)$/mi',
                    $texts['forge-auto-install.txt'], $match)) {
                    $settings[$setting] = trim($match[1]);
                }
            }
            $minecraft = $settings['minecraftVersion'] ?? '';
            $loaderType = strtolower($settings['loaderType'] ?? '');
            $loaderVersion = $settings['loaderVersion'] ?? '';
            if (preg_match('/^(?:1|26)\\.[0-9]+(?:\\.[0-9]+)?$/D', $minecraft) === 1
                && in_array($loaderType, ['forge', 'neoforge'], true)
                && preg_match('/^[0-9]+(?:\\.[0-9]+)+(?:[-.][a-z0-9]+)?$/iD', $loaderVersion) === 1) {
                $metadata['minecraft'] ??= $minecraft;
                $metadata['loader'] ??= $loaderType;
                $metadata['loader_version'] ??= $loaderVersion;
                $starterConfig = true;
                $result['evidence'][] = 'forge-auto-install.txt declares '.$loaderType.' '.$loaderVersion;
            }
        }
        foreach (['fabric-server-launcher.properties', 'quilt-server-launcher.properties'] as $file) {
            if (isset($texts[$file]) && preg_match('/^serverJar=(?:[^\r\n]*\/)?(?:minecraft_server\.|server-)(1\.[0-9]+(?:\.[0-9]+)?)\.jar\s*$/m', $texts[$file], $m)) {
                $metadata['minecraft'] ??= $m[1];
                $result['evidence'][] = $file;
            }
        }
        foreach (['minecraft', 'loader', 'loader_version'] as $key) {
            if ($result[$key] === null && ! $result['warnings'] && is_string($metadata[$key] ?? null)) {
                $result[$key] = $metadata[$key];
                if ($key === 'loader') {
                    $result['loader_confidence'] = $starterConfig ? 'installer_config' : 'pack_metadata';
                }
            }
        }
        $result['pack_hint'] = isset($texts['modpack.json']) ? 'Inspect modpack.json; pack identity is unverified' : null;
        if ($starterConfig && in_array('minecraft_server.jar', $paths, true)) {
            $result['pack_hint'] = 'Forge Server Starter configuration detected; installed runtime still requires verification';
        }
        if ($result['loader'] === 'crucible') {
            $result['pack_hint'] = 'Possible GTNH/Crucible; confirm the provider, exact release and Java variant manually';
        }
        $result['launch_method'] = str_contains(implode("\n", $launchTexts), 'unix_args') ? 'unix_args' :
            (preg_match('/\b(?:bash|sh)\b/', $startup) ? 'script' : (str_contains($startup, '-jar') ? 'jar' : 'custom'));
        $result['evidence'] = array_values(array_unique($result['evidence']));

        return $result;
    }

    /** Actual boot evidence; absent fields stay unknown. */
    public function fromStartupLog(string $log): array
    {
        $r = ['minecraft' => null, 'loader' => null, 'loader_version' => null, 'java' => null];
        $mc = '(?:1\.[0-9]+(?:\.[0-9]+)?|26\.[0-9]+(?:\.[0-9]+)?)';
        if (preg_match('/--fml\.mcVersion[=,\s]+["\x27]?('.$mc.')/', $log, $m)) { $r['minecraft'] = $m[1]; }
        foreach (['neoForgeVersion' => 'neoforge', 'forgeVersion' => 'forge'] as $flag => $loader) {
            if (preg_match('/--fml\.'.$flag.'[=,\s]+["\x27]?([0-9]+(?:\.[0-9]+)+(?:-beta)?)/', $log, $m)) { $r['loader'] = $loader; $r['loader_version'] = $m[1]; break; }
        }
        if (preg_match('/(?:Loading|Starting) Minecraft ('.$mc.') with (Fabric|Quilt) Loader ([A-Za-z0-9_.+-]+)/i', $log, $m)) {
            $r = [...$r, 'minecraft' => $m[1], 'loader' => strtolower($m[2]), 'loader_version' => $m[3]];
        }
        if (preg_match('/NeoForge[^\r\n]*version[ :]+([0-9]+(?:\.[0-9]+)+(?:-beta)?)[^\r\n]*(?:for MC|Minecraft)[ :]+('.$mc.')/i', $log, $m)) {
            $r = [...$r, 'minecraft' => $m[2], 'loader' => 'neoforge', 'loader_version' => $m[1]];
        }
        if (preg_match('/MinecraftForge v([0-9]+(?:\.[0-9]+)+)/i', $log, $m)) { $r['loader'] ??= 'forge'; $r['loader_version'] ??= $m[1]; }
        if (preg_match('/Forge Mod Loader[^\r\n]*for Minecraft ('.$mc.')/i', $log, $m)) { $r['minecraft'] ??= $m[1]; $r['loader'] ??= 'forge'; }
        if (stripos($log, 'Crucible') !== false && $r['minecraft'] === '1.7.10') { $r['loader'] = 'crucible'; $r['loader_version'] = null; }
        if (preg_match('/ModLauncher[^\r\n]*starting:[^\r\n]*java version ([0-9]+)/i', $log, $m)) { $r['java'] = (int) $m[1]; }
        if ($r['java'] === null && preg_match('/Java is [^\r\n]*?version (1\.)?([0-9]+)(?:[.\s]|$)/i', $log, $m)) { $r['java'] = (int) $m[2]; }
        if ($r['loader'] === null && preg_match('/Starting minecraft server version ('.$mc.')/i', $log, $m)) {
            $r['minecraft'] = $m[1]; $r['loader'] = 'vanilla'; $r['loader_version'] = $m[1];
        }
        return $r;
    }

    public function javaVersion(string $probe): ?int
    {
        return preg_match('/(?:openjdk|java) version ["\x27](1\.)?([0-9]+)(?:[.\-+"\x27])/i', $probe, $m) ? (int) $m[2] : null;
    }

    private function withoutComments(string $text): string
    {
        return implode("\n", array_filter(preg_split('/\r?\n/', $text), fn ($line) => ! preg_match('/^\s*#/', $line)));
    }

    public function preflight(array $current, array $target): array
    {
        $blocks = [];
        $warnings = [];
        foreach (['minecraft', 'loader', 'loader_version'] as $field) {
            if (empty($target[$field])) {
                $blocks[] = 'Target '.$field.' is unknown';
            } elseif (empty($current[$field])) {
                $blocks[] = 'Installed '.$field.' is unknown';
            } elseif ($current[$field] !== $target[$field]) {
                $blocks[] = ucfirst($field).' changes; explicit runtime migration is required';
            }
        }
        if (empty($target['java']) || empty($current['java'])) {
            $blocks[] = 'Java compatibility is unverified';
        } elseif ((int) $current['java'] !== (int) $target['java']) {
            $blocks[] = 'Java image does not match the target requirement';
        }
        if (($current['java_confidence'] ?? '') !== 'verified') {
            $blocks[] = 'Actual Java runtime has not been independently verified';
        }
        if (! ($target['runtime_prepared'] ?? false)) {
            $blocks[] = 'Target loader runtime/launcher is not prepared';
        }
        if (($current['loader'] ?? '') === 'crucible') {
            $warnings[] = 'Preserve the known-working GTNH/Crucible launcher and review pack migration notes';
        }

        return ['compatible' => $blocks === [], 'blockers' => $blocks, 'warnings' => $warnings,
            'changes_applied' => false, 'current' => $current, 'target' => $target];
    }
}
