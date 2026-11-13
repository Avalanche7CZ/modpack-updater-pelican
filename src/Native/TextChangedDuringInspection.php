<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use RuntimeException;

/** A text read raced a file metadata change. Its bytes are not usable as evidence. */
final class TextChangedDuringInspection extends RuntimeException {}
