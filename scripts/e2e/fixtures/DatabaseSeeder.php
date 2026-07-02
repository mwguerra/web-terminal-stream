<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the e2e admin user used by the Playwright global-setup backdoor.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'E2E Admin',
                'password' => bcrypt('password'),
            ],
        );
    }
}
