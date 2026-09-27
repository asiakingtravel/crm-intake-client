# asiakingtravel/crm-intake-client

Adapter phía website: gửi form lead sang CRM intake API (DA-121).

- Endpoint: `POST {base_url}/api/v1/crm/intake-submissions`
- Xác thực: header `Authorization: Bearer <token>` — token **không bao giờ** nằm trong body/URL/log
- Payload được **đóng băng** lúc tạo submission (gồm `received_at` và `external_reference`); mọi lần
  gửi lại là byte-identical, nên CRM không trả `409 idempotency_key_payload_conflict`

## Cài đặt

Package private, cài qua VCS repository:

```jsonc
// composer.json của web chính
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:asiakingtravel/crm-intake-client.git"
        }
    ]
}
```

```bash
composer require asiakingtravel/crm-intake-client:^0.1
```

ServiceProvider được auto-discovery. Publish config nếu cần sửa:

```bash
php artisan vendor:publish --tag=crm-intake-config
```

Yêu cầu: PHP ≥ 8.0.2, Laravel 9/10/11/12.

## Biến môi trường

```dotenv
CRM_INTAKE_BASE_URL=https://crm-stage.asiakingtravel.com
CRM_INTAKE_TOKEN=
CRM_INTAKE_SOURCE_KEY=
CRM_INTAKE_CHANNEL=website     # website | landing_page (mặc định: website)
CRM_INTAKE_TIMEOUT=10          # giây (mặc định: 10)
```

Mỗi brand dùng **một cặp `source_key` + token riêng**; CRM suy ra brand từ nguồn intake, nên payload
không gửi `company_code` / `selling_brand_code` / `market_code`.

## Gọi từ controller form

```php
<?php

namespace App\Http\Controllers;

use AsiaKingTravel\CrmIntake\IntakeSubmission;
use AsiaKingTravel\CrmIntake\Jobs\SendIntakeSubmission;
use Illuminate\Http\Request;

class ContactFormController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'message' => ['required', 'string'],
            'service' => ['nullable', 'string'],
            'consent' => ['nullable', 'boolean'],
        ]);

        $submission = IntakeSubmission::make()
            ->languageCode(app()->getLocale())          // bắt buộc, ≤ 5 ký tự
            ->fullName($data['name'] ?? null)
            ->email($data['email'] ?? null)             // email hoặc phone: ít nhất một
            ->phone($data['phone'] ?? null)
            ->content($data['message'])                 // bắt buộc
            ->requestedService($data['service'] ?? null)
            ->campaignReference($request->query('utm_campaign'))
            ->consent($data['consent'] ?? null)
            ->correlationId((string) $request->header('X-Request-Id'))
            ->extension(['form_id' => 'contact-footer']);

        // Đẩy vào queue: payload đóng băng ngay tại đây.
        SendIntakeSubmission::dispatch($submission);

        return back()->with('status', __('Cảm ơn bạn, chúng tôi sẽ liên hệ sớm.'));
    }
}
```

Cần biết kết quả ngay trong request (không qua queue):

```php
use AsiaKingTravel\CrmIntake\IntakeClient;

$result = app(IntakeClient::class)->submit($submission);

$result->successful();        // 201 accepted hoặc 200 duplicate
$result->outcome;             // accepted | duplicate | rejected | unavailable | timeout
$result->httpStatus;          // 201, 200, 422, 429, 500, … (null khi timeout)
$result->submissionUlid;      // chỉ có khi 200/201
$result->inquiryUlid;
$result->retryable;           // true với 429 / 5xx / timeout
$result->retryAfter;          // giây, lấy từ header Retry-After (nếu có)
$result->externalReference;   // ULID website tự sinh cho lượt gửi này
```

## Ánh xạ phản hồi

| HTTP | outcome | retry |
| --- | --- | --- |
| 201 | `accepted` (có `submission_ulid`, `inquiry_ulid`) | không |
| 200 | `duplicate` — coi là thành công | không |
| 400, 401, 403, 404, 409, 422 | `rejected` | **không** (job fail ngay với `IntakeRejected`) |
| 429 | `unavailable` | có, tôn trọng `Retry-After` |
| 5xx | `unavailable` | có |
| timeout / không kết nối được | `timeout` | có |

`SendIntakeSubmission`: tối đa **5 lần**, backoff tăng dần `10 → 30 → 90 → 300` giây, và dùng
`Retry-After` nếu CRM gửi kèm.

## Envelope gửi đi

Chỉ đúng các key top-level sau (CRM từ chối cả request nếu có key lạ):

`source_key`, `channel_type`, `language_code`, `received_at`, `external_reference`, `contact`,
`inquiry`, `campaign_reference`, `consent`, `correlation_id`, `extension`

```json
{
    "source_key": "akt-website-vi",
    "channel_type": "website",
    "language_code": "vi",
    "received_at": "2026-09-27T10:21:04.512394Z",
    "external_reference": "01K63V8Q9R7YB1C2D3E4F5G6H7",
    "contact": {
        "full_name": "Nguyễn Văn A",
        "email": "a@example.com",
        "phone": "+84901234567",
        "preferred_language_code": "vi"
    },
    "inquiry": {
        "content": "Tôi muốn đặt tour Hạ Long 3 ngày.",
        "requested_service": "halong-cruise"
    },
    "campaign_reference": "google-ads-2026-q4",
    "consent": { "indicator": true },
    "correlation_id": "web-req-0001",
    "extension": { "form_id": "contact-footer" }
}
```

Field không có giá trị bị **bỏ hẳn**, không gửi `null`. Không gửi `idempotency_key`, `fingerprint`,
`attachments`, `company_code`, `selling_brand_code`, `market_code`.

## Riêng tư

Log chỉ gồm `outcome`, `http_status`, `submission_ulid`, `external_reference`, `correlation_id`.
Token, email, số điện thoại, tên và nội dung form không bao giờ được ghi log, và body phản hồi lỗi
(4xx có thể echo lại dữ liệu người dùng) không được giữ lại trong `IntakeResult` hay message
exception. Mọi chữ ký hàm nhận token đều đánh dấu `#[\SensitiveParameter]`.

## Test

```bash
composer install
vendor/bin/phpunit
```
