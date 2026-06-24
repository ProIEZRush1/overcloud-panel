<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'proiezrushdev@gmail.com'],
            ['name' => 'Eduardo', 'password' => 'Overcloud2026!', 'email_verified_at' => now()],
        );

        $this->call(CatalogSeeder::class);
    }
}
