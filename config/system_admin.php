<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System Admin Credentials
    |--------------------------------------------------------------------------
    |
    | The System Admin does not have a row in `accounts` — it is the account
    | that approves those — so its credentials come from the environment.
    |
    | These MUST be read here rather than with env() at the point of use:
    | `php artisan config:cache` stops loading the .env file, so a runtime
    | env() call would return the fallback and silently restore the default
    | credentials on a production deploy.
    |
    | Prefer SYSTEM_ADMIN_PASSWORD_HASH (a bcrypt hash, e.g. from
    | `php artisan tinker --execute="echo bcrypt('your-password');"`). The
    | plaintext SYSTEM_ADMIN_PASSWORD is honoured for existing installs.
    |
    */

    'username' => env('SYSTEM_ADMIN_USERNAME', 'systemadmin'),

    'password' => env('SYSTEM_ADMIN_PASSWORD', 'admin123'),

    'password_hash' => env('SYSTEM_ADMIN_PASSWORD_HASH'),

];
