<?php

namespace App\Services;

use App\Models\PriceObservation;
use App\Models\Scopes\PriceScope;
use App\Support\PriceMath;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Satu sumber angka harga untuk dashboard internal dan portal publik.
 *
 * Aturan yang sama untuk keduanya: hanya data berstatus "published" yang belum ditarik
 * (publications.unpublished_at kosong). Perbedaannya:
 *  - internal: tetap dibatasi cakupan wilayah pengguna (PriceScope aktif selama ada pengguna login)
 *  - publik  : tanpa PriceScope (semua wilayah) dan hanya mengambil kolom yang aman ditampilkan.
 *
 * Draf, data diajukan, data ditolak, dan data yang belum diverifikasi tidak pernah ikut dihitung.
 */
class PriceAnalytics
{
    private function __construct(private bool $public)
    {
    }

    public static function forInternal(): self
    {
        return new self(false);
    }

    public static function forPublic(): self
    {
        return new self(true);
    }

    /** Data terbit (published) dan belum ditarik dari publikasi. */
    private function published(): Builder
    {
        $query = PriceObservation::query();

        if ($this->public) {
            $query->withoutGlobalScope(PriceScope::class);
        }

        return $query
            ->where('price_observations.status', 'published')
            ->whereNotExists(function ($sub) {
                $sub->from('publications')
                    ->whereColumn('publications.publishable_id', 'price_observations.id')
                    ->where('publications.publishable_type', (new PriceObservation())->getMorphClass())
                    ->whereNotNull('publications.unpublished_at');
            });
    }

    /**
     * Rentang tanggal inklusif. Batas atas memakai "< hari berikutnya" supaya benar baik untuk kolom
     * bertipe DATE (MySQL) maupun yang tersimpan sebagai teks tanggal-jam (SQLite pada tes).
     */
    private function between(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->where('price_observations.observed_on', '>=', $from->toDateString())
            ->where('price_observations.observed_on', '<', $to->copy()->addDay()->toDateString());
    }

    /**
     * Data untuk grafik tren (dipakai dashboard dan portal publik).
     *
     * @param  Collection<int, array{day: string, avg: float, min: float, max: float, observations: int}>  $trend
     * @return array{labels: list<string>, avg: list<float>, min: list<float>, max: list<float>, range: bool}
     */
    public static function chartPayload(Collection $trend): array
    {
        return [
            'labels' => $trend->map(fn ($r) => substr($r['day'], 8, 2) . '/' . substr($r['day'], 5, 2))->all(),
            'avg'    => $trend->map(fn ($r) => round($r['avg']))->all(),
            'min'    => $trend->map(fn ($r) => round($r['min']))->all(),
            'max'    => $trend->map(fn ($r) => round($r['max']))->all(),
            // Garis terendah/tertinggi hanya berarti bila ada perbedaan antar pasar.
            'range'  => $trend->contains(fn ($r) => $r['min'] !== $r['max']),
        ];
    }

    /**
     * Ringkasan pada rentang tanggal: jumlah data terbit, pasar, dan komoditas yang terpantau.
     *
     * @return array{observations: int, markets: int, commodities: int}
     */
    public function totals(CarbonInterface $from, CarbonInterface $to): array
    {
        $row = $this->between($this->published(), $from, $to)
            ->selectRaw('COUNT(*) as observations, COUNT(DISTINCT price_observations.market_id) as markets, COUNT(DISTINCT price_observations.commodity_id) as commodities')
            ->toBase()
            ->first();

        return [
            'observations' => (int) ($row->observations ?? 0),
            'markets'      => (int) ($row->markets ?? 0),
            'commodities'  => (int) ($row->commodities ?? 0),
        ];
    }

