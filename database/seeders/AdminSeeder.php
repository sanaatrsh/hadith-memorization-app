<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the administrator account used to sign in through POST
 * /v1/auth/admin/login.
 *
 * Credentials come from config('athar.admin'), which reads ADMIN_NAME,
 * ADMIN_EMAIL and ADMIN_PASSWORD from the environment. A real deployment sets
 * those in .env; left unset, they default to the same admin@athar.test /
 * password pair ArabicDemoSeeder uses for local development, so the two never
 * disagree about who the admin is.
 *
 * Runs after ArabicDemoSeeder in DatabaseSeeder so an explicitly configured
 * password wins over the demo seeder's hardcoded one.
 *
 * It is idempotent: keyed on email, rerunning db:seed updates the same row
 * instead of creating a second admin.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{name:string, email:string, password:string} $admin */
        $admin = config('athar.admin');

        User::updateOrCreate(
            ['email' => $admin['email']],
            [
                'name' => $admin['name'],
                'password' => Hash::make($admin['password']),
                'role' => UserRole::Admin,
                'is_active' => true,
            ],
        );
    }
}
