<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

/** Wings uses context.Background: retain maintenance and never delete/reuse its target on uncertainty. */
final class ArchiveOutcomeUnknown extends \RuntimeException {}
