<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'edumaucherni@gmail.com'],
            ['name' => 'Eduardo', 'password' => 'Eduardo2006!', 'email_verified_at' => now()],
        );
        User::where('email', 'proiezrushdev@gmail.com')->delete();

        $this->call(CatalogSeeder::class);
    }
}
