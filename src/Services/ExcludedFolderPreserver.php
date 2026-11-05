<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use RuntimeException;
use Throwable;

/**
 * Keeps folders omitted from a Pelican backup across truncate=true restores.
 *
 * Uses rename, not copy: a 40 GB backups/ directory is moved out of Wings'
 * live root without duplicating it. If paths are on separate filesystems,
 * rename fails and the restore is refused, leaving original data untouched.
 *
 * Stash is durable and intentionally NOT automatically removed on errors.
 * Never put stash inside the Wings volume that Pelican will truncate.
 */
final class ExcludedFolderPreserver
{
    /** @param list<string> $folders */
    public function moveOut(string $liveRoot, string $stashParent, array $folders): ?string
    {
        $names = BackupExclusions::normalize($folders);
        if ($names === []) {
            return null;
        }
        $live = $this->root($liveRoot);
        $parent = $this->root($stashParent);
        if ($live === $parent ||
            str_starts_with($parent, $live . '/') ||
            str_starts_with($live, $parent . '/')) {
            throw new RuntimeException('Preservation staging must not overlap live server');
        }

        $existing = [];
        foreach ($names as $name) {
            $source = $live . '/' . $name;
            if (is_link($source)) {
                throw new RuntimeException("Excluded directory is a symlink: {$name}");
            }
            if (file_exists($source)) {
                if (!is_dir($source)) {
                    throw new RuntimeException("Excluded name is not a directory: {$name}");
                }
                $existing[] = $name;
            }
        }
        if ($existing === []) {
            return null;
        }

        $stash = $parent . '/mpm-preserved-' . bin2hex(random_bytes(10));
        if (!@mkdir($stash, 0700)) {
            throw new RuntimeException('Cannot create excluded-folder preservation directory');
        }
        $journal = [
            'schema' => 1,
            'live_root' => $live,
            'folders' => $existing,
            'created_at' => gmdate(DATE_ATOM),
            'purpose' => 'preserve_backup_exclusions_across_truncate_restore',
        ];
        if (@file_put_contents($stash . '/.mpm-preserved.json',
            json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException('Could not write preservation recovery journal');
        }

        try {
            foreach ($existing as $name) {
                if (!@rename($live . '/' . $name, $stash . '/' . $name)) {
                    throw new RuntimeException(
                        "Cannot move {$name} into safe staging (is it on a different filesystem?)"
                    );
                }
            }
        } catch (Throwable $error) {
            // On a pre-restore error, try to return every moved folder.
            try {
                $this->moveBack($live, $stash);
            } catch (Throwable $recoveryError) {
                throw new RuntimeException(
                    "Preservation failed and recovery is incomplete. Manual recovery: {$stash}. " .
                    $recoveryError->getMessage(),
                    previous: $error
                );
            }
            throw new RuntimeException('Cannot preserve excluded folders: ' . $error->getMessage(),
                previous: $error);
        }

        return $stash;
    }

    public function moveBack(string $liveRoot, string $stashPath): void
    {
        $live = $this->root($liveRoot);
        $stash = $this->root($stashPath);
        $metadata = $stash . '/.mpm-preserved.json';
        if (is_link($metadata) || !is_file($metadata)) {
            throw new RuntimeException("Preservation journal missing at {$stash}");
        }
        $data = json_decode((string) file_get_contents($metadata), true);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 ||
            ($data['live_root'] ?? null) !== $live ||
            ($data['purpose'] ?? null) !== 'preserve_backup_exclusions_across_truncate_restore') {
            throw new RuntimeException('Preservation journal does not match live server root');
        }
        $folders = BackupExclusions::normalize((array) ($data['folders'] ?? []));
        foreach ($folders as $name) {
            $from = $stash . '/' . $name;
            $dest = $live . '/' . $name;
            if (is_link($from) || is_link($dest)) {
                throw new RuntimeException("Symlink detected during recovery: {$name}");
            }
            if (!file_exists($from)) {
                // Already returned by a prior recovery attempt.
                if (is_dir($dest)) {
                    continue;
                }
                throw new RuntimeException("Preserved folder missing; inspect {$stash}");
            }
            if (file_exists($dest)) {
                throw new RuntimeException(
                    "Restore recreated {$name}; refusing overwrite. Preserved contents remain at {$stash}"
                );
            }
            if (!@rename($from, $dest)) {
                throw new RuntimeException("Cannot return {$name}. Recover it manually from {$stash}");
            }
        }
        @unlink($metadata);
        @rmdir($stash);
    }

    private function root(string $path): string
    {
        if (is_link($path)) {
            throw new RuntimeException('Root symlinks are not allowed');
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('A configured directory is inaccessible: ' . $path);
        }
        return rtrim($real, '/') ?: '/';
    }
}
