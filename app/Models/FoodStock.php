<?php

namespace App\Models;

use App\Models\Scopes\LocationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo stok SAAT INI per lokasi dan komoditas.
 * Hanya boleh diubah oleh StockWorkflow::approve() di dalam transaksi dengan penguncian baris.
 * Sumber kebenaran tetap tabel stock_movements; saldo ini dapat diperiksa dengan `php artisan stock:reconcile`.
 */
class FoodStock extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new LocationScope());
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id')->withTrashed();
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
