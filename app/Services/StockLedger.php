<?php

namespace App\Services;

use App\Models\StockMovement;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Perhitungan jumlah stok dalam satuan seperseribu (bilangan bulat) agar tidak ada
 * galat pembulatan desimal, serta rekonsiliasi saldo terhadap seluruh mutasi yang disetujui.
 */
class StockLedger
{
    public static function toMilli(string|int|float|null $value): int
    {
        return (int) round(((float) $value) * 1000);
    }

    public static function fromMilli(int $milli): string
    {
        return number_format($milli / 1000, 3, '.', '');
    }

    /** Pengaruh mutasi terhadap saldo (positif menambah, negatif mengurangi), dalam seperseribu. */
    public function effectMilli(StockMovement $movement): int
    {
        $quantity = self::toMilli($movement->quantity);

        return match ($movement->movement_type) {
            'in', 'opening' => $quantity,
            'out'           => -$quantity,
            'reversal'      => -$this->effectMilli(
                StockMovement::withoutGlobalScopes()->findOrFail($movement->reverses_id)
            ),
            default => throw new DomainException('Jenis mutasi tidak dikenal.'),
        };
    }

    /**
     * Membandingkan food_stocks.balance dengan jumlah seluruh mutasi berstatus disetujui.
     *
     * @return list<array{storage_location_id:int, commodity_id:int, balance:string, ledger:string}>
     */
    public function reconcile(): array
    {
        $ledgerRows = DB::table('stock_movements as m')
            ->leftJoin('stock_movements as o', 'o.id', '=', 'm.reverses_id')
            ->where('m.status', 'approved')
            ->selectRaw(
                "m.storage_location_id as loc, m.commodity_id as com, SUM(CASE
                    WHEN m.movement_type IN ('in', 'opening') THEN m.quantity
                    WHEN m.movement_type = 'out' THEN -m.quantity
                    WHEN m.movement_type = 'reversal' AND o.movement_type IN ('in', 'opening') THEN -m.quantity
                    WHEN m.movement_type = 'reversal' AND o.movement_type = 'out' THEN m.quantity
                    ELSE 0 END) as total"
            )
            ->groupBy('m.storage_location_id', 'm.commodity_id')
            ->get();

        $expected = [];
        foreach ($ledgerRows as $row) {
            $expected[$row->loc . '|' . $row->com] = self::toMilli($row->total);
        }

        $actual = [];
        foreach (DB::table('food_stocks')->get(['storage_location_id', 'commodity_id', 'balance']) as $row) {
            $actual[$row->storage_location_id . '|' . $row->commodity_id] = self::toMilli($row->balance);
        }

        $mismatches = [];
        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $key) {
            $ledger  = $expected[$key] ?? 0;
            $balance = $actual[$key] ?? 0;

            if ($ledger !== $balance) {
                [$loc, $com] = explode('|', $key);
                $mismatches[] = [
                    'storage_location_id' => (int) $loc,
                    'commodity_id'        => (int) $com,
                    'balance'             => self::fromMilli($balance),
                    'ledger'              => self::fromMilli($ledger),
                ];
            }
        }

        return $mismatches;
    }
}
