<?php

namespace AsiaKingTravel\CrmIntake\Exceptions;

use AsiaKingTravel\CrmIntake\IntakeResult;
use RuntimeException;

/**
 * CRM từ chối vĩnh viễn (400/401/403/404/409/422) - không retry.
 *
 * Message chỉ chứa outcome, http status và external_reference. Body phản hồi
 * KHÔNG được đưa vào message vì 422 có thể echo lại dữ liệu người dùng nhập.
 */
class IntakeRejected extends RuntimeException
{
    protected IntakeResult $result;

    public function __construct(IntakeResult $result)
    {
        $this->result = $result;

        parent::__construct(sprintf(
            'crm-intake: submission rejected permanently (outcome=%s, http_status=%s, external_reference=%s).',
            $result->outcome,
            $result->httpStatus === null ? 'none' : (string) $result->httpStatus,
            $result->externalReference
        ));
    }

    public function result(): IntakeResult
    {
        return $this->result;
    }
}
