<?php

namespace App\Services;

use App\Models\PriceObservation;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Daftar pekerjaan yang menunggu tindakan pengguna, sesuai izin dan cakupan datanya.
 * Hitungan memakai global scope model sehingga hanya data dalam cakupan pengguna yang terhitung.
 */
class WorkQueue
{
    /**
     * @return list<array{key: string, label: string, count: int, url: string, urgent: bool, note: ?string}>
     */
    public function forUser(User $user): array
    {
        $items = [];

        if ($user->can('price.verify')) {
            $waiting = PriceObservation::query()
                ->where('status', 'submitted')
                ->where('created_by', '!=', $user->id);
            $total = (clone $waiting)->count();
            $outliers = (clone $waiting)->where('is_outlier', true)->count();

            $items[] = $this->item(
                'price_verify',
                'Harga menunggu verifikasi',
                $total,
                route('prices.index', ['status' => 'submitted']),
                true,
                $outliers > 0 ? "{$outliers} di antaranya berubah melebihi ambang wajar" : null
            );
        }

        if ($user->can('price.create')) {
            $items[] = $this->item(
                'price_rejected',
                'Harga ditolak, perlu Anda perbaiki',
                PriceObservation::query()->where('status', 'rejected')->where('created_by', $user->id)->count(),
                route('prices.index', ['status' => 'rejected']),
                true
            );
            $items[] = $this->item(
                'price_draft',
                'Draf harga belum diajukan',
                PriceObservation::query()->where('status', 'draft')->where('created_by', $user->id)->count(),
                route('prices.index', ['status' => 'draft']),
                false
            );
        }

        if ($user->can('stock.verify')) {
            $items[] = $this->item(
                'stock_verify',
                'Mutasi stok menunggu verifikasi',
                StockMovement::query()->where('status', 'submitted')->where('created_by', '!=', $user->id)->count(),
                route('stock.index', ['status' => 'submitted']),
                true
            );
        }

        if ($user->can('stock.approve')) {
            $items[] = $this->item(
                'stock_approve',
                'Mutasi stok menunggu persetujuan Anda',
                StockMovement::query()
                    ->where('status', 'verified')
                    ->where('created_by', '!=', $user->id)
                    ->where('verified_by', '!=', $user->id)
                    ->count(),
                route('stock.index', ['status' => 'verified']),
                true
            );
        }

        if ($user->can('stock.create')) {
            $items[] = $this->item(
                'stock_rejected',
                'Mutasi stok ditolak, perlu Anda perbaiki',
                StockMovement::query()->where('status', 'rejected')->where('created_by', $user->id)->count(),
                route('stock.index', ['status' => 'rejected']),
                true
            );
            $items[] = $this->item(
                'stock_draft',
                'Draf mutasi stok belum diajukan',
                StockMovement::query()->where('status', 'draft')->where('created_by', $user->id)->count(),
                route('stock.index', ['status' => 'draft']),
                false
            );
        }

        return $items;
    }

    /** @return array{key: string, label: string, count: int, url: string, urgent: bool, note: ?string} */
    private function item(string $key, string $label, int $count, string $url, bool $urgent, ?string $note = null): array
    {
        return [
            'key'    => $key,
            'label'  => $label,
            'count'  => $count,
            'url'    => $url,
            'urgent' => $urgent,
            'note'   => $note,
        ];
    }
}
