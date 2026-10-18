<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Pasar CONTOH untuk uji coba lokal. Ganti dengan daftar pasar sebenarnya dari DKP.
 */
class SampleMarketSeeder extends Seeder
{
    public function run(): void
    {
        $samples = [
            ['Bangkinang', 'Pasar Contoh Bangkinang'],
            ['Kampar', 'Pasar Contoh Kampar'],
        ];

        foreach ($samples as [$districtName, $marketName]) {
            $districtId = DB::table('districts')->where('name', $districtName)->value('id');

            if (! $districtId) {
                continue;
            }

            if (! DB::table('markets')->where('district_id', $districtId)->where('name', $marketName)->exists()) {
                DB::table('markets')->insert([
                    'district_id' => $districtId,
                    'name'        => $marketName,
                    'market_type' => 'tradisional',
                    'is_active'   => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }
}
