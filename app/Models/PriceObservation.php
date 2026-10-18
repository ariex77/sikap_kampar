<?php

namespace App\Models;

use App\Models\Scopes\PriceScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceObservation extends Model
{
    use SoftDeletes;

    // Kolom diisi secara eksplisit di service/komponen, bukan dari input mentah.
    protected $guarded = ['id'];

    protected $casts = [
        'observed_on'    => 'date',
        'price'          => 'decimal:2',
        'previous_price' => 'decimal:2',
        'is_outlier'     => 'boolean',
        'submitted_at'   => 'datetime',
        'verified_at'    => 'datetime',
    ];

    public const STATUS_LABELS = [
        'draft'     => 'Draf',
        'submitted' => 'Diajukan',
        'verified'  => 'Diverifikasi',
        'published' => 'Terbit',
        'rejected'  => 'Ditolak',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new PriceScope());
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Perubahan terhadap harga sebelumnya, dalam persen (null bila tidak ada pembanding). */
    public function changePercent(): ?float
    {
        if ($this->previous_price === null || (float) $this->previous_price <= 0) {
            return null;
        }

        return round(((float) $this->price - (float) $this->previous_price) / (float) $this->previous_price * 100, 1);
    }
}
