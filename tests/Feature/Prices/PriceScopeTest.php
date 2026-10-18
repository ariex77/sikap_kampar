<?php

namespace Tests\Feature\Prices;

use App\Models\PriceObservation;
use App\Services\DataScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPriceFixtures;
use Tests\TestCase;

/**
 * Kriteria penerimaan: "Operator Kecamatan A tidak dapat melihat data Kecamatan B."
 */
class PriceScopeTest extends TestCase
{
    use CreatesPriceFixtures;
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->guardTestDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPriceFixtures();
    }

    public function test_operator_kecamatan_hanya_melihat_data_kecamatannya(): void
    {
        $opA = $this->makeUser('operator-kecamatan', [['district', $this->districtA, 'harga']]);
        $other = $this->makeUser('operator-kecamatan', [['district', $this->districtB, 'harga']]);

        $inA = $this->makePrice($other, ['status' => 'published']);
        $this->makePrice($other, [
            'status' => 'published', 'market_id' => $this->marketB, 'district_id' => $this->districtB,
        ]);

        $this->actingAs($opA);

        $this->assertSame([$inA->id], PriceObservation::pluck('id')->all());
    }

    public function test_pengguna_tanpa_cakupan_tidak_melihat_apa_pun(): void
    {
        $creator = $this->makeUser('operator-dkp', [['kabupaten']]);
        $this->makePrice($creator, ['status' => 'published']);

        $noScope = $this->makeUser('operator-opd');
        $this->actingAs($noScope);

        $this->assertSame(0, PriceObservation::count());
    }

    public function test_cakupan_kabupaten_melihat_semua_data_non_draf(): void
    {
        $creator = $this->makeUser('operator-dkp', [['kabupaten']]);
        $this->makePrice($creator, ['status' => 'published']);
        $this->makePrice($creator, [
            'status' => 'submitted', 'market_id' => $this->marketB, 'district_id' => $this->districtB,
        ]);

        $auditor = $this->makeUser('auditor', [['kabupaten']]);
        $this->actingAs($auditor);

        $this->assertSame(2, PriceObservation::count());
    }

    public function test_draf_hanya_terlihat_oleh_pembuatnya(): void
    {
        $creator = $this->makeUser('operator-dkp', [['kabupaten']]);
        $draft = $this->makePrice($creator, ['status' => 'draft']);

        $other = $this->makeUser('verifikator-dkp', [['kabupaten']]);

        $this->actingAs($other);
        $this->assertSame(0, PriceObservation::count());

        $this->actingAs($creator);
        $this->assertSame([$draft->id], PriceObservation::pluck('id')->all());
    }

    public function test_cakupan_yang_sudah_kedaluwarsa_diabaikan(): void
    {
        $creator = $this->makeUser('operator-dkp', [['kabupaten']]);
        $this->makePrice($creator, ['status' => 'published']);

        $expired = $this->makeUser('operator-kecamatan', [
            ['district', $this->districtA, 'harga', null, today()->subDay()->toDateString()],
        ]);

        $this->actingAs($expired);
        $this->assertSame(0, PriceObservation::count());
    }

    public function test_cakupan_domain_lain_tidak_memberi_akses_harga(): void
    {
        $creator = $this->makeUser('operator-dkp', [['kabupaten']]);
        $this->makePrice($creator, ['status' => 'published']);

        $stokOnly = $this->makeUser('operator-kecamatan', [['district', $this->districtA, 'stok']]);

        $this->actingAs($stokOnly);
        $this->assertSame(0, PriceObservation::count());
    }

    public function test_cakupan_pasar_membatasi_ke_pasar_tertentu(): void
    {
        $creator = $this->makeUser('operator-dkp', [['kabupaten']]);
        $inMarket = $this->makePrice($creator, ['status' => 'published']);
        $this->makePrice($creator, [
            'status' => 'published', 'market_id' => $this->marketB, 'district_id' => $this->districtB,
        ]);

        $marketOnly = $this->makeUser('operator-kecamatan', [['market', $this->marketA, 'harga']]);
        $this->actingAs($marketOnly);

        $this->assertSame([$inMarket->id], PriceObservation::pluck('id')->all());
    }

    public function test_daftar_pasar_untuk_input_mengikuti_cakupan(): void
    {
        $opA = $this->makeUser('operator-kecamatan', [['district', $this->districtA, 'harga']]);
        $kab = $this->makeUser('operator-dkp', [['kabupaten']]);
        $none = $this->makeUser('operator-opd');

        $scope = app(DataScope::class);

        $this->assertSame([$this->marketA], $scope->markets($opA)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->marketA, $this->marketB], $scope->markets($kab)->pluck('id')->all());
        $this->assertSame([], $scope->markets($none)->pluck('id')->all());
    }
}
