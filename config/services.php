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
     * on the class adviser's Enroll Student panel. The key is never committed —
     * set GEMINI_API_KEY in .env. With no key the scan route is disabled and
     * the CSV/XLSX import is unaffected.
     *
     * The model id is configuration rather than a constant because it is the
     * one thing here that changes without the code changing: a school on a
     * different tier, or a provider that has renamed a model, is a .env edit
     * and not a deployment.
     */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 120),
        'max_upload_kb' => (int) env('GEMINI_MAX_UPLOAD_KB', 10240),
    ],

    /*
     * HL7 FHIR R4 outbound exchange — see docs/fhir-interoperability.md.
     *
     * `endpoint` is the receiving server's base URL; a transaction Bundle is
     * POSTed to it. It must be https:// — App\Support\FhirTransmitter refuses
     * anything else and records the refusal, so a mistyped http:// URL can
     * never put a learner's record on the wire in clear text. With no endpoint
     * the exchange screen still previews and downloads bundles; it only
     * cannot send them.
     *
     * Credentials are environment variables and nothing else: a bearer token,
     * or a username and password for HTTP Basic (Mirth Connect's HTTP
     * Listener, for instance). They are never stored in the database and
     * never written to a transmission record or the audit trail.
     *
     * `deidentify` defaults to true: the Patient is sent under a keyed
     * pseudonym with no name, no LRN and the birth year only. Turn it off only
     * for a receiver the school has a data-sharing agreement with — a public
     * test server is not one.
     */
    'fhir' => [
        'endpoint' => env('FHIR_ENDPOINT'),
        'auth_token' => env('FHIR_AUTH_TOKEN'),
        'username' => env('FHIR_USERNAME'),
        'password' => env('FHIR_PASSWORD'),
        'deidentify' => filter_var(env('FHIR_DEIDENTIFY', true), FILTER_VALIDATE_BOOL),
        'auto_transmit' => filter_var(env('FHIR_AUTO_TRANSMIT', false), FILTER_VALIDATE_BOOL),
        'timeout' => (int) env('FHIR_TIMEOUT', 30),
        // The namespace every identifier and local code in a bundle is minted
        // under. It names this application, not DepEd, and must stay stable:
        // receivers match on it to recognise a learner they already hold.
        'system_base' => rtrim((string) env('FHIR_SYSTEM_BASE', 'https://lusog-web-production.up.railway.app/fhir'), '/'),
    ],

];