    /**
     * Tren harian satu komoditas: rata-rata, terendah, dan tertinggi antar pasar.
     *
     * @return Collection<int, array{day: string, avg: float, min: float, max: float, observations: int}>
     */
    public function trend(int $commodityId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->between($this->published(), $from, $to)
            ->where('price_observations.commodity_id', $commodityId)
            ->selectRaw('DATE(price_observations.observed_on) as obs_day, AVG(price_observations.price) as avg_price, MIN(price_observations.price) as min_price, MAX(price_observations.price) as max_price, COUNT(*) as observations')
            ->groupByRaw('DATE(price_observations.observed_on)')
            ->orderBy('obs_day')
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'day'          => substr((string) $r->obs_day, 0, 10),
                'avg'          => (float) $r->avg_price,
                'min'          => (float) $r->min_price,
                'max'          => (float) $r->max_price,
                'observations' => (int) $r->observations,
            ]);
    }

    /**
     * Harga terakhir tiap pasar untuk satu komoditas beserta perubahan terhadap pengamatan sebelumnya
     * di pasar yang sama.
     *
     * @return Collection<int, array{market: string, district: string, day: string, price: float, previous_day: ?string, previous_price: ?float, change: ?float}>
     */
    public function latestByMarket(int $commodityId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $rows = $this->between($this->published(), $from, $to)
            ->where('price_observations.commodity_id', $commodityId)
            ->join('markets', 'markets.id', '=', 'price_observations.market_id')
            ->join('districts', 'districts.id', '=', 'markets.district_id')
            ->select([
                'price_observations.market_id',
                'markets.name as market_name',
                'districts.name as district_name',
                'price_observations.observed_on',
                'price_observations.price',
            ])
            ->orderByDesc('price_observations.observed_on')
            ->orderByDesc('price_observations.id')
            ->toBase()
            ->get();

        return $rows->groupBy('market_id')->map(function (Collection $group) {
            $list = $group->map(fn ($r) => [
                'day'      => substr((string) $r->observed_on, 0, 10),
                'price'    => (float) $r->price,
                'market'   => (string) $r->market_name,
                'district' => (string) $r->district_name,
            ])->all();

            [$latest, $previous] = PriceMath::latestTwo($list);

            return [
                'market'         => $latest['market'],
                'district'       => $latest['district'],
                'day'            => $latest['day'],
                'price'          => $latest['price'],
                'previous_day'   => $previous['day'] ?? null,
                'previous_price' => $previous['price'] ?? null,
                'change'         => PriceMath::changePercent($latest['price'], $previous['price'] ?? null),
            ];
        })->sortBy('market')->values();
    }

    /**
     * Gambaran seluruh komoditas yang punya data terbit dalam beberapa hari terakhir:
     * harga rata-rata antar pasar pada tanggal pengamatan terbaru dan perubahannya terhadap
     * tanggal pengamatan sebelumnya.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overview(CarbonInterface $today, int $lookbackDays = 60): Collection
    {
        $rows = $this->between($this->published(), $today->copy()->subDays($lookbackDays), $today)
            ->join('commodities', 'commodities.id', '=', 'price_observations.commodity_id')
            ->join('units', 'units.id', '=', 'commodities.default_unit_id')
            ->where('commodities.is_active', true)
            ->selectRaw('price_observations.commodity_id, commodities.name as commodity_name, commodities.category as commodity_category, units.code as unit_code, DATE(price_observations.observed_on) as obs_day, AVG(price_observations.price) as avg_price, MIN(price_observations.price) as min_price, MAX(price_observations.price) as max_price, COUNT(DISTINCT price_observations.market_id) as markets')
            ->groupBy('price_observations.commodity_id', 'commodities.name', 'commodities.category', 'units.code')
            ->groupByRaw('DATE(price_observations.observed_on)')
            ->orderBy('commodities.name')
            ->orderByDesc('obs_day')
            ->toBase()
            ->get();

        return $rows->groupBy('commodity_id')->map(function (Collection $group) {
            $list = $group->map(fn ($r) => [
                'day'         => substr((string) $r->obs_day, 0, 10),
                'avg'         => (float) $r->avg_price,
                'min'         => (float) $r->min_price,
                'max'         => (float) $r->max_price,
                'markets'     => (int) $r->markets,
                'commodity_id' => (int) $r->commodity_id,
                'name'        => (string) $r->commodity_name,
                'category'    => (string) $r->commodity_category,
                'unit'        => (string) $r->unit_code,
            ])->all();

            [$latest, $previous] = PriceMath::latestTwo($list);

            return [
                'commodity_id'  => $latest['commodity_id'],
                'name'          => $latest['name'],
                'category'      => $latest['category'],
                'unit'          => $latest['unit'],
                'day'           => $latest['day'],
                'avg'           => $latest['avg'],
                'min'           => $latest['min'],
                'max'           => $latest['max'],
                'markets'       => $latest['markets'],
                'previous_day'  => $previous['day'] ?? null,
                'previous_avg'  => $previous['avg'] ?? null,
                'change'        => PriceMath::changePercent($latest['avg'], $previous['avg'] ?? null),
            ];
        })->sortBy('name')->values();
    }
}
