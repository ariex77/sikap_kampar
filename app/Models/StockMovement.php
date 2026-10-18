<?php

namespace App\Models;

use App\Models\Scopes\LocationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Mutasi stok: catatan tak berubah (append-only) setelah diajukan.
 * Koreksi dilakukan dengan mutasi pembalik (movement_type = reversal, reverses_id),
 * bukan dengan mengubah atau menghapus mutasi lama.
 */
class StockMovement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'movement_date' => 'date',
        'quantity'      => 'decimal:3',
        'verified_at'   => 'datetime',
        'approved_at'   => 'datetime',
    ];

    public const TYPE_LABELS = [
        'in'       => 'Penerimaan',
        'out'      => 'Pengeluaran',
        'opening'  => 'Stok awal',
        'reversal' => 'Koreksi (pembalik)',
    ];

    public const STATUS_LABELS = [
        'draft'     => 'Draf',
        'submitted' => 'Diajukan',
        'verified'  => 'Diverifikasi',
        'approved'  => 'Disetujui',
        'rejected'  => 'Ditolak',
    ];

    /** Kolom yang membentuk makna transaksi; terkunci begitu mutasi keluar dari draf/ditolak. */
    private const IMMUTABLE = [
        'storage_location_id', 'commodity_id', 'unit_id', 'movement_type',
        'quantity', 'movement_date', 'reverses_id', 'created_by', 'uuid',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new LocationScope(hideForeignDrafts: true));

        static::updating(function (self $movement) {
            $original = $movement->getOriginal('status');

            // Mutasi yang sudah disetujui sudah mengubah saldo: tidak ada perubahan apa pun yang boleh terjadi.
            if ($original === 'approved') {
                throw new LogicException('Mutasi yang sudah disetujui tidak boleh diubah. Buat mutasi koreksi.');
            }

            if (in_array($original, ['draft', 'rejected'], true)) {
                return;
            }

            foreach (self::IMMUTABLE as $column) {
                if ($movement->isDirty($column)) {
                    throw new LogicException("Kolom '$column' pada mutasi yang sudah diajukan tidak boleh diubah. Buat mutasi koreksi.");
                }
            }
        });

        static::deleting(function (self $movement) {
            if ($movement->status !== 'draft') {
                throw new LogicException('Hanya draf yang dapat dihapus. Mutasi yang sudah diajukan harus dikoreksi, bukan dihapus.');
            }
        });
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->movement_type] ?? $this->movement_type;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function formattedQuantity(): string
    {
        return rtrim(rtrim(number_format((float) $this->quantity, 3, ',', '.'), '0'), ',');
    }
}
