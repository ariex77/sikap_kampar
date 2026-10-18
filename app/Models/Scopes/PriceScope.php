<?php

namespace App\Models\Scopes;

use App\Services\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope: setiap query PriceObservation otomatis dibatasi oleh
 * cakupan wilayah/instansi pengguna (tabel user_scopes) dan oleh aturan
 * "draf hanya terlihat oleh pembuatnya".
 *
 * Tidak berlaku bila tidak ada pengguna login (seeder, perintah artisan).
 * Kode internal yang memang harus melihat semua data memakai withoutGlobalScopes().
 */
class PriceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        app(DataScope::class)->applyToPrices($builder, $user);

        $table = $model->getTable();
        $builder->where(function ($q) use ($table, $user) {
            $q->where("$table.status", '!=', 'draft')
              ->orWhere("$table.created_by", $user->id);
        });
    }
}
