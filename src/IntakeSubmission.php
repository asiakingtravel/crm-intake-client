<?php

namespace AsiaKingTravel\CrmIntake;

use AsiaKingTravel\CrmIntake\Exceptions\InvalidIntakeSubmission;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LogicException;
use Symfony\Component\Uid\Ulid;

/**
 * DTO + builder cho một lượt gửi form lead sang CRM (DA-121).
 *
 * received_at và external_reference được sinh NGAY khi tạo submission; toàn bộ
 * payload được "đóng băng" ở lần encode đầu tiên. Mọi lần gửi lại (job retry,
 * kể cả sau khi job được serialize vào queue) dùng lại ĐÚNG chuỗi JSON đó —
 * CRM trả 409 idempotency_key_payload_conflict nếu cùng external_reference mà
 * payload khác.
 */
final class IntakeSubmission
{
    /**
     * Danh sách key top-level duy nhất CRM chấp nhận. CRM từ chối cả request
     * nếu có key lạ, nên payload được lọc và kiểm theo đúng danh sách này.
     *
     * @var array<int, string>
     */
    public const ALLOWED_TOP_LEVEL_KEYS = [
        'source_key',
        'channel_type',
        'language_code',
        'received_at',
        'external_reference',
        'contact',
        'inquiry',
        'campaign_reference',
        'consent',
        'correlation_id',
        'extension',
    ];

    /** @var array<int, string> */
    public const CHANNEL_TYPES = ['website', 'landing_page'];

    public const LANGUAGE_CODE_MAX_LENGTH = 5;

    private string $externalReference;

    private string $receivedAt;

    private ?string $sourceKey = null;

    private ?string $channelType = null;

    private ?string $languageCode = null;

    private ?string $fullName = null;

    private ?string $email = null;

    private ?string $phone = null;

    private ?string $preferredLanguageCode = null;

    private ?string $content = null;

    private ?string $requestedService = null;

    private ?string $campaignReference = null;

    private ?bool $consent = null;

    private ?string $correlationId = null;

    /** @var array<string, mixed> */
    private array $extension = [];

    /** @var array<string, mixed>|null */
    private ?array $frozenPayload = null;

    private ?string $frozenJson = null;

