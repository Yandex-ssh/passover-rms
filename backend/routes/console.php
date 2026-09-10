<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Artisan::command('staff:create-admin {--name=} {--email=}', function () {
    $name = $this->option('name') ?: $this->ask('Name');
    $email = $this->option('email') ?: $this->ask('Email');

    if (! is_string($name) || trim($name) === '' || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A valid name and email are required.');

        return 1;
    }

    if (User::query()->where('email', $email)->exists()) {
        $this->error('A user with that email already exists.');

        return 1;
    }

    $password = $this->secret('Password (minimum 8 characters)');
    if (! is_string($password) || strlen($password) < 8) {
        $this->error('The password must contain at least 8 characters.');

        return 1;
    }

    User::create([
        'name' => trim($name),
        'email' => $email,
        'password' => $password,
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->info('Admin account created successfully.');

    return 0;
})->purpose('Provision an initial active Admin account without default credentials');
