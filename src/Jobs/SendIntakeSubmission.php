<?php

namespace AsiaKingTravel\CrmIntake\Jobs;

use AsiaKingTravel\CrmIntake\Exceptions\IntakeRejected;
use AsiaKingTravel\CrmIntake\IntakeClient;
use AsiaKingTravel\CrmIntake\IntakeSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Gửi submission sang CRM ở background.
 *
 * Retry CHỈ khi 429 / 5xx / timeout. Lỗi vĩnh viễn (400/401/403/404/409/422)
 * làm job fail ngay, không gửi lại. Payload đã đóng băng lúc tạo submission nên
 * mọi lần gửi lại là byte-identical.
 */
class SendIntakeSubmission implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Tối đa 5 lần gửi. */
    public int $tries = 5;

    /** Backoff tăng dần giữa các lần (giây), dùng khi CRM không gửi Retry-After. */
    public const BACKOFF = [10, 30, 90, 300];

    protected IntakeSubmission $submission;

    public function __construct(IntakeSubmission $submission)
    {
        // Đóng băng ngay: sai dữ liệu lộ ra ở request web, không phải ở worker.
        $submission->toJson();

        $this->submission = $submission;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    public function handle(IntakeClient $client): void
    {
        $result = $client->submit($this->submission);

        if ($result->successful()) {
            return;
        }

        if (! $result->retryable) {
            $this->fail(new IntakeRejected($result));

            return;
        }

        $this->release($result->retryAfter ?? $this->backoffSeconds());
    }

    public function submission(): IntakeSubmission
    {
        return $this->submission;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['crm-intake', 'external_reference:'.$this->submission->getExternalReference()];
    }

    private function backoffSeconds(): int
    {
        $attempt = max(1, $this->attempts());
        $backoff = $this->backoff();

        return $backoff[min($attempt, count($backoff)) - 1];
    }
}
