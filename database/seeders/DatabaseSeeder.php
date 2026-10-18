<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Urutan penting: peran -> data master -> pengaturan -> pengguna
        $this->call([
            RolePermissionSeeder::class,
            MasterDataSeeder::class,
            SettingSeeder::class,
            UserSeeder::class,
        ]);

        // Data contoh hanya untuk lingkungan pengembangan
        if (app()->environment('local')) {
            $this->call([
                SampleMarketSeeder::class,
                SampleStorageLocationSeeder::class,
            ]);
        }
    }
}
