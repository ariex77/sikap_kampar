<?php

namespace Tests\Feature\Prices;

use App\Livewire\Prices\Form;
use App\Livewire\Prices\Index;
use App\Models\PriceObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPriceFixtures;
use Tests\TestCase;

/**
 * Kriteria penerimaan: percobaan akses tanpa izin ditolak di sisi server
 * (rute, Policy, dan komponen Livewire), bukan hanya disembunyikan di tampilan.
 */
class PriceAccessTest extends TestCase
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

    private function operatorA()
    {
        return $this->makeUser('operator-kecamatan', [['district', $this->districtA, 'harga']]);
    }

    // ---------- Rute ----------

    public function test_tamu_diarahkan_ke_halaman_login(): void
    {
        $this->get('/harga')->assertRedirect('/login');
    }

    public function test_admin_teknis_tidak_boleh_membuka_data_harga(): void
    {
        $admin = $this->makeUser('super-admin-teknis');

        $this->actingAs($admin)->get('/harga')->assertForbidden();
    }

    public function test_operator_dapat_membuka_daftar_harga(): void
    {
        $this->actingAs($this->operatorA())->get('/harga')->assertOk()->assertSee('Harga Pangan');
    }

    public function test_auditor_dapat_melihat_tetapi_tidak_boleh_input(): void
    {
        $auditor = $this->makeUser('auditor', [['kabupaten']]);

        $this->actingAs($auditor)->get('/harga')->assertOk();
        $this->actingAs($auditor)->get('/harga/baru')->assertForbidden();
    }

    public function test_draf_milik_orang_lain_tidak_dapat_dibuka_untuk_diubah(): void
    {
        $other = $this->makeUser('operator-dkp', [['kabupaten']]);
        $draft = $this->makePrice($other);

        $this->actingAs($this->operatorA())->get("/harga/{$draft->id}/ubah")->assertNotFound();
    }

    public function test_data_kecamatan_lain_tidak_dapat_dibuka(): void
    {
        $other = $this->makeUser('operator-dkp', [['kabupaten']]);
        $inB = $this->makePrice($other, [
            'status' => 'rejected', 'created_by' => $other->id,
            'market_id' => $this->marketB, 'district_id' => $this->districtB,
        ]);

        $this->actingAs($this->operatorA())->get("/harga/{$inB->id}/ubah")->assertNotFound();
    }

    public function test_pembuat_dapat_membuka_drafnya_sendiri(): void
    {
        $op = $this->operatorA();
        $draft = $this->makePrice($op);

        $this->actingAs($op)->get("/harga/{$draft->id}/ubah")->assertOk();
    }

    // ---------- Policy ----------

    public function test_izin_per_peran_pada_policy(): void
    {
        $creator = $this->makeUser('operator-dkp', [['kabupaten']]);
        $verifier = $this->makeUser('verifikator-dkp', [['kabupaten']]);
        $submitted = $this->makePrice($creator, ['status' => 'submitted']);
        $draft = $this->makePrice($creator, ['observed_on' => today()->subDay()->toDateString()]);

        $this->assertTrue($verifier->can('verify', $submitted));
        $this->assertFalse($creator->can('verify', $submitted));      // bukan pemeriksa
        $this->assertFalse($verifier->can('update', $draft));         // bukan miliknya, dan tanpa izin input
        $this->assertTrue($creator->can('update', $draft));
        $this->assertFalse($creator->can('update', $submitted));      // sudah diajukan, terkunci
        $this->assertFalse($verifier->can('verify', $draft));         // belum diajukan
    }

    // ---------- Livewire: formulir ----------

    public function test_form_menolak_pasar_di_luar_wilayah_kerja(): void
    {
        $op = $this->operatorA();

        Livewire::actingAs($op)->test(Form::class)
            ->set('market_id', (string) $this->marketB)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('observed_on', today()->toDateString())
            ->set('price', '15000')
            ->call('save', false)
            ->assertHasErrors(['market_id']);

        $this->assertSame(0, PriceObservation::withoutGlobalScopes()->count());
    }

    public function test_form_menyimpan_dan_mengajukan_harga_yang_valid(): void
    {
        $op = $this->operatorA();

        Livewire::actingAs($op)->test(Form::class)
            ->set('market_id', (string) $this->marketA)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('observed_on', today()->toDateString())
            ->set('price', '15000')
            ->call('save', true)
            ->assertHasNoErrors()
            ->assertRedirect(route('prices.index'));

        $this->assertDatabaseHas('price_observations', [
            'market_id'    => $this->marketA,
            'district_id'  => $this->districtA,
            'unit_id'      => $this->unitId,
            'status'       => 'submitted',
            'created_by'   => $op->id,
        ]);
    }

    public function test_form_menolak_data_ganda_dan_tanggal_masa_depan(): void
    {
        $op = $this->operatorA();
        $this->makePrice($op); // sudah ada: pasar A, beras, hari ini

        Livewire::actingAs($op)->test(Form::class)
            ->set('market_id', (string) $this->marketA)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('observed_on', today()->toDateString())
            ->set('price', '16000')
            ->call('save', false)
            ->assertHasErrors(['commodity_id']);

        Livewire::actingAs($op)->test(Form::class)
            ->set('market_id', (string) $this->marketA)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('observed_on', today()->addDay()->toDateString())
            ->set('price', '16000')
            ->call('save', false)
            ->assertHasErrors(['observed_on']);
    }

    // ---------- Livewire: daftar ----------

    public function test_verifikator_dapat_memverifikasi_lewat_daftar(): void
    {
        $op = $this->operatorA();
        $ver = $this->makeUser('verifikator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op, ['status' => 'submitted']);

        Livewire::actingAs($ver)->test(Index::class)
            ->assertSee('Pasar A')
            ->call('openPanel', $price->id, 'verify')
            ->assertSet('panelType', 'verify')
            ->call('confirmPanel');

        $this->assertDatabaseHas('price_observations', ['id' => $price->id, 'status' => 'published']);
    }

    public function test_penolakan_wajib_menyertakan_alasan(): void
    {
        $op = $this->operatorA();
        $ver = $this->makeUser('verifikator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op, ['status' => 'submitted']);

        Livewire::actingAs($ver)->test(Index::class)
            ->call('openPanel', $price->id, 'reject')
            ->set('note', '')
            ->call('confirmPanel')
            ->assertHasErrors(['note']);

        $this->assertSame('submitted', $price->fresh()->status);
    }

    public function test_operator_tidak_dapat_memverifikasi_lewat_komponen(): void
    {
        $op = $this->operatorA();
        $other = $this->makeUser('operator-dkp', [['kabupaten']]);
        $price = $this->makePrice($other, ['status' => 'submitted']);

        Livewire::actingAs($op)->test(Index::class)
            ->call('openPanel', $price->id, 'verify')
            ->assertForbidden();

        $this->assertSame('submitted', $price->fresh()->status);
    }

    public function test_operator_tidak_dapat_menghapus_data_yang_sudah_diajukan(): void
    {
        $op = $this->operatorA();
        $price = $this->makePrice($op, ['status' => 'submitted']);

        Livewire::actingAs($op)->test(Index::class)
            ->call('deletePrice', $price->id)
            ->assertForbidden();

        $this->assertDatabaseHas('price_observations', ['id' => $price->id]);
    }
}
