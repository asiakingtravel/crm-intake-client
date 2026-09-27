<?php

namespace AsiaKingTravel\CrmIntake\Tests;

use AsiaKingTravel\CrmIntake\Exceptions\InvalidIntakeSubmission;
use AsiaKingTravel\CrmIntake\IntakeSubmission;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class PayloadTest extends TestCase
{
    public function test_it_builds_the_da121_envelope(): void
    {
        $payload = json_decode($this->submission()->toJson(), true);

        $this->assertSame('akt-website-vi', $payload['source_key']);
        $this->assertSame('website', $payload['channel_type']);
        $this->assertSame('vi', $payload['language_code']);
        $this->assertSame(self::FULL_NAME, $payload['contact']['full_name']);
        $this->assertSame(self::EMAIL, $payload['contact']['email']);
        $this->assertSame(self::PHONE, $payload['contact']['phone']);
        $this->assertSame('vi', $payload['contact']['preferred_language_code']);
        $this->assertSame(self::CONTENT, $payload['inquiry']['content']);
        $this->assertSame('halong-cruise', $payload['inquiry']['requested_service']);
        $this->assertSame('google-ads-2026-q4', $payload['campaign_reference']);
        $this->assertSame(['indicator' => true], $payload['consent']);
        $this->assertSame('web-req-0001', $payload['correlation_id']);
        $this->assertSame(['form_id' => 'contact-footer'], $payload['extension']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $payload['external_reference']);
    }

    public function test_it_only_sends_whitelisted_top_level_keys(): void
    {
        $payload = json_decode($this->submission()->toJson(), true);

        $this->assertSame(
            [],
            array_diff(array_keys($payload), IntakeSubmission::ALLOWED_TOP_LEVEL_KEYS),
            'CRM từ chối cả request nếu có key top-level lạ.'
        );
    }

    #[DataProvider('forbiddenKeys')]
    public function test_it_never_sends_keys_outside_the_contract(string $key): void
    {
        $payload = json_decode($this->submission()->toJson(), true);

        $this->assertArrayNotHasKey($key, $payload);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function forbiddenKeys(): array
    {
        return [
            ['idempotency_key'],
            ['company_code'],
            ['selling_brand_code'],
            ['market_code'],
            ['fingerprint'],
            ['attachments'],
        ];
    }

    public function test_it_drops_empty_fields_instead_of_sending_null(): void
    {
        $json = IntakeSubmission::make()
            ->languageCode('en')
            ->email(self::EMAIL)
            ->fullName('   ')
            ->phone(null)
            ->requestedService('')
            ->campaignReference(null)
            ->content(self::CONTENT)
            ->toJson();

        $payload = json_decode($json, true);

        $this->assertStringNotContainsString('null', $json);
        $this->assertSame(
            ['source_key', 'channel_type', 'language_code', 'received_at', 'external_reference', 'contact', 'inquiry'],
            array_keys($payload)
        );
        $this->assertSame(['email' => self::EMAIL], $payload['contact']);
        $this->assertSame(['content' => self::CONTENT], $payload['inquiry']);
        $this->assertArrayNotHasKey('consent', $payload);
        $this->assertArrayNotHasKey('extension', $payload);
    }

    public function test_it_keeps_consent_false(): void
    {
        $payload = json_decode(
            IntakeSubmission::make()
                ->languageCode('vi')
                ->phone(self::PHONE)
                ->content(self::CONTENT)
                ->consent(false)
                ->toJson(),
            true
        );

        $this->assertSame(['indicator' => false], $payload['consent']);
    }

    public function test_received_at_is_rfc3339_utc_with_six_decimals(): void
    {
        $payload = json_decode($this->submission()->toJson(), true);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $payload['received_at']
        );
    }

    public function test_it_encodes_utf8_without_escaping(): void
    {
        $json = $this->submission()->toJson();

        $this->assertStringContainsString(self::FULL_NAME, $json);
        $this->assertStringNotContainsString('\\u', $json);
    }

    public function test_it_requires_inquiry_content(): void
    {
        $this->expectException(InvalidIntakeSubmission::class);
        $this->expectExceptionMessage('"inquiry.content" is required');

        IntakeSubmission::make()->languageCode('vi')->email(self::EMAIL)->toJson();
    }

    public function test_it_requires_language_code(): void
    {
        $this->expectException(InvalidIntakeSubmission::class);
        $this->expectExceptionMessage('"language_code" is required');

        IntakeSubmission::make()->email(self::EMAIL)->content(self::CONTENT)->toJson();
    }

    public function test_it_limits_language_code_to_five_characters(): void
    {
        $this->expectException(InvalidIntakeSubmission::class);
        $this->expectExceptionMessage('max 5 characters');

        IntakeSubmission::make()->languageCode('vi-VN-x')->email(self::EMAIL)->content(self::CONTENT)->toJson();
    }

    public function test_it_requires_email_or_phone(): void
    {
        $this->expectException(InvalidIntakeSubmission::class);
        $this->expectExceptionMessage('"contact.email" or "contact.phone"');

        IntakeSubmission::make()->languageCode('vi')->content(self::CONTENT)->toJson();
    }

    public function test_it_rejects_an_unknown_channel_type(): void
    {
        $this->expectException(InvalidIntakeSubmission::class);
        $this->expectExceptionMessage('"channel_type" is invalid');

        IntakeSubmission::make()
            ->channelType('mobile_app')
            ->languageCode('vi')
            ->email(self::EMAIL)
            ->content(self::CONTENT)
            ->toJson();
    }

    public function test_it_accepts_landing_page_channel(): void
    {
        $payload = json_decode(
            IntakeSubmission::make()
                ->channelType('landing_page')
                ->languageCode('vi')
                ->email(self::EMAIL)
                ->content(self::CONTENT)
                ->toJson(),
            true
        );

        $this->assertSame('landing_page', $payload['channel_type']);
    }

    public function test_a_frozen_submission_can_no_longer_be_modified(): void
    {
        $submission = $this->submission();
        $submission->toJson();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is frozen');

        $submission->content('nội dung khác');
    }
}
