<?php

namespace Tests\Concerns;

use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockWorkflow;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data awal tes stok: dua lokasi (di Kecamatan A dan B) dan tiga akun dengan
 * peran berbeda: penginput (op), pemeriksa (ver), dan penyetuju (boss).
 */
trait CreatesStockFixtures
{
    use CreatesPriceFixtures;

    protected int $locA;
    protected int $locB;
    protected User $op;
    protected User $ver;
    protected User $boss;

    protected function setUpStockFixtures(): void
    {
        $this->setUpPriceFixtures();

        $ts = ['created_at' => now(), 'updated_at' => now()];

        $this->locA = DB::table('storage_locations')->insertGetId(['district_id' => $this->districtA, 'name' => 'Gudang A', 'is_active' => true] + $ts);
        $this->locB = DB::table('storage_locations')->insertGetId(['district_id' => $this->districtB, 'name' => 'Gudang B', 'is_active' => true] + $ts);

        $this->op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $this->ver = $this->makeUser('verifikator-dkp', [['kabupaten']]);
        $this->boss = $this->makeUser('kepala-dinas', [['kabupaten']]);
    }

    /** Membuat draf mutasi milik $this->op (tanpa melalui alur). */
    protected function makeMovement(array $attrs = []): StockMovement
    {
        return StockMovement::withoutGlobalScopes()->create(array_merge([
            'uuid'                => (string) Str::uuid(),
            'storage_location_id' => $this->locA,
            'commodity_id'        => $this->commodityId,
            'unit_id'             => $this->unitId,
            'movement_type'       => 'in',
            'quantity'            => 100,
            'movement_date'       => today()->toDateString(),
            'reference_no'        => 'BA-001',
            'counterparty'        => 'Pemasok',
            'status'              => 'draft',
            'created_by'          => $this->op->id,
        ], $attrs));
    }

    /** Menjalankan ajukan -> verifikasi -> setujui lewat service. */
    protected function approveMovement(StockMovement $movement): StockMovement
    {
        $workflow = app(StockWorkflow::class);
        $workflow->submit($movement, $this->op);
        $workflow->verify($movement, $this->ver);
        $workflow->approve($movement, $this->boss);

        return $movement->fresh();
    }

    /** Membuat dan langsung menyetujui satu mutasi. */
    protected function movement(string $type, string|int|float $quantity, array $attrs = []): StockMovement
    {
        return $this->approveMovement($this->makeMovement(['movement_type' => $type, 'quantity' => $quantity] + $attrs));
    }

    protected function balance(?int $location = null): float
    {
        return (float) DB::table('food_stocks')
            ->where('storage_location_id', $location ?? $this->locA)
            ->where('commodity_id', $this->commodityId)
            ->value('balance');
    }

    protected function assertDomainError(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Seharusnya melempar DomainException.');
    }
}
