<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;
use RuntimeException;

/** Strict interchange format for immutable upstream release inventories. */
final class ManifestJson
{
    /** @return array{version:string,files:array<string,string>} */
    public function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Manifest file is not readable');
        }
        $size = @filesize($path);
        if ($size === false || $size > 16777216) {
            throw new RuntimeException('Manifest exceeds 16 MiB limit');
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Could not read manifest');
        }
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException('Invalid manifest JSON', previous: $error);
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 ||
            !is_string($data['version'] ?? null) || strlen($data['version']) > 128 ||
            trim($data['version']) === '' || !is_array($data['files'] ?? null)) {
            throw new InvalidArgumentException('Expected schema 1 manifest with version and files');
        }
        if (count($data['files']) > 100000) {
            throw new InvalidArgumentException('Too many manifest entries');
        }
        foreach ($data['files'] as $key => $sha) {
            UpdatePlanner::assertPath($key);
            if (!is_string($sha) || preg_match('/^[a-f0-9]{64}$/iD', $sha) !== 1) {
                throw new InvalidArgumentException("Invalid file hash for {$key}");
            }
        }
        ksort($data['files']);
        return ['version' => $data['version'], 'files' => $data['files']];
    }

    /** @param array<string,string> $files */
    public function encode(string $version, array $files): string
    {
        if (trim($version) === '' || strlen($version) > 128) {
            throw new InvalidArgumentException('Version must be 1-128 characters');
        }
        foreach ($files as $path => $hash) {
            UpdatePlanner::assertPath($path);
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/iD', $hash) !== 1) {
                throw new InvalidArgumentException("Invalid SHA-256 for {$path}");
            }
        }
        ksort($files);
        return json_encode(['schema' => 1, 'version' => $version, 'files' => $files],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
