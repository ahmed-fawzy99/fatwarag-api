<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SourceSeeder::class);
        $this->call(CommonQuestionSeeder::class);

        if (config('fatwarag.admin.email') && config('fatwarag.admin.password')) {
            User::query()->updateOrCreate(
                ['email' => config('fatwarag.admin.email')],
                ['name' => 'Admin', 'password' => config('fatwarag.admin.password')],
            );
        }
    }
}
