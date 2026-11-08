<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Services\BackupExclusions;
use RuntimeException;

/** Root-entry operations only. No inventory or hash traversal of worlds. */
final class Recovery
{
    public function __construct(private readonly Files $files) {}

    /**
     * $authorize revalidates the exact complete locked native backup and stopped/guarded
     * container BEFORE every destructive action. The caller then invokes native restore
     * with truncate=false, waits for its callback, and verifies original critical files.
     * This method never restores or starts the server by itself.
     */
    public function clearForRestore(array $excluded, \Closure $authorize, \Closure $journal): void
    {
        $excluded = BackupExclusions::normalize($excluded);
        $authorize();
        $preserved = [];
        foreach ($this->files->listing() as $row) {
            if (in_array($row['name'], $excluded, true)) {
                if (! $row['directory'] || $row['file'] || $row['symlink']) { throw new RuntimeException('An excluded backup folder is not a regular directory; native recovery is blocked.'); }
                $preserved[] = $row['name'];
            }
        }
        $journal(['step' => 'recovery_clear_intent', 'preserved' => $preserved]);
        foreach ($this->files->listing() as $row) {
            if (in_array($row['name'], $preserved, true)) { continue; }
            $authorize();
            $journal(['step' => 'recovery_delete_intent', 'path' => $row['name']]);
            $this->files->delete($row['name']);
            $journal(['step' => 'recovery_deleted', 'path' => $row['name']]);
        }
        $this->requireCleared($preserved);
        $journal(['step' => 'recovery_root_cleared', 'preserved' => $preserved]);
    }

    public function requireCleared(array $preserved): void
    {
        $actual = [];
        foreach ($this->files->listing() as $row) {
            if (! in_array($row['name'], $preserved, true) || ! $row['directory'] || $row['file'] || $row['symlink']) {
                throw new RuntimeException('Native recovery root was not completely cleared. Keep Minecraft stopped; inspect Wings denylist and the exact locked backup.');
            }
            $actual[] = $row['name'];
        }
        sort($actual); sort($preserved);
        if ($actual !== $preserved) { throw new RuntimeException('Excluded backup directory disappeared during recovery; keep the server stopped.'); }
    }
}
