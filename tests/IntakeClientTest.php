<?php

namespace AsiaKingTravel\CrmIntake\Tests;

use AsiaKingTravel\CrmIntake\Exceptions\IntakeNotConfigured;
use AsiaKingTravel\CrmIntake\IntakeClient;
use AsiaKingTravel\CrmIntake\IntakeResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class IntakeClientTest extends TestCase
{
    private function client(): IntakeClient
    {
        return $this->app->make(IntakeClient::class);
    }

    public function test_201_is_accepted(): void
    {
        Http::fake([
            '*' => Http::response([
                'submission_ulid' => '01JBSUBMISSION0000000000AA',
                'inquiry_ulid' => '01JBINQUIRY0000000000000BB',
            ], 201),
        ]);

        $result = $this->client()->submit($this->submission());

        $this->assertSame(IntakeResult::OUTCOME_ACCEPTED, $result->outcome);
        $this->assertSame(201, $result->httpStatus);
        $this->assertSame('01JBSUBMISSION0000000000AA', $result->submissionUlid);
        $this->assertSame('01JBINQUIRY0000000000000BB', $result->inquiryUlid);
        $this->assertFalse($result->retryable);
        $this->assertTrue($result->successful());
    }

    public function test_201_reads_identifiers_nested_under_data(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'submission_ulid' => '01JBSUBMISSION0000000000AA',
                    'inquiry_ulid' => '01JBINQUIRY0000000000000BB',
                ],
            ], 201),
        ]);

        $result = $this->client()->submit($this->submission());

        $this->assertSame('01JBSUBMISSION0000000000AA', $result->submissionUlid);
        $this->assertSame('01JBINQUIRY0000000000000BB', $result->inquiryUlid);
    }

    public function test_200_duplicate_counts_as_success(): void
    {
        Http::fake([
            '*' => Http::response([
                'submission_ulid' => '01JBSUBMISSION0000000000AA',
                'inquiry_ulid' => '01JBINQUIRY0000000000000BB',
            ], 200),
        ]);

        $result = $this->client()->submit($this->submission());

        $this->assertSame(IntakeResult::OUTCOME_DUPLICATE, $result->outcome);
        $this->assertSame(200, $result->httpStatus);
        $this->assertTrue($result->successful());
        $this->assertTrue($result->isDuplicate());
        $this->assertFalse($result->retryable);
        $this->assertSame('01JBSUBMISSION0000000000AA', $result->submissionUlid);
    }

    public function test_401_is_permanent(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $result = $this->client()->submit($this->submission());

        $this->assertSame(IntakeResult::OUTCOME_REJECTED, $result->outcome);
        $this->assertSame(401, $result->httpStatus);
        $this->assertFalse($result->retryable);
        $this->assertFalse($result->successful());
    }

    public function test_409_payload_conflict_is_permanent(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'idempotency_key_payload_conflict'], 409),
        ]);

        $result = $this->client()->submit($this->submission());

        $this->assertSame(IntakeResult::OUTCOME_REJECTED, $result->outcome);
        $this->assertSame(409, $result->httpStatus);
        $this->assertFalse($result->retryable);
    }

    #[DataProvider('permanentStatuses')]
    public function test_permanent_statuses_are_never_retryable(int $status): void
    {
        Http::fake(['*' => Http::response([], $status)]);

        $result = $this->client()->submit($this->submission());

        $this->assertSame(IntakeResult::OUTCOME_REJECTED, $result->outcome);
        $this->assertFalse($result->retryable);
    }

    /**
     * @return array<int, array<int, int>>
     */
    public static function permanentStatuses(): array
    {
        return [[400], [401], [403], [404], [409], [422]];
    }

    public function test_429_is_retryable_and_respects_retry_after(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => '42']),
        ]);

        $result = $this->client()->submit($this->submission());

        $this->assertSame(IntakeResult::OUTCOME_UNAVAILABLE, $result->outcome);
        $this->assertSame(429, $result->httpStatus);
        $this->assertTrue($result->retryable);
        $this->assertSame(42, $result->retryAfter);
    }

    public function test_500_is_retryable(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $result = $this->client()->submit($this->submission());

        $this->assertSame(IntakeResult::OUTCOME_UNAVAILABLE, $result->outcome);
        $this->assertSame(500, $result->httpStatus);
        $this->assertTrue($result->retryable);
        $this->assertNull($result->retryAfter);
    }

    public function test_timeout_is_retryable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds');
        });

        $result = $this->client()->submit($this->submission());

        $this->assertSame(IntakeResult::OUTCOME_TIMEOUT, $result->outcome);
        $this->assertNull($result->httpStatus);
        $this->assertTrue($result->retryable);
    }

    public function test_it_posts_json_to_the_da121_endpoint_with_a_bearer_token(): void
    {
        Http::fake(['*' => Http::response([], 201)]);

        $submission = $this->submission();
        $this->client()->submit($submission);

        Http::assertSent(function (Request $request) use ($submission) {
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame('Bearer '.self::TOKEN, $request->header('Authorization')[0]);
            $this->assertStringStartsWith('application/json', $request->header('Content-Type')[0]);
            $this->assertSame($submission->toJson(), $request->body());

            return true;
        });
    }

    public function test_the_token_is_never_in_the_url_or_the_body(): void
    {
        Http::fake(['*' => Http::response([], 201)]);

        $this->client()->submit($this->submission());

        Http::assertSent(function (Request $request) {
            $this->assertStringNotContainsString(self::TOKEN, $request->url());
            $this->assertStringNotContainsString(self::TOKEN, $request->body());

            return true;
        });
    }

    public function test_it_fails_loudly_when_the_token_is_missing(): void
    {
        config(['crm-intake.token' => '']);
        $this->app->forgetInstance(IntakeClient::class);

        $this->expectException(IntakeNotConfigured::class);
        $this->expectExceptionMessage('crm-intake.token');

        $this->client()->submit($this->submission());
    }
}
