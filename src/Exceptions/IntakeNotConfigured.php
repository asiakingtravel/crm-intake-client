<?php

namespace AsiaKingTravel\CrmIntake\Exceptions;

use RuntimeException;

class IntakeNotConfigured extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self(sprintf('crm-intake: config "crm-intake.%s" is not set.', $key));
    }
}
