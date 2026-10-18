<?php

namespace App\Models\Scopes;

use App\Services\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Membatasi data stok ke lokasi penyimpanan yang masuk cakupan pengguna (user_scopes, domain "stok").
 * Opsional: draf milik orang lain disembunyikan. Tidak berlaku bila tidak ada pengguna login.
 */
class LocationScope implements Scope
{
    public function __construct(private bool $hideForeignDrafts = false)
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $table = $model->getTable();

        $ids = app(DataScope::class)->locationIdsQuery($user);
        if ($ids !== null) {
            $builder->whereIn("$table.storage_location_id", $ids);
        }

        if ($this->hideForeignDrafts) {
            $builder->where(function ($q) use ($table, $user) {
                $q->where("$table.status", '!=', 'draft')
                  ->orWhere("$table.created_by", $user->id);
            });
        }
    }
}
