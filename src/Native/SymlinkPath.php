<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use RuntimeException;

/** A candidate is protected at this exact link; its target must never be read. */
final class SymlinkPath extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('Native operation blocked by a symlink at '.$path);
    }
}
