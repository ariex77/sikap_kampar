<?php

namespace App\Services;

use App\Models\FoodStock;
use App\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Ringkasan stok untuk dashboard. Seluruh angka dibatasi cakupan lokasi pengguna (LocationScope)
 * dan memakai satuan seperseribu (bilangan bulat) agar tidak ada galat pembulatan.
 */
class StockSummary
{
    /**
     * Saldo saat ini per komoditas, dijumlahkan dari semua lokasi dalam cakupan pengguna.
     *
     * @return Collection<int, array{commodity_id: int, name: string, unit: string, balance_milli: int, locations: int}>
     */
    public function balances(): Collection
    {
        return FoodStock::query()
            ->join('commodities', 'commodities.id', '=', 'food_stocks.commodity_id')
            ->join('units', 'units.id', '=', 'food_stocks.unit_id')
            ->selectRaw('food_stocks.commodity_id, commodities.name as commodity_name, units.code as unit_code, SUM(food_stocks.balance) as total, COUNT(*) as locations')
            ->groupBy('food_stocks.commodity_id', 'commodities.name', 'units.code')
            ->orderBy('commodities.name')
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'commodity_id'  => (int) $r->commodity_id,
                'name'          => (string) $r->commodity_name,
                'unit'          => (string) $r->unit_code,
                'balance_milli' => StockLedger::toMilli($r->total),
                'locations'     => (int) $r->locations,
            ]);
    }

    /**
     * Penerimaan dan penyaluran bersih per komoditas pada rentang tanggal, hanya dari mutasi yang
     * sudah disetujui. Koreksi mengurangi jenis mutasi yang dikoreksinya dan dihitung pada tanggal
     * koreksi. Stok awal tidak dihitung sebagai penerimaan.
     *
     * @return Collection<int, array{inflow_milli: int, outflow_milli: int}> dikunci menurut commodity_id
     */
    public function flows(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return StockMovement::query()
            ->leftJoin('stock_movements as orig', 'orig.id', '=', 'stock_movements.reverses_id')
            ->where('stock_movements.status', 'approved')
            ->where('stock_movements.movement_date', '>=', $from->toDateString())
            ->where('stock_movements.movement_date', '<', $to->copy()->addDay()->toDateString())
            ->selectRaw("stock_movements.commodity_id,
                SUM(CASE
                    WHEN stock_movements.movement_type = 'in' THEN stock_movements.quantity
                    WHEN stock_movements.movement_type = 'reversal' AND orig.movement_type = 'in' THEN -stock_movements.quantity
                    ELSE 0 END) as inflow,
                SUM(CASE
                    WHEN stock_movements.movement_type = 'out' THEN stock_movements.quantity
                    WHEN stock_movements.movement_type = 'reversal' AND orig.movement_type = 'out' THEN -stock_movements.quantity
                    ELSE 0 END) as outflow")
            ->groupBy('stock_movements.commodity_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => [
                (int) $r->commodity_id => [
                    'inflow_milli'  => StockLedger::toMilli($r->inflow),
                    'outflow_milli' => StockLedger::toMilli($r->outflow),
                ],
            ]);
    }
}
