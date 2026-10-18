<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Lokasi penyimpanan CONTOH untuk uji coba lokal. Ganti dengan gudang/lumbung sebenarnya.
 */
class SampleStorageLocationSeeder extends Seeder
{
    public function run(): void
    {
        $districtId = DB::table('districts')->where('name', 'Bangkinang')->value('id');
        $orgId = DB::table('organizations')->where('name', 'Dinas Ketahanan Pangan Kabupaten Kampar')->value('id');

        if (! $districtId) {
            return;
        }

        if (! DB::table('storage_locations')->where('name', 'Gudang Cadangan Contoh')->exists()) {
            DB::table('storage_locations')->insert([
                'organization_id' => $orgId,
                'district_id'     => $districtId,
                'name'            => 'Gudang Cadangan Contoh',
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }
}
