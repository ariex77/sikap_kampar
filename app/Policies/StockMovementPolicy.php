<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

/**
 * Izin (stock.*) berasal dari peran; wilayah dibatasi oleh LocationScope.
 * Penginput, pemeriksa, dan penyetuju harus berbeda orang.
 */
class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.create');
    }

    /** Mengubah isi mutasi (bukan mutasi koreksi, karena isinya ditentukan oleh mutasi asal). */
    public function update(User $user, StockMovement $movement): bool
    {
        return $user->can('stock.create')
            && $movement->movement_type !== 'reversal'
            && $this->isOwnerEditable($user, $movement);
    }

    public function submit(User $user, StockMovement $movement): bool
    {
        return $user->can('stock.submit') && $this->isOwnerEditable($user, $movement);
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        return $user->can('stock.create')
            && $movement->status === 'draft'
            && (int) $movement->created_by === (int) $user->id;
    }

    public function verify(User $user, StockMovement $movement): bool
    {
        return $user->can('stock.verify')
            && $movement->status === 'submitted'
            && (int) $movement->created_by !== (int) $user->id;
    }

    public function approve(User $user, StockMovement $movement): bool
    {
        return $user->can('stock.approve')
            && $movement->status === 'verified'
            && (int) $movement->created_by !== (int) $user->id
            && (int) $movement->verified_by !== (int) $user->id;
    }

    public function reject(User $user, StockMovement $movement): bool
    {
        if ($movement->status === 'submitted') {
            return $this->verify($user, $movement);
        }

        if ($movement->status === 'verified') {
            return $this->approve($user, $movement);
        }

        return false;
    }

    public function reverse(User $user, StockMovement $movement): bool
    {
        return $user->can('stock.create')
            && $movement->status === 'approved'
            && $movement->movement_type !== 'reversal';
    }

    private function isOwnerEditable(User $user, StockMovement $movement): bool
    {
        return in_array($movement->status, ['draft', 'rejected'], true)
            && (int) $movement->created_by === (int) $user->id;
    }
}
