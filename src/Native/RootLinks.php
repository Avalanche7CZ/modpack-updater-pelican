<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use RuntimeException;

/** Retain the existing root launcher link by native rename; never follow or recreate it. */
final class RootLinks
{
    public function __construct(private readonly Wings $files) {}

    public function preserve(string $stash, ?array $receipt, \Closure $journal): ?array
    {
        if (! preg_match('/^\.arcturus-recovery-[a-f0-9-]{36}$/D', $stash)) { throw new RuntimeException('Invalid native link preservation identity.'); }
        if ($receipt === null) {
            $links = [];
            foreach ($this->files->listing() as $row) {
                if (! $row['symlink']) { continue; }
                if ($row['name'] !== 'unix_args.txt') { throw new RuntimeException('Native recovery cannot yet preserve this root symlink: '.$row['name']); }
                $links[$row['name']] = $row;
            }
            if ($links === []) { return null; }
            if ($this->files->stat($stash) !== null) { throw new RuntimeException('An unrecognized native recovery stash exists; retain it for manual review.'); }
            $receipt = ['path' => $stash, 'links' => $links];
            $journal(['step' => 'root_links_preserve_intent', 'link_stash' => $receipt]);
        }
        if (($receipt['path'] ?? null) !== $stash || array_keys($receipt['links'] ?? []) !== ['unix_args.txt']) { throw new RuntimeException('Native launcher-link receipt is invalid.'); }
        $this->files->mkdir($stash);
        foreach ($this->files->listing($stash) as $row) { if (! isset($receipt['links'][$row['name']])) { throw new RuntimeException('Unexpected entry in native recovery stash.'); } }
        foreach ($receipt['links'] as $path => $expected) {
            $saved = $this->files->linkMetadata($stash.'/'.$path);
            if ($saved === null) {
                if (! Wings::sameLink($expected, $this->files->linkMetadata($path))) { throw new RuntimeException('Original launcher link could not be verified before preservation.'); }
                $journal(['step' => 'root_link_move_intent', 'path' => $path]);
                $this->files->moveLink($path, $stash.'/'.$path);
            }
            if (! Wings::sameLink($expected, $this->files->linkMetadata($stash.'/'.$path))) { throw new RuntimeException('Retained launcher link changed; keep Minecraft stopped.'); }
        }
        $journal(['step' => 'root_links_preserved', 'link_stash' => $receipt]);
        return $receipt;
    }

    public function restore(?array $receipt, \Closure $journal): void
    {
        if ($receipt === null) { return; }
        $stash = $receipt['path'];
        foreach ($receipt['links'] as $path => $expected) {
            if (! Wings::sameLink($expected, $this->files->linkMetadata($stash.'/'.$path))) { throw new RuntimeException('Preserved root launcher link is missing or changed.'); }
            $journal(['step' => 'root_link_return_intent', 'path' => $path]);
            if ($this->files->linkMetadata($path) !== null) { $this->files->delete($path); }
            $this->files->moveLink($stash.'/'.$path, $path);
            if (! Wings::sameLink($expected, $this->files->linkMetadata($path))) { throw new RuntimeException('Original launcher link was not returned correctly.'); }
        }
        $this->files->delete($stash);
        $journal(['step' => 'root_links_restored', 'link_stash' => null]);
    }
}
