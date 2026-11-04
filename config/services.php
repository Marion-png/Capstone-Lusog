<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Claude vision, used to read a photographed feeding-attendance sheet.
     * The key is never committed — set ANTHROPIC_API_KEY in .env. With no key
     * the photo-scan route is disabled and the CSV/XLSX import still works.
     */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
    ],

    /*
     * Gemini vision, used to read a photographed or scanned CLASS MASTERLIST
     * on the class adviser's Enroll Student panel, and a photographed, signed
     * Sulat-Pahibalo (App\Support\ConsentFormScanner). The key is never
     * committed — set GEMINI_API_KEY in .env. With no key both readers are
     * disabled; the CSV/XLSX import and the hand-filled consent form are
     * unaffected.
     *
     * The model id is configuration rather than a constant because it is the
     * one thing here that changes without the code changing: a school on a
     * different tier, or a provider that has renamed a model, is a .env edit
     * and not a deployment.
     */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        // Detail given to a photographed consent form. Blank uses the model's
        // default, for a model that refuses this level.
        'media_resolution' => env('GEMINI_MEDIA_RESOLUTION', 'MEDIA_RESOLUTION_ULTRA_HIGH'),
        'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 120),
        'max_upload_kb' => (int) env('GEMINI_MAX_UPLOAD_KB', 10240),
    ],

    /*
     * Calendarific, read for the Philippine public holidays so a holiday is
     * not counted as a feeding day (App\Support\PublicHolidaySync). The key is
     * never committed — set CALENDARIFIC_API_KEY in .env. With no key the
     * sync is disabled and every weekday stays a feeding day, as before.
     *
     * `location` is an ISO 3166-2 code; leave it empty for national holidays
     * only. A regional holiday (BARMM's, a city's) is kept only when it names
     * the location set here.
     */
    'calendarific' => [
        'key' => env('CALENDARIFIC_API_KEY'),
        'endpoint' => env('CALENDARIFIC_ENDPOINT', 'https://calendarific.com/api/v2'),
        'country' => env('CALENDARIFIC_COUNTRY', 'PH'),
        'location' => env('CALENDARIFIC_LOCATION', ''),
        'timeout' => (int) env('CALENDARIFIC_TIMEOUT', 20),
    ],

    /*
     * RxNav (U.S. National Library of Medicine), read by App\Support\RxNormService
     * for the dispense allergy check. Free and keyless; only a medicine name is
     * ever sent. The base URL is here so it can change without a code edit.
     */
    'rxnorm' => [
        'base_url' => env('RXNORM_BASE_URL', 'https://rxnav.nlm.nih.gov/REST'),
    ],

];
