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

];
