<?php

namespace Tests\Feature\Stock;

use App\Livewire\Stock\Form;
use App\Livewire\Stock\Index;
use App\Models\StockMovement;
use App\Services\StockWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

/**
 * Kriteria penerimaan: percobaan akses tanpa izin ditolak di sisi server, dan
 * persetujuan hanya oleh Kepala Dinas.
 */
class StockAccessTest extends TestCase
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

    private function verifiedMovement(array $attrs = []): StockMovement
    {
        $m = $this->makeMovement($attrs);
        $workflow = app(StockWorkflow::class);
        $workflow->submit($m, $this->op);
        $workflow->verify($m, $this->ver);

        return $m->fresh();
    }

    // ---------- Rute ----------

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/stok')->assertRedirect('/login');
    }

    public function test_admin_teknis_tidak_boleh_membuka_data_stok(): void
    {
        $this->actingAs($this->makeUser('super-admin-teknis'))->get('/stok')->assertForbidden();
    }

    public function test_operator_dapat_membuka_daftar_stok(): void
    {
        $this->actingAs($this->op)->get('/stok')->assertOk()->assertSee('Stok dan Cadangan Pangan');
    }

    public function test_auditor_dan_operator_opd_tidak_boleh_input_mutasi(): void
    {
        $auditor = $this->makeUser('auditor', [['kabupaten']]);
        $opd = $this->makeUser('operator-opd', [['kabupaten']]);

        $this->actingAs($auditor)->get('/stok')->assertOk();
        $this->actingAs($auditor)->get('/stok/baru')->assertForbidden();
        $this->actingAs($opd)->get('/stok/baru')->assertForbidden();
    }

    public function test_draf_orang_lain_tidak_dapat_dibuka(): void
    {
        $draft = $this->makeMovement();
        $other = $this->makeUser('operator-dkp', [['kabupaten']]);

        $this->actingAs($other)->get("/stok/{$draft->id}/ubah")->assertNotFound();
    }

    // ---------- Cakupan ----------

    public function test_pengguna_hanya_melihat_stok_di_lokasi_cakupannya(): void
    {
        $this->movement('in', 100);
        $this->movement('in', 70, ['storage_location_id' => $this->locB]);

        $scoped = $this->makeUser('operator-dkp', [['district', $this->districtA, 'stok']]);

        Livewire::actingAs($scoped)->test(Index::class)
            ->assertSee('Gudang A')
            ->assertDontSee('Gudang B');
    }

    public function test_pengguna_tanpa_cakupan_stok_tidak_melihat_apa_pun(): void
    {
        $this->movement('in', 100);

        $none = $this->makeUser('operator-dkp'); // peran ada, cakupan tidak ada

        Livewire::actingAs($none)->test(Index::class)->assertDontSee('Gudang A');
    }

    // ---------- Policy ----------

    public function test_izin_per_peran_pada_policy(): void
    {
        $submitted = $this->makeMovement(['status' => 'submitted']);
        $verified = $this->makeMovement(['status' => 'verified', 'verified_by' => $this->ver->id]);
        $approved = $this->makeMovement(['status' => 'approved']);

        $this->assertTrue($this->ver->can('verify', $submitted));
        $this->assertFalse($this->boss->can('verify', $submitted));
        $this->assertFalse($this->op->can('verify', $submitted));

        $this->assertTrue($this->boss->can('approve', $verified));
        $this->assertFalse($this->boss->can('approve', $submitted));   // belum diverifikasi
        $this->assertFalse($this->ver->can('approve', $verified));     // bukan penyetuju
        $this->assertFalse($this->op->can('approve', $verified));

        $this->assertTrue($this->op->can('reverse', $approved));
        $this->assertFalse($this->ver->can('reverse', $approved));
    }

    // ---------- Livewire: formulir ----------

    public function test_form_menyimpan_dan_mengajukan_mutasi_valid(): void
    {
        Livewire::actingAs($this->op)->test(Form::class)
            ->set('movement_type', 'in')
            ->set('storage_location_id', (string) $this->locA)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('movement_date', today()->toDateString())
            ->set('quantity', '1500.5')
            ->set('reference_no', 'BA/01/2026')
            ->set('counterparty', 'Bulog')
            ->call('save', true)
            ->assertHasNoErrors()
            ->assertRedirect(route('stock.index'));

        $this->assertDatabaseHas('stock_movements', [
            'storage_location_id' => $this->locA,
            'movement_type'       => 'in',
            'status'              => 'submitted',
            'created_by'          => $this->op->id,
            'unit_id'             => $this->unitId,
        ]);
        $this->assertDatabaseMissing('food_stocks', ['commodity_id' => $this->commodityId]); // saldo belum berubah
    }

    public function test_form_menolak_lokasi_di_luar_cakupan(): void
    {
        $scoped = $this->makeUser('operator-dkp', [['district', $this->districtA, 'stok']]);

        Livewire::actingAs($scoped)->test(Form::class)
            ->set('movement_type', 'in')
            ->set('storage_location_id', (string) $this->locB)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('movement_date', today()->toDateString())
            ->set('quantity', '10')
            ->set('reference_no', 'BA-1')
            ->set('counterparty', 'X')
            ->call('save', false)
            ->assertHasErrors(['storage_location_id']);

        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count());
    }

    public function test_form_mewajibkan_dokumen_untuk_pengeluaran_dan_menolak_jumlah_tidak_valid(): void
    {
        $base = fn () => Livewire::actingAs($this->op)->test(Form::class)
            ->set('storage_location_id', (string) $this->locA)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('movement_date', today()->toDateString());

        $base()->set('movement_type', 'out')->set('quantity', '10')
            ->call('save', false)
            ->assertHasErrors(['reference_no', 'counterparty']);

        $base()->set('movement_type', 'opening')->set('quantity', '1.2345')
            ->call('save', false)
            ->assertHasErrors(['quantity']);

        $base()->set('movement_type', 'opening')->set('quantity', '0')
            ->call('save', false)
            ->assertHasErrors(['quantity']);

        $base()->set('movement_type', 'opening')->set('quantity', '-5')
            ->call('save', false)
            ->assertHasErrors(['quantity']);
    }

    // ---------- Livewire: daftar ----------

    public function test_kepala_dinas_menyetujui_lewat_daftar_dan_saldo_berubah(): void
    {
        $m = $this->verifiedMovement(['quantity' => 250]);

        Livewire::actingAs($this->boss)->test(Index::class)
            ->call('openPanel', $m->id, 'approve')
            ->assertSet('panelType', 'approve')
            ->call('confirmPanel');

        $this->assertDatabaseHas('stock_movements', ['id' => $m->id, 'status' => 'approved']);
        $this->assertEquals(250.0, $this->balance());
    }

    public function test_operator_dan_pemeriksa_tidak_dapat_menyetujui_lewat_komponen(): void
    {
        $m = $this->verifiedMovement();

        Livewire::actingAs($this->op)->test(Index::class)
            ->call('openPanel', $m->id, 'approve')
            ->assertForbidden();

        Livewire::actingAs($this->ver)->test(Index::class)
            ->call('openPanel', $m->id, 'approve')
            ->assertForbidden();

        $this->assertSame('verified', $m->fresh()->status);
    }

    public function test_persetujuan_pengeluaran_melebihi_saldo_menampilkan_galat_dan_saldo_tetap(): void
    {
        $this->movement('in', 50);
        $out = $this->verifiedMovement(['movement_type' => 'out', 'quantity' => 80]);

        Livewire::actingAs($this->boss)->test(Index::class)
            ->call('openPanel', $out->id, 'approve')
            ->call('confirmPanel')
            ->assertHasErrors(['note']);

        $this->assertSame('verified', $out->fresh()->status);
        $this->assertEquals(50.0, $this->balance());
    }

    public function test_koreksi_lewat_panel_membuat_draf_pembalik_dan_wajib_alasan(): void
    {
        $in = $this->movement('in', 100);

        Livewire::actingAs($this->op)->test(Index::class)
            ->call('openPanel', $in->id, 'reverse')
            ->set('note', '')
            ->call('confirmPanel')
            ->assertHasErrors(['note']);

        $this->assertSame(0, StockMovement::withoutGlobalScopes()->where('movement_type', 'reversal')->count());

        Livewire::actingAs($this->op)->test(Index::class)
            ->call('openPanel', $in->id, 'reverse')
            ->set('note', 'Jumlah salah diketik')
            ->call('confirmPanel')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'reversal', 'reverses_id' => $in->id, 'status' => 'draft',
        ]);
        $this->assertEquals(100.0, $this->balance()); // saldo baru berubah setelah koreksi disetujui
    }

    public function test_operator_tidak_dapat_menghapus_mutasi_yang_sudah_diajukan(): void
    {
        $m = $this->makeMovement();
        app(StockWorkflow::class)->submit($m, $this->op);

        Livewire::actingAs($this->op)->test(Index::class)
            ->call('deleteMovement', $m->id)
            ->assertForbidden();

        $this->assertDatabaseHas('stock_movements', ['id' => $m->id]);
    }
}
