<?php

namespace AsiaKingTravel\CrmIntake\Tests;

use AsiaKingTravel\CrmIntake\Exceptions\IntakeRejected;
use AsiaKingTravel\CrmIntake\IntakeClient;
use AsiaKingTravel\CrmIntake\IntakeSubmission;
use AsiaKingTravel\CrmIntake\Jobs\SendIntakeSubmission;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

class LoggingPrivacyTest extends TestCase
{
    /** Những giá trị tuyệt đối không được xuất hiện trong log hay exception. */
    private const SECRETS = [
        self::TOKEN,
        self::EMAIL,
        self::PHONE,
        self::FULL_NAME,
        self::CONTENT,
    ];

    #[DataProvider('responses')]
    public function test_nothing_sensitive_reaches_the_log(int $status): void
    {
        // Body 422 của CRM thường echo lại chính dữ liệu người dùng nhập.
        Http::fake(['*' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['contact.email' => ['The value '.self::EMAIL.' is invalid.']],
        ], $status)]);

        $this->app->make(IntakeClient::class)->submit($this->submission());

        $this->assertNothingSensitiveIn($this->loggedJson());
    }

    /**
     * @return array<int, array<int, int>>
     */
    public static function responses(): array
    {
        return [[201], [200], [400], [401], [403], [404], [409], [422], [429], [500], [503]];
    }

    public function test_a_timeout_does_not_log_the_connection_message(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28 for '.self::ENDPOINT.'?token='.self::TOKEN);
        });

        $this->app->make(IntakeClient::class)->submit($this->submission());

        $this->assertNothingSensitiveIn($this->loggedJson());
    }

    #[DataProvider('responses')]
    public function test_the_log_context_carries_only_the_allowed_fields(int $status): void
    {
        Http::fake(['*' => Http::response([], $status)]);

        $this->app->make(IntakeClient::class)->submit($this->submission());

        $records = $this->records();
        $this->assertNotEmpty($records);

        foreach ($records as $record) {
            $this->assertSame(
                ['outcome', 'http_status', 'submission_ulid', 'external_reference', 'correlation_id'],
                array_keys($record['context'])
            );
        }
    }

    public function test_the_permanent_failure_exception_is_free_of_sensitive_data(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['contact.email' => [self::EMAIL]]], 422)]);

        $captured = null;
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('fail')->once()->andReturnUsing(function (Throwable $exception) use (&$captured) {
            $captured = $exception;
        });

        $submission = $this->submission();
        $job = new SendIntakeSubmission($submission);
        $job->setJob($queueJob);
        $job->handle($this->app->make(IntakeClient::class));

        $this->assertInstanceOf(IntakeRejected::class, $captured);
        $this->assertNothingSensitiveIn($captured->getMessage().$captured->getTraceAsString());
        $this->assertStringContainsString($submission->getExternalReference(), $captured->getMessage());
        $this->assertStringContainsString('http_status=422', $captured->getMessage());
    }

    public function test_validation_errors_name_the_field_without_echoing_the_value(): void
    {
        try {
            IntakeSubmission::make()
                ->languageCode('vietnamese')
                ->email(self::EMAIL)
                ->fullName(self::FULL_NAME)
                ->content(self::CONTENT)
                ->toJson();

            $this->fail('Expected a validation failure.');
        } catch (Throwable $exception) {
            $this->assertNothingSensitiveIn($exception->getMessage());
            $this->assertStringContainsString('language_code', $exception->getMessage());
        }
    }

    public function test_every_signature_taking_the_token_marks_it_sensitive(): void
    {
        $checked = 0;

        foreach ($this->packageClasses() as $class) {
            $reflection = new ReflectionClass($class);
            $methods = $reflection->getMethods();

            if ($reflection->getConstructor() !== null) {
                $methods[] = $reflection->getConstructor();
            }

            foreach ($methods as $method) {
                foreach ($method->getParameters() as $parameter) {
                    if (! $this->looksLikeAToken($parameter->getName(), $parameter->getType())) {
                        continue;
                    }

                    $checked++;

                    $this->assertNotEmpty(
                        $parameter->getAttributes(\SensitiveParameter::class),
                        sprintf(
                            '%s::%s($%s) phải được đánh dấu #[\SensitiveParameter].',
                            $class,
                            $method->getName(),
                            $parameter->getName()
                        )
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'Không tìm thấy chữ ký nào nhận token.');
    }

    private function looksLikeAToken(string $name, ?\ReflectionType $type): bool
    {
        if (stripos($name, 'token') === false) {
            return false;
        }

        // Bỏ qua tham số kiểu object (vd token object của thư viện khác).
        return ! $type instanceof ReflectionNamedType || $type->isBuiltin();
    }

    /**
     * @return array<int, class-string>
     */
    private function packageClasses(): array
    {
        $classes = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = trim(str_replace([realpath(__DIR__.'/../src'), '.php'], '', $file->getRealPath()), '/');
            $classes[] = 'AsiaKingTravel\\CrmIntake\\'.str_replace('/', '\\', $relative);
        }

        sort($classes);

        return $classes;
    }

    private function assertNothingSensitiveIn(string $haystack): void
    {
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $handlers = Log::channel('testing')->getLogger()->getHandlers();
        $handler = $handlers[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        return array_map(
            static fn ($record) => is_array($record) ? $record : $record->toArray(),
            $handler->getRecords()
        );
    }

    private function loggedJson(): string
    {
        return (string) json_encode($this->records(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
