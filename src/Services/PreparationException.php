<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

final class PreparationException extends \RuntimeException
{
    public readonly array $diagnostic;

    public function __construct(array $diagnostic)
    {
        $this->diagnostic = PreparationDiagnostic::normalize($diagnostic);
        parent::__construct(PreparationDiagnostic::message($this->diagnostic));
    }
}
