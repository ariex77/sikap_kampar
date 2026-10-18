<?php

namespace Tests\Feature\Stock;

use App\Livewire\Stock\Form;
use App\Livewire\Stock\Index;
use App\Models\StockMovement;
use App\Services\StockLedger;
use App\Services\StockWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

/**
 * Pengerasan: mutasi disetujui terkunci total, formulir tidak membuat draf ganda,
 * izin mengajukan diperiksa terpisah, dan perintah uji paralel tidak berjalan di luar lingkungan lokal.
 */
class StockHardeningTest extends TestCase
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

    public function test_mutasi_disetujui_tidak_boleh_diubah_dalam_bentuk_apa_pun(): void
    {
        $approved = $this->movement('in', 10);

        // Mengembalikan status ke draf
        $copy = StockMovement::withoutGlobalScopes()->find($approved->id);
        $copy->status = 'draft';

        try {
            $copy->save();
            $this->fail('Mengubah status mutasi yang sudah disetujui seharusnya ditolak.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('approved', StockMovement::withoutGlobalScopes()->find($approved->id)->status);

        // Mengubah kolom yang tampak sepele
        $copy = StockMovement::withoutGlobalScopes()->find($approved->id);
        $copy->notes = 'diubah diam-diam';

        $this->expectException(LogicException::class);
        $copy->save();
    }

    public function test_persetujuan_yang_gagal_tidak_mengubah_saldo_dan_tetap_konsisten(): void
    {
        $workflow = app(StockWorkflow::class);

        $out = $this->makeMovement(['movement_type' => 'out', 'quantity' => 5]);
        $workflow->submit($out, $this->op);
        $workflow->verify($out, $this->ver);

        $this->assertDomainError(fn () => $workflow->approve($out, $this->boss));

        $this->assertEquals(0.0, $this->balance());
        $this->assertSame('verified', $out->fresh()->status);
        $this->assertSame([], app(StockLedger::class)->reconcile());
    }

    public function test_simpan_berulang_pada_formulir_tidak_membuat_draf_ganda(): void
    {
        $component = Livewire::actingAs($this->op)->test(Form::class)
            ->set('movement_type', 'in')
            ->set('storage_location_id', (string) $this->locA)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('movement_date', today()->toDateString())
            ->set('quantity', '25')
            ->set('reference_no', 'BA-1')
            ->set('counterparty', 'Pemasok');

        $component->call('save', false)->call('save', false);

        $this->assertSame(1, StockMovement::withoutGlobalScopes()->count());
    }

    public function test_peran_tanpa_izin_ajukan_tidak_dapat_mengajukan_lewat_formulir(): void
    {
        Role::findOrCreate('penginput-saja', 'web')->syncPermissions(['dashboard.view', 'stock.view', 'stock.create']);
        $user = $this->makeUser('penginput-saja', [['kabupaten']]);

        $fill = fn () => Livewire::actingAs($user)->test(Form::class)
            ->set('movement_type', 'in')
            ->set('storage_location_id', (string) $this->locA)
            ->set('commodity_id', (string) $this->commodityId)
            ->set('movement_date', today()->toDateString())
            ->set('quantity', '25')
            ->set('reference_no', 'BA-2')
            ->set('counterparty', 'Pemasok');

        $fill()->call('save', true)->assertForbidden();
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count()); // ditolak sebelum menyimpan apa pun

        $fill()->call('save', false)->assertHasNoErrors();
        $this->assertDatabaseHas('stock_movements', ['created_by' => $user->id, 'status' => 'draft']);
    }

    public function test_lokasi_yang_dihapus_lunak_tidak_merusak_halaman_stok(): void
    {
        $this->movement('in', 100);

        DB::table('storage_locations')->where('id', $this->locA)->update(['deleted_at' => now()]);

        Livewire::actingAs($this->boss)->test(Index::class)->assertSee('Gudang A');
    }

    public function test_uji_paralel_menolak_berjalan_di_luar_lingkungan_lokal(): void
    {
        // APP_ENV pada tes adalah "testing", sehingga perintah harus berhenti sebelum menyentuh data apa pun.
        $this->artisan('stock:parallel-check')->assertExitCode(1);

        $this->assertSame(0, DB::table('commodities')->where('code', 'like', 'ZZ-UJI-%')->count());
    }
}
