<?php

namespace Tests\Feature\Database;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_an_admin_from_config_idempotently(): void
    {
        $this->seed(AdminSeeder::class);
        $this->seed(AdminSeeder::class);

        $this->assertSame(1, User::where('role', UserRole::Admin)->count());

        $admin = User::where('email', config('athar.admin.email'))->sole();

        $this->assertSame(config('athar.admin.name'), $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check(config('athar.admin.password'), $admin->password));
    }

    public function test_a_custom_environment_password_overrides_the_default(): void
    {
        config(['athar.admin.email' => 'root@example.com', 'athar.admin.password' => 'S3cure!Pass']);

        $this->seed(AdminSeeder::class);

        $admin = User::where('email', 'root@example.com')->sole();

        $this->assertTrue(Hash::check('S3cure!Pass', $admin->password));
    }
}
