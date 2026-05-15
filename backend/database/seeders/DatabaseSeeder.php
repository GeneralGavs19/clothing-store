<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate([
            'email' => env('ADMIN_EMAIL', 'admin@store.local'),
        ], [
            'name' => env('ADMIN_NAME', 'Store Admin'),
            'role' => 'admin',
            'is_active' => true,
            'password' => bcrypt(env('ADMIN_PASSWORD', 'ChangeMe123!')),
        ]);

        $this->call(DemoDataSeeder::class);
    }
}
