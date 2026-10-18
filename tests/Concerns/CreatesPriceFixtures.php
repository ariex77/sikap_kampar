<?php

namespace Tests\Concerns;

use App\Models\PriceObservation;
use App\Models\User;
use App\Services\DataScope;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Data awal bersama untuk tes modul harga:
 * dua kecamatan (A dan B), satu pasar di tiap kecamatan, satu komoditas, dan satu satuan.
 */
trait CreatesPriceFixtures
{
    protected int $districtA;
    protected int $districtB;
    protected int $marketA;
    protected int $marketB;
    protected int $commodityId;
    protected int $unitId;

    /**
     * Pengaman: RefreshDatabase MENGHAPUS seluruh tabel. Tes hanya boleh berjalan
     * pada SQLite atau database yang namanya mengandung kata "test".
     */
    protected function guardTestDatabase(): void
    {
        $default = config('database.default');
        $database = (string) config("database.connections.$default.database");

        if ($default !== 'sqlite' && ! str_contains(strtolower($database), 'test')) {
            throw new RuntimeException(
                "Tes dihentikan: koneksi '$default' mengarah ke database '$database', bukan database uji. " .
                'Periksa phpunit.xml (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).'
            );
        }
    }

    protected function setUpPriceFixtures(): void
    {
        DataScope::flush();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $ts = ['created_at' => now(), 'updated_at' => now()];

        $this->unitId = DB::table('units')->insertGetId(['code' => 'kg', 'name' => 'Kilogram'] + $ts);

        $this->districtA = DB::table('districts')->insertGetId(['code' => 'T-A', 'name' => 'Kecamatan A', 'is_active' => true] + $ts);
        $this->districtB = DB::table('districts')->insertGetId(['code' => 'T-B', 'name' => 'Kecamatan B', 'is_active' => true] + $ts);

        $this->marketA = DB::table('markets')->insertGetId(['district_id' => $this->districtA, 'name' => 'Pasar A', 'is_active' => true] + $ts);
        $this->marketB = DB::table('markets')->insertGetId(['district_id' => $this->districtB, 'name' => 'Pasar B', 'is_active' => true] + $ts);

        $this->commodityId = DB::table('commodities')->insertGetId([
            'code' => 'BRS', 'name' => 'Beras', 'category' => 'Beras',
            'default_unit_id' => $this->unitId, 'is_strategic' => true, 'is_active' => true,
        ] + $ts);
    }

    /**
     * @param array<int, array> $scopes  tiap elemen: [tipe, id, domain = '*', valid_from, valid_until]
     */
    protected function makeUser(string $role, array $scopes = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        foreach ($scopes as $s) {
            DB::table('user_scopes')->insert([
                'user_id'     => $user->id,
                'scope_type'  => $s[0],
                'scope_id'    => $s[1] ?? null,
                'data_domain' => $s[2] ?? '*',
                'valid_from'  => $s[3] ?? null,
                'valid_until' => $s[4] ?? null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        DataScope::flush();

        return $user;
    }

    protected function makePrice(User $creator, array $attrs = []): PriceObservation
    {
        return PriceObservation::withoutGlobalScopes()->create(array_merge([
            'uuid'         => (string) Str::uuid(),
            'market_id'    => $this->marketA,
            'district_id'  => $this->districtA,
            'commodity_id' => $this->commodityId,
            'unit_id'      => $this->unitId,
            'observed_on'  => today()->toDateString(),
            'price'        => 15000,
            'source_type'  => 'field',
            'status'       => 'draft',
            'created_by'   => $creator->id,
        ], $attrs));
    }
}
