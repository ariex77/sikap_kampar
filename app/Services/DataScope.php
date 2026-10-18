<?php

namespace App\Services;

use App\Models\Market;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menerjemahkan tabel user_scopes menjadi pembatasan query.
 * Peran menentukan APA yang boleh dilakukan; cakupan menentukan DI DATA MANA.
 */
class DataScope
{
    public const DOMAIN_PRICE = 'harga';
    public const DOMAIN_STOCK = 'stok';

    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Cakupan yang masih berlaku hari ini untuk domain tertentu (atau '*'). */
    public function scopesFor(User $user, string $domain): Collection
    {
        $key = $user->id . '|' . $domain;

        return self::$cache[$key] ??= DB::table('user_scopes')
            ->where('user_id', $user->id)
            ->whereIn('data_domain', [$domain, '*'])
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', today()->toDateString()))
            ->get();
    }

    // ------------------------------------------------------------------
    // Harga
    // ------------------------------------------------------------------

    public function applyToPrices(Builder $query, User $user): void
    {
        $scopes = $this->scopesFor($user, self::DOMAIN_PRICE);

        // Tanpa cakupan sama sekali = tidak boleh melihat data apa pun.
        if ($scopes->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($scopes->contains('scope_type', 'kabupaten')) {
            return;
        }

        $districts = $scopes->where('scope_type', 'district')->pluck('scope_id')->all();
        $markets   = $scopes->where('scope_type', 'market')->pluck('scope_id')->all();
        $orgs      = $scopes->where('scope_type', 'organization')->pluck('scope_id')->all();
        $villages  = $scopes->where('scope_type', 'village')->pluck('scope_id')->all();

        if ($villages) {
            $markets = array_merge(
                $markets,
                DB::table('markets')->whereIn('village_id', $villages)->pluck('id')->all()
            );
        }

        $t = 'price_observations';

        $query->where(function ($q) use ($t, $districts, $markets, $orgs) {
            $q->whereRaw('1 = 0');

            if ($districts) {
                $q->orWhereIn("$t.district_id", $districts);
            }
            if ($markets) {
                $q->orWhereIn("$t.market_id", $markets);
            }
            if ($orgs) {
                $q->orWhereIn("$t.organization_id", $orgs);
            }
        });
    }

    /** Pasar yang boleh dipilih pengguna saat menginput harga. */
    public function markets(User $user): Builder
    {
        $query  = Market::query()->where('is_active', true);
        $scopes = $this->scopesFor($user, self::DOMAIN_PRICE);

        if ($scopes->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        if ($scopes->contains('scope_type', 'kabupaten')) {
            return $query;
        }

        $districts = $scopes->where('scope_type', 'district')->pluck('scope_id')->all();
        $markets   = $scopes->where('scope_type', 'market')->pluck('scope_id')->all();
        $villages  = $scopes->where('scope_type', 'village')->pluck('scope_id')->all();

        return $query->where(function ($w) use ($districts, $markets, $villages) {
            $w->whereRaw('1 = 0');

            if ($districts) {
                $w->orWhereIn('district_id', $districts);
            }
            if ($markets) {
                $w->orWhereIn('id', $markets);
            }
            if ($villages) {
                $w->orWhereIn('village_id', $villages);
            }
        });
    }

    // ------------------------------------------------------------------
    // Stok dan cadangan
    // ------------------------------------------------------------------

    /**
     * Subquery id lokasi penyimpanan yang boleh diakses pengguna.
     * Mengembalikan null bila pengguna punya akses penuh (cakupan kabupaten).
     * Tipe cakupan: kabupaten, district, village, organization, location.
     */
    public function locationIdsQuery(User $user): ?QueryBuilder
    {
        $scopes = $this->scopesFor($user, self::DOMAIN_STOCK);

        if ($scopes->contains('scope_type', 'kabupaten')) {
            return null;
        }

        $query = DB::table('storage_locations')->select('storage_locations.id');

        if ($scopes->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $districts = $scopes->where('scope_type', 'district')->pluck('scope_id')->all();
        $villages  = $scopes->where('scope_type', 'village')->pluck('scope_id')->all();
        $orgs      = $scopes->where('scope_type', 'organization')->pluck('scope_id')->all();
        $locations = $scopes->where('scope_type', 'location')->pluck('scope_id')->all();

        return $query->where(function ($w) use ($districts, $villages, $orgs, $locations) {
            $w->whereRaw('1 = 0');

            if ($districts) {
                $w->orWhereIn('storage_locations.district_id', $districts);
            }
            if ($villages) {
                $w->orWhereIn('storage_locations.village_id', $villages);
            }
            if ($orgs) {
                $w->orWhereIn('storage_locations.organization_id', $orgs);
            }
            if ($locations) {
                $w->orWhereIn('storage_locations.id', $locations);
            }
        });
    }

    /** Lokasi penyimpanan aktif yang boleh dipilih pengguna saat menginput mutasi. */
    public function storageLocations(User $user): Builder
    {
        $query = StorageLocation::query()->where('is_active', true);
        $ids = $this->locationIdsQuery($user);

        return $ids === null ? $query : $query->whereIn('storage_locations.id', $ids);
    }
}
