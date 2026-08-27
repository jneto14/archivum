<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Admin User
    |--------------------------------------------------------------------------
    |
    | Used by the database seeder to create the first user on a fresh
    | self-hosted installation. If ADMIN_PASSWORD is left empty, the seeder
    | generates a random password and prints it once during seeding.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Workspace
    |--------------------------------------------------------------------------
    |
    | When disabled, the installation transparently uses a single default
    | Workspace (seeded on first install) instead of exposing workspace
    | creation/switching. The Workspace model and tables still exist either
    | way.
    |
    */

    'multi_workspace_enabled' => env('MULTI_WORKSPACE_ENABLED', true),

    'default_workspace_name' => env('DEFAULT_WORKSPACE_NAME', 'Default Workspace'),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Disk used to store Document attachments (scans, photos, PDFs). Defaults
    | to the private "local" disk rather than "public", since documents may
    | contain sensitive content. Set to "s3" (pointed at AWS S3 or a
    | MinIO-compatible endpoint via the AWS_* env vars) for non-local
    | deployments.
    |
    */

    'attachments' => [
        'disk' => env('ATTACHMENTS_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Languages users may pick as their preferred locale, keyed by IETF
    | language tag. The application's default locale (config('app.locale'))
    | is used whenever a user hasn't chosen one.
    |
    */

    'locales' => [
        'en' => 'English',
        'pt' => 'Português',
    ],

];
