<?php

namespace Tests\Feature\Dashboard;

use App\Models\PriceObservation;
use App\Services\PriceAnalytics;
use App\Services\StockSummary;
use App\Services\StockWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

/**
 * Angka dashboard dan portal dihitung dari satu sumber (PriceAnalytics, StockSummary).
 * Tes ini mengunci hasil hitungannya terhadap data sumber (kriteria penerimaan nomor 4).
 */
class AnalyticsTest extends TestCase
{
    use CreatesStockFixtures;
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->guardTestDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockFixtures();
    }

    private function published(array $attrs = []): PriceObservation
    {
        return $this->makePrice($this->op, array_merge([
            'status'      => 'published',
            'verified_by' => $this->ver->id,
            'verified_at' => now(),
        ], $attrs));
    }

    private function daysAgo(int $days): string
    {
        return today()->subDays($days)->toDateString();
    }

    private function inB(array $attrs = []): array
    {
        return ['market_id' => $this->marketB, 'district_id' => $this->districtB] + $attrs;
    }

    // ---------------- Harga ----------------

    public function test_ringkasan_komoditas_menghitung_rata_rata_dan_perubahan(): void
    {
        // Kemarin: dua pasar, rata-rata 11.000. Hari ini: satu pasar 13.200.
        $this->published(['price' => 10000, 'observed_on' => $this->daysAgo(1)]);
        $this->published($this->inB(['price' => 12000, 'observed_on' => $this->daysAgo(1)]));
        $this->published(['price' => 13200, 'observed_on' => $this->daysAgo(0)]);

        $this->actingAs($this->boss);
        $overview = PriceAnalytics::forInternal()->overview(today());

        $this->assertCount(1, $overview);
        $row = $overview->first();

        $this->assertSame('Beras', $row['name']);
        $this->assertSame('kg', $row['unit']);
        $this->assertSame($this->daysAgo(0), $row['day']);
        $this->assertSame(13200.0, $row['avg']);
        $this->assertSame(1, $row['markets']);
        $this->assertSame($this->daysAgo(1), $row['previous_day']);
        $this->assertSame(11000.0, $row['previous_avg']);
        $this->assertSame(20.0, $row['change']);
    }

    public function test_hanya_data_terbit_yang_dihitung(): void
    {
        $this->published(['price' => 15000, 'observed_on' => $this->daysAgo(0)]);
        $this->makePrice($this->op, ['status' => 'draft', 'price' => 99000, 'observed_on' => $this->daysAgo(1)]);
        $this->makePrice($this->op, ['status' => 'submitted', 'price' => 99000, 'observed_on' => $this->daysAgo(2)]);
        $this->makePrice($this->op, ['status' => 'rejected', 'price' => 99000, 'observed_on' => $this->daysAgo(3)]);

        $this->actingAs($this->boss);
        $analytics = PriceAnalytics::forInternal();

        $totals = $analytics->totals(today()->subDays(29), today());
        $this->assertSame(['observations' => 1, 'markets' => 1, 'commodities' => 1], $totals);
        $this->assertCount(1, $analytics->trend($this->commodityId, today()->subDays(29), today()));
    }

    public function test_data_yang_ditarik_atau_dihapus_tidak_dihitung(): void
    {
        $withdrawn = $this->published(['price' => 15000, 'observed_on' => $this->daysAgo(0)]);
        DB::table('publications')->insert([
            'publishable_type' => $withdrawn->getMorphClass(),
            'publishable_id'   => $withdrawn->id,
            'version'          => 1,
            'published_by'     => $this->ver->id,
            'published_at'     => now(),
            'unpublished_at'   => now(),
        ]);
        $this->published(['price' => 16000, 'observed_on' => $this->daysAgo(1)])->delete();
        $this->published(['price' => 17000, 'observed_on' => $this->daysAgo(2)]);

        $this->actingAs($this->boss);
        $totals = PriceAnalytics::forInternal()->totals(today()->subDays(29), today());

        $this->assertSame(1, $totals['observations']);
    }

    public function test_internal_menghormati_cakupan_sedangkan_publik_tidak(): void
    {
        $this->published($this->inB(['price' => 17777, 'observed_on' => $this->daysAgo(0)]));

        $scoped = $this->makeUser('operator-kecamatan', [['district', $this->districtA, 'harga']]);
        $this->actingAs($scoped);

        $this->assertCount(0, PriceAnalytics::forInternal()->overview(today()));
        $this->assertSame(0, PriceAnalytics::forInternal()->totals(today()->subDays(29), today())['observations']);

        $this->assertCount(1, PriceAnalytics::forPublic()->overview(today()));
        $this->assertSame(1, PriceAnalytics::forPublic()->totals(today()->subDays(29), today())['observations']);
    }

    public function test_pengguna_tanpa_cakupan_tidak_menghitung_apa_pun_di_dashboard(): void
    {
        $this->published(['price' => 15000, 'observed_on' => $this->daysAgo(0)]);

        $this->actingAs($this->makeUser('operator-opd')); // peran ada, cakupan tidak ada

        $this->assertCount(0, PriceAnalytics::forInternal()->overview(today()));
        $this->assertSame(0, PriceAnalytics::forInternal()->totals(today()->subDays(29), today())['observations']);
    }

    public function test_tren_mencakup_hari_awal_dan_akhir_dan_mengecualikan_yang_di_luar(): void
    {
        $from = today()->subDays(6);
        $to = today();

        $this->published(['price' => 10000, 'observed_on' => $this->daysAgo(7)]); // di luar
        $this->published(['price' => 11000, 'observed_on' => $this->daysAgo(6)]); // hari pertama
        $this->published(['price' => 12000, 'observed_on' => $this->daysAgo(0)]); // hari terakhir
        $this->published($this->inB(['price' => 14000, 'observed_on' => $this->daysAgo(0)]));

        $this->actingAs($this->boss);
        $trend = PriceAnalytics::forInternal()->trend($this->commodityId, $from, $to);

        $this->assertSame([$this->daysAgo(6), $this->daysAgo(0)], $trend->pluck('day')->all());

        $last = $trend->last();
        $this->assertSame(13000.0, $last['avg']);
        $this->assertSame(12000.0, $last['min']);
        $this->assertSame(14000.0, $last['max']);
        $this->assertSame(2, $last['observations']);
    }

    public function test_harga_terakhir_per_pasar_dengan_perubahan_di_pasar_yang_sama(): void
    {
        $this->published(['price' => 10000, 'observed_on' => $this->daysAgo(3)]);
        $this->published(['price' => 12000, 'observed_on' => $this->daysAgo(1)]);
        $this->published($this->inB(['price' => 20000, 'observed_on' => $this->daysAgo(2)]));

        $this->actingAs($this->boss);
        $markets = PriceAnalytics::forInternal()->latestByMarket($this->commodityId, today()->subDays(60), today());

        $this->assertSame(['Pasar A', 'Pasar B'], $markets->pluck('market')->all());

        $a = $markets->firstWhere('market', 'Pasar A');
        $this->assertSame(12000.0, $a['price']);
        $this->assertSame(10000.0, $a['previous_price']);
        $this->assertSame(20.0, $a['change']);
        $this->assertSame('Kecamatan A', $a['district']);

        $b = $markets->firstWhere('market', 'Pasar B');
        $this->assertSame(20000.0, $b['price']);
        $this->assertNull($b['change']); // belum ada pengamatan sebelumnya di pasar B
    }

    public function test_data_di_luar_jendela_pencarian_tidak_masuk_ringkasan(): void
    {
        $this->published(['price' => 15000, 'observed_on' => $this->daysAgo(100)]);

        $this->actingAs($this->boss);

        $this->assertCount(0, PriceAnalytics::forInternal()->overview(today(), 60));
        $this->assertCount(1, PriceAnalytics::forInternal()->overview(today(), 120));
    }

    // ---------------- Stok ----------------

    public function test_saldo_dan_arus_stok_bersih_dengan_koreksi(): void
    {
        $workflow = app(StockWorkflow::class);

        $this->movement('opening', 500);
        $this->movement('in', 250);
        $out = $this->movement('out', 100);
        $this->movement('out', 40.5);
        $this->approveMovement($workflow->createReversal($out, $this->op, 'Penyaluran dibatalkan'));

        $this->actingAs($this->boss);
        $summary = app(StockSummary::class);

        $balances = $summary->balances();
        $this->assertCount(1, $balances);
        $this->assertSame(709500, $balances->first()['balance_milli']); // 500 + 250 - 100 - 40,5 + 100
        $this->assertSame('Beras', $balances->first()['name']);

        $flows = $summary->flows(today()->subDays(29), today())->get($this->commodityId);
        $this->assertSame(250000, $flows['inflow_milli']);           // stok awal tidak dihitung
        $this->assertSame(40500, $flows['outflow_milli']);           // 100 + 40,5 - 100 (dikoreksi)
    }

    public function test_arus_stok_hanya_dari_mutasi_disetujui_dan_dalam_periode(): void
    {
        $this->movement('in', 100);
        $this->movement('in', 70, ['movement_date' => $this->daysAgo(40)]); // di luar periode 30 hari

        $pending = $this->makeMovement(['movement_type' => 'in', 'quantity' => 999]);
        app(StockWorkflow::class)->submit($pending, $this->op); // belum disetujui

        $this->actingAs($this->boss);
        $flows = app(StockSummary::class)->flows(today()->subDays(29), today());

        $this->assertSame(100000, $flows->get($this->commodityId)['inflow_milli']);
        $this->assertSame(0, $flows->get($this->commodityId)['outflow_milli']);
    }

    public function test_stok_dibatasi_lokasi_dalam_cakupan(): void
    {
        $this->movement('in', 100);
        $this->movement('in', 70, ['storage_location_id' => $this->locB]);

        $scopedA = $this->makeUser('operator-dkp', [['district', $this->districtA, 'stok']]);
        $this->actingAs($scopedA);

        $balances = app(StockSummary::class)->balances();

        $this->assertSame(100000, $balances->sum('balance_milli'));
        $this->assertSame(1, $balances->first()['locations']);

        $this->actingAs($this->makeUser('operator-dkp')); // tanpa cakupan stok
        $this->assertCount(0, app(StockSummary::class)->balances());
    }
}
