<?php

namespace AsiaKingTravel\CrmIntake;

/**
 * Kết quả một lần gọi CRM intake.
 *
 * Chủ ý KHÔNG giữ body phản hồi: 4xx (nhất là 422) có thể echo lại dữ liệu
 * người dùng nhập, mà dữ liệu đó không được phép rơi vào log/exception.
 */
final class IntakeResult
{
    /** 201 — CRM đã nhận. */
    public const OUTCOME_ACCEPTED = 'accepted';

    /** 200 — CRM đã nhận trước đó cho cùng external_reference; coi là thành công. */
    public const OUTCOME_DUPLICATE = 'duplicate';

    /** 400/401/403/404/409/422 — lỗi vĩnh viễn, không retry. */
    public const OUTCOME_REJECTED = 'rejected';

    /** 429 hoặc 5xx — lỗi tạm thời, nên retry. */
    public const OUTCOME_UNAVAILABLE = 'unavailable';

    /** Không kết nối được / timeout — nên retry. */
    public const OUTCOME_TIMEOUT = 'timeout';

    public string $outcome;

    public ?int $httpStatus;

    public ?string $submissionUlid;

    public ?string $inquiryUlid;

    public bool $retryable;

    /** Số giây lấy từ header Retry-After (nếu CRM có gửi). */
    public ?int $retryAfter;

    public string $externalReference;

    public ?string $correlationId;

    public function __construct(
        string $outcome,
        ?int $httpStatus,
        bool $retryable,
        string $externalReference,
        ?string $correlationId = null,
        ?string $submissionUlid = null,
        ?string $inquiryUlid = null,
        ?int $retryAfter = null
    ) {
        $this->outcome = $outcome;
        $this->httpStatus = $httpStatus;
        $this->retryable = $retryable;
        $this->externalReference = $externalReference;
        $this->correlationId = $correlationId;
        $this->submissionUlid = $submissionUlid;
        $this->inquiryUlid = $inquiryUlid;
        $this->retryAfter = $retryAfter;
    }

    public function successful(): bool
    {
        return $this->outcome === self::OUTCOME_ACCEPTED || $this->outcome === self::OUTCOME_DUPLICATE;
    }

    public function isDuplicate(): bool
    {
        return $this->outcome === self::OUTCOME_DUPLICATE;
    }

    /**
     * Context được phép ghi log. Không có token, không có dữ liệu người dùng.
     *
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return [
            'outcome' => $this->outcome,
            'http_status' => $this->httpStatus,
            'submission_ulid' => $this->submissionUlid,
            'external_reference' => $this->externalReference,
            'correlation_id' => $this->correlationId,
        ];
    }
}
