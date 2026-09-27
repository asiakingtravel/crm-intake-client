<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CRM intake endpoint
    |--------------------------------------------------------------------------
    |
    | Base URL của CRM. Client sẽ POST tới {base_url}/api/v1/crm/intake-submissions
    |
    */

    'base_url' => env('CRM_INTAKE_BASE_URL', 'https://crm-stage.asiakingtravel.com'),

    /*
    |--------------------------------------------------------------------------
    | Bearer token
    |--------------------------------------------------------------------------
    |
    | Token chỉ được gửi ở header Authorization, không bao giờ nằm trong body/URL
    | và không bao giờ được ghi log. Mỗi brand dùng một cặp source_key + token riêng.
    |
    */

    'token' => env('CRM_INTAKE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Nguồn intake
    |--------------------------------------------------------------------------
    |
    | source_key do CRM cấp cho từng brand/nguồn. channel_type: website hoặc
    | landing_page.
    |
    */

    'source_key' => env('CRM_INTAKE_SOURCE_KEY'),

    'channel_type' => env('CRM_INTAKE_CHANNEL', 'website'),

    /*
    |--------------------------------------------------------------------------
    | Timeout (giây)
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('CRM_INTAKE_TIMEOUT', 10),

];
