<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['price.outlier_threshold_pct', 20, 'Ambang perubahan harga (%) terhadap harga terbit sebelumnya; di atasnya wajib diverifikasi dengan catatan'],
        ];

        foreach ($settings as [$key, $value, $description]) {
            if (! DB::table('system_settings')->where('key', $key)->exists()) {
                DB::table('system_settings')->insert([
                    'key'         => $key,
                    'value'       => json_encode($value),
                    'description' => $description,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }
}