    private function __construct()
    {
        $this->externalReference = (string) new Ulid();
        $this->receivedAt = self::formatTimestamp(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public static function make(): self
    {
        return new self();
    }

    public function sourceKey(?string $sourceKey): self
    {
        return $this->set('sourceKey', self::clean($sourceKey));
    }

    public function channelType(?string $channelType): self
    {
        return $this->set('channelType', self::clean($channelType));
    }

    public function languageCode(?string $languageCode): self
    {
        return $this->set('languageCode', self::clean($languageCode));
    }

    public function fullName(?string $fullName): self
    {
        return $this->set('fullName', self::clean($fullName));
    }

    public function email(?string $email): self
    {
        return $this->set('email', self::clean($email));
    }

    public function phone(?string $phone): self
    {
        return $this->set('phone', self::clean($phone));
    }

    public function preferredLanguageCode(?string $code): self
    {
        return $this->set('preferredLanguageCode', self::clean($code));
    }

    public function content(?string $content): self
    {
        return $this->set('content', self::clean($content));
    }

    public function requestedService(?string $requestedService): self
    {
        return $this->set('requestedService', self::clean($requestedService));
    }

    public function campaignReference(?string $campaignReference): self
    {
        return $this->set('campaignReference', self::clean($campaignReference));
    }

    public function consent(?bool $indicator): self
    {
        return $this->set('consent', $indicator);
    }

    public function correlationId(?string $correlationId): self
    {
        return $this->set('correlationId', self::clean($correlationId));
    }

    /**
     * Ghi đè external_reference (ULID) nếu website đã tự sinh sẵn cho lượt gửi.
     */
    public function externalReference(string $externalReference): self
    {
        return $this->set('externalReference', $externalReference);
    }

    public function receivedAt(DateTimeInterface $receivedAt): self
    {
        return $this->set('receivedAt', self::formatTimestamp($receivedAt));
    }

    /**
     * @param  array<string, mixed>  $extension
     */
    public function extension(array $extension): self
    {
        return $this->set('extension', $extension);
    }

    public function getExternalReference(): string
    {
        return $this->externalReference;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Payload đã đóng băng (build + kiểm ở lần gọi đầu tiên).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        if ($this->frozenPayload === null) {
            $payload = $this->buildPayload();

            $this->frozenJson = self::encode($payload);
            $this->frozenPayload = $payload;
        }

        return $this->frozenPayload;
    }

    /**
     * Chuỗi JSON đã đóng băng — byte-identical qua mọi lần gửi lại.
     */
    public function toJson(): string
    {
        $this->payload();

        return (string) $this->frozenJson;
    }

    public function isFrozen(): bool
    {
        return $this->frozenJson !== null;
    }

    /**
     * Queue chỉ lưu chuỗi JSON đã đóng băng, không lưu từng field, nên worker
     * không thể dựng lại payload khác với lần đầu.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'json' => $this->toJson(),
            'external_reference' => $this->externalReference,
            'correlation_id' => $this->correlationId,
            'received_at' => $this->receivedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $this->frozenJson = (string) $data['json'];
        $this->frozenPayload = json_decode($this->frozenJson, true);
        $this->externalReference = (string) $data['external_reference'];
        $this->correlationId = $data['correlation_id'] ?? null;
        $this->receivedAt = (string) $data['received_at'];
    }

    /**
     * @param  mixed  $value
     */
    private function set(string $property, $value): self
    {
        if ($this->frozenJson !== null) {
            throw new LogicException(sprintf(
                'crm-intake: submission %s is frozen and can no longer be modified.',
                $this->externalReference
            ));
        }

        $this->{$property} = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $sourceKey = $this->sourceKey ?? self::configString('source_key');
        $channelType = $this->channelType ?? self::configString('channel_type') ?? 'website';

        if ($sourceKey === null) {
            throw InvalidIntakeSubmission::missing('source_key');
        }

        if (! in_array($channelType, self::CHANNEL_TYPES, true)) {
            throw InvalidIntakeSubmission::invalid('channel_type', 'expected one of: '.implode('|', self::CHANNEL_TYPES));
        }

        if ($this->languageCode === null) {
            throw InvalidIntakeSubmission::missing('language_code');
        }

        if (mb_strlen($this->languageCode) > self::LANGUAGE_CODE_MAX_LENGTH) {
            throw InvalidIntakeSubmission::invalid('language_code', 'max '.self::LANGUAGE_CODE_MAX_LENGTH.' characters');
        }

        if ($this->content === null) {
            throw InvalidIntakeSubmission::missing('inquiry.content');
        }

        if ($this->email === null && $this->phone === null) {
            throw InvalidIntakeSubmission::contactRequired();
        }

        $contact = self::withoutEmpty([
            'full_name' => $this->fullName,
            'email' => $this->email,
            'phone' => $this->phone,
            'preferred_language_code' => $this->preferredLanguageCode,
        ]);

        $inquiry = self::withoutEmpty([
            'content' => $this->content,
            'requested_service' => $this->requestedService,
        ]);

        $payload = self::withoutEmpty([
            'source_key' => $sourceKey,
            'channel_type' => $channelType,
            'language_code' => $this->languageCode,
            'received_at' => $this->receivedAt,
            'external_reference' => $this->externalReference,
            'contact' => $contact,
            'inquiry' => $inquiry,
            'campaign_reference' => $this->campaignReference,
            'consent' => $this->consent === null ? null : ['indicator' => $this->consent],
            'correlation_id' => $this->correlationId,
            'extension' => $this->extension === [] ? null : (object) $this->extension,
        ]);

        $unknown = array_diff(array_keys($payload), self::ALLOWED_TOP_LEVEL_KEYS);

        if ($unknown !== []) {
            throw InvalidIntakeSubmission::unknownTopLevelKeys(array_values($unknown));
        }

        return $payload;
    }

    /**
     * Bỏ hẳn field không có giá trị — không gửi null, không gửi chuỗi rỗng.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function withoutEmpty(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw InvalidIntakeSubmission::invalid('payload', 'not encodable as UTF-8 JSON: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * RFC 3339 UTC, đúng 6 chữ số thập phân.
     */
    private static function formatTimestamp(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromFormat('U.u', $moment->format('U.u'))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function configString(string $key): ?string
    {
        if (! function_exists('config')) {
            return null;
        }

        $value = config('crm-intake.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
