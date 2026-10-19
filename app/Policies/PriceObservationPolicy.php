<?php

namespace App\Policies;

use App\Models\PriceObservation;
use App\Models\User;

/**
 * Izin (price.*) berasal dari peran. Pembatasan wilayah dijalankan oleh PriceScope,
 * sehingga data di luar cakupan pengguna tidak pernah sampai ke Policy ini.
 */
class PriceObservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('price.view');
    }

    public function view(User $user, PriceObservation $price): bool
    {
        return $user->can('price.view');
    }

    public function create(User $user): bool
    {
        return $user->can('price.create');
    }

    public function update(User $user, PriceObservation $price): bool
    {
        return $user->can('price.create') && $this->isEditableByOwner($user, $price);
    }

    public function submit(User $user, PriceObservation $price): bool
    {
        return $user->can('price.submit') && $this->isEditableByOwner($user, $price);
    }

    public function delete(User $user, PriceObservation $price): bool
    {
        return $user->can('price.create')
            && $price->status === 'draft'
            && $price->created_by === $user->id;
    }

    public function verify(User $user, PriceObservation $price): bool
    {
        return $user->can('price.verify')
            && $price->status === 'submitted'
            && $price->created_by !== $user->id; // pemisahan penginput dan pemeriksa
    }

    public function reject(User $user, PriceObservation $price): bool
    {
        return $this->verify($user, $price);
    }

    /** Penarikan dari publikasi: izin publication.manage (Kepala Dinas), hanya data terbit yang belum ditarik. */
    public function unpublish(User $user, PriceObservation $price): bool
    {
        return $user->can('publication.manage')
            && $price->status === 'published'
            && ! \App\Services\PriceWorkflow::isRetracted($price->id);
    }

    private function isEditableByOwner(User $user, PriceObservation $price): bool
    {
        return in_array($price->status, ['draft', 'rejected'], true)
            && $price->created_by === $user->id;
    }
}
