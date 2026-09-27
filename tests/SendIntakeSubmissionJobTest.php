<?php

namespace AsiaKingTravel\CrmIntake\Tests;

use AsiaKingTravel\CrmIntake\Exceptions\IntakeRejected;
use AsiaKingTravel\CrmIntake\IntakeClient;
use AsiaKingTravel\CrmIntake\Jobs\SendIntakeSubmission;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

class SendIntakeSubmissionJobTest extends TestCase
{
    public function test_it_allows_five_attempts_with_increasing_backoff(): void
    {
        $job = new SendIntakeSubmission($this->submission());

        $this->assertSame(5, $job->tries);
        $this->assertSame([10, 30, 90, 300], $job->backoff());
    }

    public function test_a_successful_submission_is_not_released(): void
    {
        Http::fake(['*' => Http::response(['submission_ulid' => '01JBSUBMISSION0000000000AA'], 201)]);

        $queueJob = $this->queueJob(1);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldNotReceive('fail');

        $this->runJob(new SendIntakeSubmission($this->submission()), $queueJob);
    }

    public function test_a_duplicate_is_not_released(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $queueJob = $this->queueJob(1);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldNotReceive('fail');

        $this->runJob(new SendIntakeSubmission($this->submission()), $queueJob);
    }

    public function test_500_is_released_with_the_backoff_for_the_attempt(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $queueJob = $this->queueJob(2);
        $queueJob->shouldReceive('release')->once()->with(30);
        $queueJob->shouldNotReceive('fail');

        $this->runJob(new SendIntakeSubmission($this->submission()), $queueJob);
    }

    public function test_429_is_released_using_retry_after(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);

        $queueJob = $this->queueJob(1);
        $queueJob->shouldReceive('release')->once()->with(42);
        $queueJob->shouldNotReceive('fail');

        $this->runJob(new SendIntakeSubmission($this->submission()), $queueJob);
    }

    public function test_a_timeout_is_released(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $queueJob = $this->queueJob(1);
        $queueJob->shouldReceive('release')->once()->with(10);
        $queueJob->shouldNotReceive('fail');

        $this->runJob(new SendIntakeSubmission($this->submission()), $queueJob);
    }

    #[DataProvider('permanentStatuses')]
    public function test_permanent_errors_fail_the_job_without_retrying(int $status): void
    {
        Http::fake(['*' => Http::response([], $status)]);

        $queueJob = $this->queueJob(1);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldReceive('fail')->once()->with(Mockery::type(IntakeRejected::class));

        $this->runJob(new SendIntakeSubmission($this->submission()), $queueJob);
    }

    /**
     * @return array<int, array<int, int>>
     */
    public static function permanentStatuses(): array
    {
        return [[400], [401], [403], [404], [409], [422]];
    }

    public function test_a_retry_after_500_sends_a_byte_identical_body(): void
    {
        $submission = $this->submission();
        $frozen = $submission->toJson();

        // Job được serialize một lần vào queue; mỗi lần thử là một lần unserialize.
        $queued = serialize(new SendIntakeSubmission($submission));

        Http::fake([
            '*' => Http::sequence()
                ->push('boom', 500)
                ->push(['submission_ulid' => '01JBSUBMISSION0000000000AA'], 201),
        ]);

        $first = $this->queueJob(1);
        $first->shouldReceive('release')->once()->with(10);
        $this->runJob(unserialize($queued), $first);

        $second = $this->queueJob(2);
        $second->shouldNotReceive('release');
        $this->runJob(unserialize($queued), $second);

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);
        $this->assertSame($frozen, $recorded[0][0]->body());
        $this->assertSame($recorded[0][0]->body(), $recorded[1][0]->body());
    }

    public function test_the_queued_payload_keeps_received_at_and_external_reference(): void
    {
        $submission = $this->submission();
        $before = json_decode($submission->toJson(), true);

        $restored = unserialize(serialize(new SendIntakeSubmission($submission)))->submission();
        $after = json_decode($restored->toJson(), true);

        $this->assertSame($before['received_at'], $after['received_at']);
        $this->assertSame($before['external_reference'], $after['external_reference']);
        $this->assertSame($submission->toJson(), $restored->toJson());
        $this->assertSame($submission->getExternalReference(), $restored->getExternalReference());
    }

    /**
     * @return MockInterface&QueueJob
     */
    private function queueJob(int $attempts): MockInterface
    {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);

        return $queueJob;
    }

    private function runJob(SendIntakeSubmission $job, MockInterface $queueJob): void
    {
        $job->setJob($queueJob);
        $job->handle($this->app->make(IntakeClient::class));

        $this->addToAssertionCount(1);
    }
}
