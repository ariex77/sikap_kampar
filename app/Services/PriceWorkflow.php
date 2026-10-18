<?php

namespace App\Services;

use App\Models\PriceObservation;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Alur harga: draf -> diajukan -> diverifikasi -> terbit (otomatis).
 * Data ditolak kembali ke pembuat dengan alasan, lalu dapat diperbaiki dan diajukan ulang.
 *
 * Semua aturan bisnis ada di sini (bukan di tampilan), sehingga tidak bisa dilewati
 * dengan mengutak-atik formulir.
 */
class PriceWorkflow
{
    public function submit(PriceObservation $price, User $by): void
    {
        DB::transaction(function () use ($price, $by) {
            $p = $this->lock($price);

            if ($p->created_by !== $by->id) {
                throw new DomainException('Hanya pembuat data yang dapat mengajukan.');
            }
            if (! in_array($p->status, ['draft', 'rejected'], true)) {
                throw new DomainException('Hanya draf atau data yang ditolak yang dapat diajukan.');
            }

            $this->assertPeriodOpen($p->observed_on);

            [$previous, $isOutlier] = $this->evaluate($p);
            $from = $p->status;

            $p->forceFill([
                'status'           => 'submitted',
                'previous_price'   => $previous,
                'is_outlier'       => $isOutlier,
                'rejection_reason' => null,
                'submitted_at'     => now(),
                'updated_by'       => $by->id,
            ])->save();

            $this->history($p, $from, 'submitted', $by, null);
            Audit::log('submitted', 'price_observation', $p->id, ['status' => $from], ['status' => 'submitted', 'is_outlier' => $isOutlier]);
        });
    }

    public function verify(PriceObservation $price, User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($price, $by, $note) {
            $p = $this->lock($price);

            if ($p->status !== 'submitted') {
                throw new DomainException('Hanya data berstatus Diajukan yang dapat diverifikasi.');
            }
            if ($p->created_by === $by->id) {
                throw new DomainException('Anda tidak dapat memverifikasi data yang Anda input sendiri.');
            }
            if ($p->is_outlier && blank($note)) {
                throw new DomainException('Harga ini berubah melebihi ambang wajar. Isi catatan verifikasi (misalnya hasil pengecekan ke pedagang).');
            }

            $this->assertPeriodOpen($p->observed_on);

            $p->forceFill([
                'status'      => 'published',
                'verified_by' => $by->id,
                'verified_at' => now(),
                'updated_by'  => $by->id,
            ])->save();

            $this->history($p, 'submitted', 'verified', $by, $note);
            $this->history($p, 'verified', 'published', $by, 'Terbit otomatis setelah verifikasi');

            DB::table('publications')->insert([
                'publishable_type' => $p->getMorphClass(),
                'publishable_id'   => $p->id,
                'version'          => 1,
                'published_by'     => $by->id,
                'published_at'     => now(),
            ]);

            Audit::log('verified', 'price_observation', $p->id, ['status' => 'submitted'], ['status' => 'published']);
        });
    }

    public function reject(PriceObservation $price, User $by, string $reason): void
    {
        DB::transaction(function () use ($price, $by, $reason) {
            $p = $this->lock($price);

            if ($p->status !== 'submitted') {
                throw new DomainException('Hanya data berstatus Diajukan yang dapat ditolak.');
            }
            if ($p->created_by === $by->id) {
                throw new DomainException('Anda tidak dapat menolak data yang Anda input sendiri.');
            }

            $p->forceFill([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
                'updated_by'       => $by->id,
            ])->save();

            $this->history($p, 'submitted', 'rejected', $by, $reason);
            Audit::log('rejected', 'price_observation', $p->id, ['status' => 'submitted'], ['status' => 'rejected', 'reason' => $reason]);
        });
    }

    /** Draf boleh dihapus permanen oleh pembuatnya (belum menjadi data resmi). */
    public function deleteDraft(PriceObservation $price, User $by): void
    {
        DB::transaction(function () use ($price, $by) {
            $p = $this->lock($price);

            if ($p->status !== 'draft' || $p->created_by !== $by->id) {
                throw new DomainException('Hanya draf milik Anda yang dapat dihapus.');
            }

            Audit::log('deleted', 'price_observation', $p->id, $p->only(['market_id', 'commodity_id', 'observed_on', 'price']), null);
            $p->forceDelete();
        });
    }

    // ---------------------------------------------------------------

    private function lock(PriceObservation $price): PriceObservation
    {
        return PriceObservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($price->id);
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

    /** @return array{0: ?string, 1: bool} harga terbit sebelumnya, apakah lonjakan di luar ambang */
    private function evaluate(PriceObservation $p): array
    {
        $previous = PriceObservation::withoutGlobalScopes()
            ->where('market_id', $p->market_id)
            ->where('commodity_id', $p->commodity_id)
            ->where('status', 'published')
            ->whereDate('observed_on', '<', $p->observed_on->toDateString())
            ->orderByDesc('observed_on')
            ->value('price');

        $isOutlier = false;

        if ($previous !== null && (float) $previous > 0) {
            $change = abs(((float) $p->price - (float) $previous) / (float) $previous * 100);
            $isOutlier = $change > $this->threshold();
        }

        return [$previous, $isOutlier];
    }

    private function threshold(): float
    {
        $raw = DB::table('system_settings')->where('key', 'price.outlier_threshold_pct')->value('value');
        $value = $raw === null ? null : json_decode($raw, true);

        return is_numeric($value) ? (float) $value : 20.0;
    }

    private function history(PriceObservation $p, ?string $from, string $to, User $by, ?string $note): void
    {
        DB::table('approval_histories')->insert([
            'approvable_type' => $p->getMorphClass(),
            'approvable_id'   => $p->id,
            'from_status'     => $from,
            'to_status'       => $to,
            'note'            => $note,
            'acted_by'        => $by->id,
            'acted_at'        => now(),
        ]);
    }
}
