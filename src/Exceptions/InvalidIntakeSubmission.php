<?php

namespace AsiaKingTravel\CrmIntake\Exceptions;

use InvalidArgumentException;

/**
 * Submission không đạt kiểm tối thiểu phía website.
 *
 * Message chỉ chứa TÊN field, không bao giờ chứa giá trị người dùng nhập.
 */
class InvalidIntakeSubmission extends InvalidArgumentException
{
    public static function missing(string $field): self
    {
        return new self(sprintf('crm-intake: field "%s" is required.', $field));
    }

    public static function invalid(string $field, string $rule): self
    {
        return new self(sprintf('crm-intake: field "%s" is invalid (%s).', $field, $rule));
    }

    public static function contactRequired(): self
    {
        return new self('crm-intake: at least one of "contact.email" or "contact.phone" is required.');
    }

    /**
     * @param  array<int, string>  $keys
     */
    public static function unknownTopLevelKeys(array $keys): self
    {
        return new self(sprintf(
            'crm-intake: unknown top-level payload keys rejected by CRM: %s.',
            implode(', ', $keys)
        ));
    }
}
