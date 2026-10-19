<?php

namespace App\Livewire;

use App\Models\Commodity;
use App\Models\User;
use App\Services\DataScope;
use App\Services\PriceAnalytics;
use App\Services\StockSummary;
use App\Services\WorkQueue;
use Carbon\CarbonInterface;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dashboard internal. Setiap bagian ditampilkan menurut izin pengguna (price.view, stock.view),
 * setiap angka dibatasi cakupan wilayah pengguna, dan setiap angka punya tautan ke daftar data
 * sumber dengan filter periode yang sama (kriteria penerimaan nomor 4).
 */
#[Layout('layouts.app')]
#[Title('Dashboard - SIKAP KAMPAR')]
class Dashboard extends Component
{
    public const PERIODS = [7, 30, 90];

    /** Berapa hari ke belakang dicari untuk "harga terakhir". */
    private const LOOKBACK_DAYS = 60;

    #[Url]
    public string $commodity = '';

    #[Url]
    public string $days = '30';

    public function render()
    {
        $user = auth()->user();

        $days = in_array((int) $this->days, self::PERIODS, true) ? (int) $this->days : 30;
        $to = today();
        $from = $to->copy()->subDays($days - 1);

        return view('livewire.dashboard', [
            'user'    => $user,
            'periodDays' => $days,
            'from'    => $from,
            'to'      => $to,
            'periods' => self::PERIODS,
            'queue'   => app(WorkQueue::class)->forUser($user),
            'price'   => $user->can('price.view') ? $this->priceData($user, $from, $to) : null,
            'stock'   => $user->can('stock.view') ? $this->stockData($user, $from, $to) : null,
        ]);
    }

    private function scopeLabel(User $user, string $domain): string
    {
        $scopes = app(DataScope::class)->scopesFor($user, $domain);

        if ($scopes->isEmpty()) {
            return 'Anda belum memiliki wilayah kerja untuk data ini. Hubungi admin.';
        }

        if ($scopes->contains('scope_type', 'kabupaten')) {
            return 'Seluruh Kabupaten Kampar';
        }

        return 'Wilayah kerja Anda (' . $scopes->count() . ' penugasan)';
    }

    /** @return array<string, mixed> */
    private function priceData(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $analytics = PriceAnalytics::forInternal();

        $overview = $analytics->overview($to, self::LOOKBACK_DAYS);
        $commodities = Commodity::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $requested = (int) $this->commodity;
        $selectedId = ($requested > 0 && $commodities->contains('id', $requested))
            ? $requested
            : ($overview->first()['commodity_id'] ?? $commodities->first()?->id);

        $selected = $selectedId ? Commodity::with('defaultUnit')->find($selectedId) : null;

        $trend = $selectedId ? $analytics->trend($selectedId, $from, $to) : collect();
        $markets = $selectedId
            ? $analytics->latestByMarket($selectedId, $to->copy()->subDays(self::LOOKBACK_DAYS), $to)
            : collect();

        $chart = PriceAnalytics::chartPayload($trend);

        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

        return [
            'scope_label'   => $this->scopeLabel($user, DataScope::DOMAIN_PRICE),
            'commodities'   => $commodities,
            'selected_name' => $selected?->name,
            'unit'          => $selected?->defaultUnit?->code,
            'totals'        => $analytics->totals($from, $to),
            'overview'      => $overview,
            'trend'         => $trend,
            'markets'       => $markets,
            'chart'         => $chart,
            'chart_key'     => md5(json_encode([$selectedId, $chart])),
            'links'         => [
                'all'    => route('prices.index', ['status' => 'published'] + $period),
                'source' => route('prices.index', ['status' => 'published', 'commodity' => $selectedId] + $period),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function stockData(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $summary = app(StockSummary::class);
        $flows = $summary->flows($from, $to);

        $rows = $summary->balances()->map(function (array $balance) use ($flows) {
            $flow = $flows->get($balance['commodity_id'], []);

            return $balance + [
                'inflow_milli'  => $flow['inflow_milli'] ?? 0,
                'outflow_milli' => $flow['outflow_milli'] ?? 0,
            ];
        });

        return [
            'scope_label' => $this->scopeLabel($user, DataScope::DOMAIN_STOCK),
            'rows'        => $rows,
            'source_url'  => route('stock.index', [
                'status' => 'approved',
                'from'   => $from->toDateString(),
                'to'     => $to->toDateString(),
            ]),
        ];
    }
}
