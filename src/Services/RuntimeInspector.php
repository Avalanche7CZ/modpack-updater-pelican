<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

/** Observations only. The Docker image is a Java hint, never a runtime probe. */
final class RuntimeInspector
{
    public function inspect(array $paths, array $texts, string $startup, string $image, ?string $egg = null): array
    {
        $result = ['minecraft' => null, 'loader' => null, 'loader_version' => null, 'java' => null,
            'java_confidence' => 'unknown', 'startup' => $startup, 'image' => $image, 'egg' => $egg, 'evidence' => []];
        if (preg_match('/(?:java|jdk)[_: -]?(8|11|16|17|21|25)(?:[^0-9]|$)/i', $image, $m)) {
            $result['java'] = (int) $m[1];
            $result['java_confidence'] = 'image_hint';
        }
        foreach ($paths as $path) {
            if (preg_match('~libraries/net/(minecraftforge/forge|neoforged/neoforge|neoforged/forge)/([^/]+)/unix_args\.txt$~', $path, $m)) {
                $result['loader'] = $m[1] === 'minecraftforge/forge' ? 'forge' : 'neoforge';
                $result['loader_version'] = $m[2];
                $result['evidence'][] = $path;
                if (preg_match('/^(1\.[0-9]+(?:\.[0-9]+)?)-(.+)$/', $m[2], $v)) {
                    $result['minecraft'] = $v[1];
                    $result['loader_version'] = $v[2];
                }
            }
            if (stripos($path, 'crucible') !== false && str_ends_with(strtolower($path), '.jar')) {
                $result['loader'] = 'crucible';
                $result['minecraft'] = '1.7.10';
                $result['evidence'][] = $path;
            } elseif (preg_match('~(?:^|/)forge-(1\.[0-9]+(?:\.[0-9]+)?)-([0-9.]+).*\.jar$~i', $path, $m)) {
                $result['loader'] = 'forge';
                $result['minecraft'] = $m[1];
                $result['loader_version'] = $m[2];
                $result['evidence'][] = $path;
            }
            if (preg_match('~(?:^|/)(fabric|quilt)-server-launch(?:er)?\.jar$~', $path, $m)) {
                $result['loader'] = $m[1];
                $result['evidence'][] = $path;
            }
        }
        foreach (['fabric-server-launcher.properties', 'quilt-server-launcher.properties'] as $file) {
            if (isset($texts[$file]) && preg_match('/^serverJar=(.+)$/m', $texts[$file], $m)) {
                $result['evidence'][] = trim($m[0]);
            }
        }
        if (isset($texts['modrinth.index.json'])) {
            $data = json_decode($texts['modrinth.index.json'], true);
            $deps = $data['dependencies'] ?? [];
            $result['minecraft'] ??= $deps['minecraft'] ?? null;
            foreach (['forge', 'neoforge', 'fabric-loader', 'quilt-loader'] as $key) {
                if (isset($deps[$key])) {
                    $result['loader'] ??= str_replace('-loader', '', $key);
                    $result['loader_version'] ??= $deps[$key];
                }
            }
        }
        $result['pack_hint'] = isset($texts['modpack.json']) ? 'Inspect modpack.json; pack identity is unverified' : null;
        if ($result['loader'] === 'crucible') {
            $result['pack_hint'] = 'Possible GTNH/Crucible; confirm the provider, exact release and Java variant manually';
        }
        $result['launch_method'] = str_contains($startup, 'unix_args') ? 'unix_args' :
            (preg_match('/\b(?:bash|sh)\b/', $startup) ? 'script' : (str_contains($startup, '-jar') ? 'jar' : 'custom'));

        return $result;
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
