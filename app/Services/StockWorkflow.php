<?php

namespace App\Services;

use App\Models\FoodStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alur mutasi stok: draf -> diajukan -> diverifikasi -> disetujui.
 *
 * Jaminan utama:
 *  - Saldo (food_stocks) HANYA berubah saat mutasi disetujui, di dalam satu transaksi,
 *    dengan baris saldo dikunci (SELECT ... FOR UPDATE) sehingga dua persetujuan yang
 *    bersamaan diproses satu per satu. Saldo tidak pernah boleh negatif.
 *  - Penginput, pemeriksa, dan penyetuju harus tiga orang yang berbeda.
 *  - Mutasi tidak diubah atau dihapus; kesalahan dikoreksi dengan mutasi pembalik.
 */
class StockWorkflow
{
    public function __construct(private StockLedger $ledger)
    {
    }

    public function submit(StockMovement $movement, User $by): void
    {
        DB::transaction(function () use ($movement, $by) {
            $m = $this->lock($movement);

            if ((int) $m->created_by !== (int) $by->id) {
                throw new DomainException('Hanya pembuat mutasi yang dapat mengajukan.');
            }
            if (! in_array($m->status, ['draft', 'rejected'], true)) {
                throw new DomainException('Hanya draf atau mutasi yang ditolak yang dapat diajukan.');
            }
            if (StockLedger::toMilli($m->quantity) <= 0) {
                throw new DomainException('Jumlah harus lebih dari nol.');
            }

            $this->assertPeriodOpen($m->movement_date);

            $from = $m->status;
            $m->forceFill([
                'status'           => 'submitted',
                'rejection_reason' => null,
                'verified_by'      => null,
                'verified_at'      => null,
            ])->save();

            $this->history($m, $from, 'submitted', $by, null);
            Audit::log('submitted', 'stock_movement', $m->id, ['status' => $from], ['status' => 'submitted']);
        });
    }

