<?php

namespace AsiaKingTravel\CrmIntake\Tests;

use AsiaKingTravel\CrmIntake\CrmIntakeServiceProvider;
use AsiaKingTravel\CrmIntake\IntakeSubmission;
use Monolog\Handler\TestHandler;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** Giá trị nhạy cảm dùng để kiểm chúng KHÔNG lọt vào log/exception. */
    public const TOKEN = 'crm-token-must-never-be-logged-8f3a1c';

    public const EMAIL = 'nguyen.van.a@example.com';

    public const PHONE = '+84901234567';

    public const FULL_NAME = 'Nguyễn Văn A';

    public const CONTENT = 'Tôi muốn đặt tour Hạ Long 3 ngày cho 4 người vào tháng 12.';

    public const BASE_URL = 'https://crm-stage.asiakingtravel.com';

    public const ENDPOINT = self::BASE_URL.'/api/v1/crm/intake-submissions';

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CrmIntakeServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('crm-intake.base_url', self::BASE_URL);
        $app['config']->set('crm-intake.token', self::TOKEN);
        $app['config']->set('crm-intake.source_key', 'akt-website-vi');
        $app['config']->set('crm-intake.channel_type', 'website');
        $app['config']->set('crm-intake.timeout', 10);

        $app['config']->set('logging.channels.testing', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
        $app['config']->set('logging.default', 'testing');
    }

    /**
     * Submission đầy đủ field, dùng chung cho các test.
     */
    protected function submission(): IntakeSubmission
    {
        return IntakeSubmission::make()
            ->languageCode('vi')
            ->fullName(self::FULL_NAME)
            ->email(self::EMAIL)
            ->phone(self::PHONE)
            ->preferredLanguageCode('vi')
            ->content(self::CONTENT)
            ->requestedService('halong-cruise')
            ->campaignReference('google-ads-2026-q4')
            ->consent(true)
            ->correlationId('web-req-0001')
            ->extension(['form_id' => 'contact-footer']);
    }
}
