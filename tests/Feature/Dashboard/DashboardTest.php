<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard;
use App\Models\PriceObservation;
use App\Services\WorkQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

/**
 * Dashboard internal: bagian tampil menurut izin, angka mengikuti cakupan wilayah,
 * angka dapat ditelusuri ke data sumber, dan antrean kerja sesuai peran.
 */
class DashboardTest extends TestCase
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
        $this->withoutVite();
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

    // ---------- Akses ----------

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_teknis_dapat_membuka_dashboard_tanpa_data_substantif(): void
    {
        $this->published(['price' => 15500]);
        $this->movement('in', 100);

        $admin = $this->makeUser('super-admin-teknis');

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Peran Anda tidak memiliki akses ke data harga atau stok')
            ->assertDontSee('15.500')
            ->assertDontSee('Gudang A');
    }

    public function test_menu_dan_halaman_dashboard_tampil_untuk_operator(): void
    {
        $this->actingAs($this->op)->get('/dashboard')
            ->assertOk()
            ->assertSee('Harga pangan')
            ->assertSee('Stok dan cadangan pangan')
            ->assertSee('Portal publik');
    }

    // ---------- Cakupan ----------

    public function test_dashboard_dibatasi_cakupan_wilayah(): void
    {
        $this->published(['price' => 15000]);
        $this->published(['market_id' => $this->marketB, 'district_id' => $this->districtB, 'price' => 17777]);

        $scoped = $this->makeUser('operator-kecamatan', [['district', $this->districtA, 'harga']]);

        Livewire::actingAs($scoped)->test(Dashboard::class)
            ->assertSee('Pasar A')
            ->assertDontSee('Pasar B')
            ->assertDontSee('17.777')
            ->assertViewHas('price', fn ($p) => $p['totals']['observations'] === 1);
    }

    public function test_pengguna_tanpa_cakupan_melihat_nol_dan_petunjuk(): void
    {
        $this->published(['price' => 15000]);
        $this->movement('in', 100);

        $none = $this->makeUser('operator-dkp'); // peran ada, cakupan tidak ada

        Livewire::actingAs($none)->test(Dashboard::class)
            ->assertSee('belum memiliki wilayah kerja')
            ->assertDontSee('Pasar A')
            ->assertDontSee('Gudang A')
            ->assertViewHas('price', fn ($p) => $p['totals']['observations'] === 0)
            ->assertViewHas('stock', fn ($s) => $s['rows']->isEmpty());
    }

    // ---------- Angka dan penelusuran ----------

    public function test_angka_dashboard_cocok_dengan_data_sumber_dan_dapat_ditelusuri(): void
    {
        $this->published(['price' => 10000, 'observed_on' => $this->daysAgo(2)]);
        $this->published(['price' => 12000, 'observed_on' => $this->daysAgo(1)]);
        $this->published(['price' => 14000, 'observed_on' => $this->daysAgo(0)]);

        $commodityId = $this->commodityId;

        Livewire::actingAs($this->boss)->test(Dashboard::class)
            ->assertSee('Rp 14.000')
            ->assertSee('+16,7%')
            ->assertViewHas('price', function ($p) use ($commodityId) {
                return $p['totals'] === ['observations' => 3, 'markets' => 1, 'commodities' => 1]
                    && $p['trend']->count() === 3
                    && $p['trend']->first()['avg'] === 10000.0
                    && $p['markets']->first()['price'] === 14000.0
                    && $p['markets']->first()['change'] === 16.7
                    && $p['overview']->first()['change'] === 16.7
                    && str_contains($p['links']['source'], 'status=published')
                    && str_contains($p['links']['source'], 'commodity=' . $commodityId)
                    && str_contains($p['links']['source'], 'from=' . today()->subDays(29)->toDateString())
                    && str_contains($p['links']['source'], 'to=' . today()->toDateString());
            });
    }

    public function test_data_belum_terbit_tidak_dihitung_di_dashboard(): void
    {
        $this->published(['price' => 15000]);
        $this->makePrice($this->op, ['status' => 'submitted', 'price' => 99999, 'observed_on' => $this->daysAgo(1)]);
        $this->makePrice($this->op, ['status' => 'rejected', 'price' => 88888, 'observed_on' => $this->daysAgo(2)]);
        $this->makePrice($this->op, ['status' => 'draft', 'price' => 77777, 'observed_on' => $this->daysAgo(3)]);

        Livewire::actingAs($this->op)->test(Dashboard::class)
            ->assertDontSee('99.999')
            ->assertDontSee('88.888')
            ->assertDontSee('77.777')
            ->assertViewHas('price', fn ($p) => $p['totals']['observations'] === 1);
    }

    public function test_periode_membatasi_angka(): void
    {
        $this->published(['price' => 15000, 'observed_on' => $this->daysAgo(40)]);

        Livewire::actingAs($this->boss)->test(Dashboard::class)
            ->assertViewHas('price', fn ($p) => $p['totals']['observations'] === 0 && $p['trend']->isEmpty())
            ->set('days', '90')
            ->assertViewHas('price', fn ($p) => $p['totals']['observations'] === 1 && $p['trend']->count() === 1);
    }

    public function test_pilihan_tidak_valid_tidak_menimbulkan_galat(): void
    {
        $this->published(['price' => 15000]);

        Livewire::actingAs($this->boss)->test(Dashboard::class)
            ->set('commodity', '999999')
            ->set('days', 'abc')
            ->assertHasNoErrors()
            ->assertViewHas('periodDays', fn ($d) => $d === 30)
            ->assertViewHas('price', fn ($p) => $p['selected_name'] === 'Beras');
    }

    public function test_ringkasan_stok_tampil_di_dashboard(): void
    {
        $this->movement('in', 250);
        $this->movement('out', 100);

        Livewire::actingAs($this->boss)->test(Dashboard::class)
            ->assertSee('Stok dan cadangan pangan')
            ->assertSee('Beras')
            ->assertSee('150 kg')
            ->assertViewHas('stock', function ($s) {
                $row = $s['rows']->first();

                return $row['balance_milli'] === 150000
                    && $row['inflow_milli'] === 250000
                    && $row['outflow_milli'] === 100000;
            });
    }

    // ---------- Antrean kerja ----------

    private function queueCounts($user): array
    {
        $this->actingAs($user); // global scope memakai pengguna yang sedang login

        return collect(app(WorkQueue::class)->forUser($user))->pluck('count', 'key')->all();
    }

    public function test_antrean_verifikator_tidak_menghitung_data_buatannya_sendiri(): void
    {
        $this->makePrice($this->op, ['status' => 'submitted', 'observed_on' => $this->daysAgo(1)]);
        $this->makePrice($this->ver, ['status' => 'submitted', 'observed_on' => $this->daysAgo(2)]);

        $this->assertSame(1, $this->queueCounts($this->ver)['price_verify']);
        $this->assertArrayNotHasKey('price_verify', $this->queueCounts($this->op)); // operator tidak memverifikasi
    }

    public function test_antrean_menandai_lonjakan_harga(): void
    {
        $this->makePrice($this->op, ['status' => 'submitted', 'is_outlier' => true]);
        $this->actingAs($this->ver);

        $item = collect(app(WorkQueue::class)->forUser($this->ver))->firstWhere('key', 'price_verify');

        $this->assertSame(1, $item['count']);
        $this->assertStringContainsString('1 di antaranya', (string) $item['note']);
    }

    public function test_antrean_operator_menghitung_draf_dan_penolakan_miliknya(): void
    {
        $this->makePrice($this->op, ['status' => 'draft', 'observed_on' => $this->daysAgo(1)]);
        $this->makePrice($this->op, ['status' => 'rejected', 'observed_on' => $this->daysAgo(2)]);
        $this->makePrice($this->ver, ['status' => 'draft', 'observed_on' => $this->daysAgo(3)]); // milik orang lain

        $counts = $this->queueCounts($this->op);

        $this->assertSame(1, $counts['price_draft']);
        $this->assertSame(1, $counts['price_rejected']);
    }

    public function test_antrean_persetujuan_stok_hanya_untuk_penyetuju(): void
    {
        $this->makeMovement(['status' => 'verified', 'verified_by' => $this->ver->id]);

        $this->assertSame(1, $this->queueCounts($this->boss)['stock_approve']);
        $this->assertArrayNotHasKey('stock_approve', $this->queueCounts($this->ver));
        $this->assertArrayNotHasKey('stock_approve', $this->queueCounts($this->op));
    }

    public function test_pemeriksa_tidak_melihat_antrean_persetujuan_atas_verifikasinya_sendiri(): void
    {
        // Penyetuju yang juga pernah memverifikasi mutasi itu tidak boleh menyetujuinya.
        $this->makeMovement(['status' => 'verified', 'verified_by' => $this->boss->id]);

        $this->assertSame(0, $this->queueCounts($this->boss)['stock_approve']);
    }
}