    public function verify(StockMovement $movement, User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($movement, $by, $note) {
            $m = $this->lock($movement);

            if ($m->status !== 'submitted') {
                throw new DomainException('Hanya mutasi berstatus Diajukan yang dapat diverifikasi.');
            }
            if ((int) $m->created_by === (int) $by->id) {
                throw new DomainException('Anda tidak dapat memverifikasi mutasi yang Anda input sendiri.');
            }

            $this->assertPeriodOpen($m->movement_date);

            $m->forceFill(['status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now()])->save();

            $this->history($m, 'submitted', 'verified', $by, $note);
            Audit::log('verified', 'stock_movement', $m->id, ['status' => 'submitted'], ['status' => 'verified']);
        });
    }

    /**
     * Menyetujui mutasi dan memperbarui saldo. Inti konsistensi stok.
     *
     * Urutan kunci selalu sama (baris mutasi, lalu baris saldo). Seluruh pembacaan pembanding
     * (periode, stok awal, mutasi asal) dilakukan SETELAH baris saldo terkunci, sehingga dua
     * persetujuan bersamaan untuk stok yang sama diproses satu per satu, dan yang kedua selalu
     * melihat hasil yang pertama.
     *
     * Jangan memanggil metode ini di dalam transaksi lain: percobaan ulang otomatis saat deadlock
     * hanya berlaku pada transaksi terluar.
     */
    public function approve(StockMovement $movement, User $by, ?string $note = null): void
    {
        // Baris saldo dibuat DI LUAR transaksi persetujuan (lihat ensureStockRow).
        $this->ensureStockRow($movement);

        DB::transaction(function () use ($movement, $by, $note) {
            $m = $this->lock($movement);

            if ($m->status !== 'verified') {
                throw new DomainException('Hanya mutasi berstatus Diverifikasi yang dapat disetujui.');
            }
            if ((int) $m->created_by === (int) $by->id) {
                throw new DomainException('Anda tidak dapat menyetujui mutasi yang Anda input sendiri.');
            }
            if ((int) $m->verified_by === (int) $by->id) {
                throw new DomainException('Pemeriksa dan penyetuju harus orang yang berbeda.');
            }
            if (StockLedger::toMilli($m->quantity) <= 0) {
                throw new DomainException('Jumlah harus lebih dari nol.');
            }

            $stock = FoodStock::withoutGlobalScopes()
                ->where('storage_location_id', $m->storage_location_id)
                ->where('commodity_id', $m->commodity_id)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                throw new DomainException('Baris saldo belum tersedia. Silakan ulangi persetujuan.');
            }
            if ((int) $stock->unit_id !== (int) $m->unit_id) {
                throw new DomainException('Satuan mutasi tidak sama dengan satuan saldo stok.');
            }

            // Pembacaan di bawah ini sengaja setelah kunci saldo diperoleh.
            $this->assertPeriodOpen($m->movement_date);
            $this->assertTypeRules($m);

            $before = StockLedger::toMilli($stock->balance);
            $effect = $this->ledger->effectMilli($m);
            $after  = $before + $effect;

            if ($after < 0) {
                throw new DomainException(sprintf(
                    'Saldo tidak mencukupi. Saldo saat ini %s, mutasi ini mengurangi %s.',
                    StockLedger::fromMilli($before),
                    StockLedger::fromMilli(abs($effect))
                ));
            }

            $stock->forceFill([
                'balance'          => StockLedger::fromMilli($after),
                'version'          => (int) $stock->version + 1,
                'last_movement_id' => $m->id,
            ])->save();

            $m->forceFill(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()])->save();

            $this->history($m, 'verified', 'approved', $by, $note);
            Audit::log(
                'approved',
                'stock_movement',
                $m->id,
                ['status' => 'verified', 'balance' => StockLedger::fromMilli($before)],
                ['status' => 'approved', 'balance' => StockLedger::fromMilli($after)]
            );
        }, 3);
    }

    /** Menolak: oleh pemeriksa (saat Diajukan) atau oleh penyetuju (saat Diverifikasi). */
    public function reject(StockMovement $movement, User $by, string $reason): void
    {
        DB::transaction(function () use ($movement, $by, $reason) {
            $m = $this->lock($movement);

            if (! in_array($m->status, ['submitted', 'verified'], true)) {
                throw new DomainException('Hanya mutasi berstatus Diajukan atau Diverifikasi yang dapat ditolak.');
            }
            if ((int) $m->created_by === (int) $by->id) {
                throw new DomainException('Anda tidak dapat menolak mutasi yang Anda input sendiri.');
            }
            if ($m->status === 'verified' && (int) $m->verified_by === (int) $by->id) {
                throw new DomainException('Pemeriksa tidak dapat menolak mutasi yang sudah ia verifikasi sendiri.');
            }

            $from = $m->status;
            // Verifikasi gugur saat mutasi dikembalikan; siapa yang memverifikasi tetap tercatat di approval_histories.
            $m->forceFill([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
                'verified_by'      => null,
                'verified_at'      => null,
            ])->save();

            $this->history($m, $from, 'rejected', $by, $reason);
            Audit::log('rejected', 'stock_movement', $m->id, ['status' => $from], ['status' => 'rejected', 'reason' => $reason]);
        });
    }

    /**
     * Membuat draf mutasi pembalik untuk mengoreksi mutasi yang sudah disetujui.
     * Draf ini harus melewati verifikasi dan persetujuan seperti mutasi biasa.
     */
    public function createReversal(StockMovement $original, User $by, string $reason, ?CarbonInterface $date = null): StockMovement
    {
        return DB::transaction(function () use ($original, $by, $reason, $date) {
            // Mengunci mutasi asal membuat dua permintaan koreksi bersamaan diproses berurutan.
            $o = $this->lock($original);

            if ($o->status !== 'approved') {
                throw new DomainException('Hanya mutasi yang sudah disetujui yang dapat dikoreksi.');
            }
            if ($o->movement_type === 'reversal') {
                throw new DomainException('Mutasi koreksi tidak dapat dikoreksi lagi. Buat mutasi baru bila perlu.');
            }

            $inProgress = StockMovement::withoutGlobalScopes()
                ->where('reverses_id', $o->id)
                ->whereIn('status', ['draft', 'submitted', 'verified', 'approved'])
                ->exists();
            if ($inProgress) {
                throw new DomainException('Mutasi ini sudah memiliki koreksi yang sedang berjalan atau selesai.');
            }

            $date ??= today();
            $this->assertPeriodOpen($date);

            $reversal = StockMovement::withoutGlobalScopes()->create([
                'uuid'                => (string) Str::uuid(),
                'storage_location_id' => $o->storage_location_id,
                'commodity_id'        => $o->commodity_id,
                'unit_id'             => $o->unit_id,
                'movement_type'       => 'reversal',
                'quantity'            => $o->quantity,
                'movement_date'       => $date->toDateString(),
                'reference_no'        => $o->reference_no,
                'notes'               => $reason,
                'reverses_id'         => $o->id,
                'status'              => 'draft',
                'created_by'          => $by->id,
            ]);

            Audit::log('created', 'stock_movement', $reversal->id, null, ['reverses_id' => $o->id, 'reason' => $reason]);

            return $reversal;
        });
    }

    public function deleteDraft(StockMovement $movement, User $by): void
    {
        DB::transaction(function () use ($movement, $by) {
            $m = $this->lock($movement);

            if ($m->status !== 'draft' || (int) $m->created_by !== (int) $by->id) {
                throw new DomainException('Hanya draf milik Anda yang dapat dihapus.');
            }

            Audit::log('deleted', 'stock_movement', $m->id, $m->only(['storage_location_id', 'commodity_id', 'movement_type', 'quantity']), null);
            $m->delete();
        });
    }

    // ---------------------------------------------------------------

    /**
     * Memastikan baris saldo ada, di luar transaksi persetujuan (autocommit).
     *
     * Jika dibuat di dalam transaksi yang sama dengan SELECT ... FOR UPDATE, MySQL dapat mengalami
     * deadlock: INSERT IGNORE yang gagal karena duplikat menahan kunci bersama, lalu dua transaksi
     * sama-sama menunggu untuk meningkatkannya menjadi kunci eksklusif. Baris bersaldo 0 tidak
     * memengaruhi rekonsiliasi (selisih dihitung terhadap mutasi yang disetujui).
     */
    private function ensureStockRow(StockMovement $movement): void
    {
        $m = StockMovement::withoutGlobalScopes()->find($movement->id);

        if (! $m || $m->status !== 'verified') {
            return; // pemeriksaan sebenarnya tetap dilakukan di dalam transaksi
        }

        DB::table('food_stocks')->insertOrIgnore([
            'storage_location_id' => $m->storage_location_id,
            'commodity_id'        => $m->commodity_id,
            'unit_id'             => $m->unit_id,
            'balance'             => 0,
            'version'             => 0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function lock(StockMovement $movement): StockMovement
    {
        return StockMovement::withoutGlobalScopes()->lockForUpdate()->findOrFail($movement->id);
    }

    private function assertPeriodOpen(CarbonInterface $date): void
    {
        $locked = DB::table('reporting_periods')
            ->where('status', 'locked')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();

        if ($locked) {
            throw new DomainException('Periode pelaporan untuk tanggal ini sudah dikunci.');
        }
    }

    private function assertTypeRules(StockMovement $m): void
    {
        $approvedReversals = fn ($q) => $q->select('reverses_id')
            ->from('stock_movements')
            ->where('movement_type', 'reversal')
            ->where('status', 'approved')
            ->whereNotNull('reverses_id');

        if ($m->movement_type === 'opening') {
            $existing = StockMovement::withoutGlobalScopes()
                ->where('storage_location_id', $m->storage_location_id)
                ->where('commodity_id', $m->commodity_id)
                ->where('movement_type', 'opening')
                ->where('status', 'approved')
                ->where('id', '!=', $m->id)
                ->whereNotIn('id', $approvedReversals)
                ->exists();

            if ($existing) {
                throw new DomainException('Stok awal untuk lokasi dan komoditas ini sudah ada. Gunakan penerimaan atau koreksi.');
            }
        }

        if ($m->movement_type === 'reversal') {
            $original = StockMovement::withoutGlobalScopes()->find($m->reverses_id);

            if (! $original || $original->status !== 'approved' || $original->movement_type === 'reversal') {
                throw new DomainException('Mutasi yang dikoreksi tidak valid.');
            }

            $alreadyReversed = StockMovement::withoutGlobalScopes()
                ->where('reverses_id', $original->id)
                ->where('status', 'approved')
                ->where('id', '!=', $m->id)
                ->exists();

            if ($alreadyReversed) {
                throw new DomainException('Mutasi ini sudah pernah dikoreksi.');
            }
        }
    }

    private function history(StockMovement $m, ?string $from, string $to, User $by, ?string $note): void
    {
        DB::table('approval_histories')->insert([
            'approvable_type' => $m->getMorphClass(),
            'approvable_id'   => $m->id,
            'from_status'     => $from,
            'to_status'       => $to,
            'note'            => $note,
            'acted_by'        => $by->id,
            'acted_at'        => now(),
        ]);
    }
}
