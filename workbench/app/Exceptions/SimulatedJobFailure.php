<?php

namespace Workbench\App\Exceptions;

use RuntimeException;

class SimulatedJobFailure extends RuntimeException
{
    public static function forItem(int $itemNumber): self
    {
        return new self("Simulated failure for item {$itemNumber}");
    }

    public static function forEmail(string $email): self
    {
        return new self('Simulated email sending failure');
    }
}
