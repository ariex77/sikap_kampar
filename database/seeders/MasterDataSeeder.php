<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOrganizations();
        $this->seedUnits();
        $this->seedCommodities();
        $this->seedDistricts();
    }

    /** Menyisipkan baris hanya jika belum ada, sehingga seeder aman dijalankan berulang. */
    private function insertIfMissing(string $table, array $where, array $values): void
    {
        if (! DB::table($table)->where($where)->exists()) {
            DB::table($table)->insert($where + $values + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedOrganizations(): void
    {
        $this->insertIfMissing('organizations',
            ['name' => 'Dinas Ketahanan Pangan Kabupaten Kampar'],
            ['organization_type' => 'dkp', 'is_active' => true]
        );
    }

    private function seedUnits(): void
    {
        foreach ([['kg', 'Kilogram'], ['l', 'Liter'], ['butir', 'Butir'], ['ikat', 'Ikat']] as [$code, $name]) {
            $this->insertIfMissing('units', ['code' => $code], ['name' => $name]);
        }
    }

    private function seedCommodities(): void
    {
        $kg = DB::table('units')->where('code', 'kg')->value('id');
        $liter = DB::table('units')->where('code', 'l')->value('id');

        // [kode, nama, kategori, id satuan]
        $items = [
            ['BRS-PRM', 'Beras Premium', 'Beras', $kg],
            ['BRS-MDM', 'Beras Medium', 'Beras', $kg],
            ['CBM',     'Cabai Merah', 'Hortikultura', $kg],
            ['CBR',     'Cabai Rawit', 'Hortikultura', $kg],
            ['BWM',     'Bawang Merah', 'Hortikultura', $kg],
            ['BWP',     'Bawang Putih', 'Hortikultura', $kg],
            ['DGS',     'Daging Sapi', 'Protein Hewani', $kg],
            ['DGA',     'Daging Ayam Ras', 'Protein Hewani', $kg],
            ['TLR',     'Telur Ayam Ras', 'Protein Hewani', $kg],
            ['GLA',     'Gula Pasir', 'Pangan Pokok Lain', $kg],
            ['MGC',     'Minyak Goreng Curah', 'Pangan Pokok Lain', $liter],
            ['TPT',     'Tepung Terigu', 'Pangan Pokok Lain', $kg],
        ];

        foreach ($items as [$code, $name, $category, $unitId]) {
            $this->insertIfMissing('commodities', ['code' => $code], [
                'name' => $name,
                'category' => $category,
                'default_unit_id' => $unitId,
                'is_strategic' => true,
                'is_active' => true,
            ]);
        }
    }

    private function seedDistricts(): void
    {
        // PENTING: kode 'TMP-xx' hanyalah penanda sementara.
        // Ganti dengan kode wilayah resmi (Kemendagri/BPS) sebelum dipakai produksi.
        $names = [
            'Bangkinang', 'Bangkinang Kota', 'Gunung Sahilan', 'Kampar', 'Kampar Kiri',
            'Kampar Kiri Hilir', 'Kampar Kiri Hulu', 'Kampar Kiri Tengah', 'Kampar Timur',
            'Kampar Utara', 'Koto Kampar Hulu', 'Kuok', 'Perhentian Raja', 'Rumbio Jaya',
            'Salo', 'Siak Hulu', 'Tambang', 'Tapung', 'Tapung Hilir', 'Tapung Hulu',
            'XIII Koto Kampar',
        ];

        foreach ($names as $i => $name) {
            $this->insertIfMissing('districts', ['name' => $name], [
                'code' => sprintf('TMP-%02d', $i + 1),
                'is_active' => true,
            ]);
        }
    }
}
