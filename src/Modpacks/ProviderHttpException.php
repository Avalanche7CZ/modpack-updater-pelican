<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Modpacks;

/** A safe provider status; response bodies, URLs and headers never enter the message. */
final class ProviderHttpException extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Provider HTTP '.$status.($status === 429 ? '; rate limited, retry later' : ''));
    }
}
