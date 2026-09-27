<?php

namespace AsiaKingTravel\CrmIntake;

use AsiaKingTravel\CrmIntake\Exceptions\IntakeNotConfigured;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class IntakeClient
{
    public const PATH = '/api/v1/crm/intake-submissions';

    /** HTTP status CRM coi là lỗi vĩnh viễn — không retry. */
    private const PERMANENT_STATUSES = [400, 401, 403, 404, 409, 422];

    private string $baseUrl;

    private string $token;

    private int $timeout;

    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] string $token,
        int $timeout = 10
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->timeout = $timeout;
    }

    public function endpoint(): string
    {
        return $this->baseUrl.self::PATH;
    }

    /**
     * Gửi submission sang CRM. Không ném exception cho lỗi HTTP — trả IntakeResult
     * để caller (hoặc job) quyết định retry.
     */
    public function submit(IntakeSubmission $submission): IntakeResult
    {
        // Đóng băng payload trước khi gửi; lần gửi lại dùng đúng chuỗi JSON này.
        $body = $submission->toJson();

        if ($this->baseUrl === '') {
            throw IntakeNotConfigured::missing('base_url');
        }

        if ($this->token === '') {
            throw IntakeNotConfigured::missing('token');
        }

        try {
            $response = Http::withToken($this->token)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout($this->timeout)
                ->withBody($body, 'application/json')
                ->post($this->endpoint());
        } catch (ConnectionException) {
            return $this->finish(new IntakeResult(
                IntakeResult::OUTCOME_TIMEOUT,
                null,
                true,
                $submission->getExternalReference(),
                $submission->getCorrelationId()
            ));
        }

        return $this->finish($this->interpret($response, $submission));
    }

    private function interpret(Response $response, IntakeSubmission $submission): IntakeResult
    {
        $status = $response->status();
        $reference = $submission->getExternalReference();
        $correlationId = $submission->getCorrelationId();

        if ($status === 201 || $status === 200) {
            $identifiers = $this->identifiers($response);

            return new IntakeResult(
                $status === 201 ? IntakeResult::OUTCOME_ACCEPTED : IntakeResult::OUTCOME_DUPLICATE,
                $status,
                false,
                $reference,
                $correlationId,
                $identifiers['submission_ulid'],
                $identifiers['inquiry_ulid']
            );
        }

        if (in_array($status, self::PERMANENT_STATUSES, true)) {
            return new IntakeResult(
                IntakeResult::OUTCOME_REJECTED,
                $status,
                false,
                $reference,
                $correlationId
            );
        }

        if ($status === 429 || $status >= 500) {
            return new IntakeResult(
                IntakeResult::OUTCOME_UNAVAILABLE,
                $status,
                true,
                $reference,
                $correlationId,
                null,
                null,
                $this->retryAfter($response)
            );
        }

        // Status ngoài hợp đồng DA-121: coi là vĩnh viễn để không gửi lặp vô ích.
        return new IntakeResult(
            IntakeResult::OUTCOME_REJECTED,
            $status,
            false,
            $reference,
            $correlationId
        );
    }

    /**
     * Chỉ đọc đúng hai định danh từ body thành công; phần còn lại của body
     * không được giữ lại.
     *
     * @return array{submission_ulid: ?string, inquiry_ulid: ?string}
     */
    private function identifiers(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            return ['submission_ulid' => null, 'inquiry_ulid' => null];
        }

        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;

        return [
            'submission_ulid' => isset($data['submission_ulid']) && is_string($data['submission_ulid'])
                ? $data['submission_ulid']
                : null,
            'inquiry_ulid' => isset($data['inquiry_ulid']) && is_string($data['inquiry_ulid'])
                ? $data['inquiry_ulid']
                : null,
        ];
    }

    /**
     * Retry-After: số giây, hoặc HTTP-date.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = trim((string) $response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $moment = strtotime($header);

        if ($moment === false) {
            return null;
        }

        $seconds = $moment - (new DateTimeImmutable('now'))->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Log chỉ gồm outcome, http status, submission_ulid, external_reference,
     * correlation_id. Không token, không email/phone/tên/nội dung form.
     */
    private function finish(IntakeResult $result): IntakeResult
    {
        $context = $result->logContext();

        if ($result->successful()) {
            Log::info('crm-intake.submission', $context);
        } elseif ($result->retryable) {
            Log::warning('crm-intake.submission', $context);
        } else {
            Log::error('crm-intake.submission', $context);
        }

        return $result;
    }
}
