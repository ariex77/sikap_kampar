<?php

namespace Tests\Feature\Stock;

use App\Models\StockMovement;
use App\Services\StockWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

/**
 * Kriteria penerimaan: input, verifikasi, persetujuan, dan koreksi stok berjalan sesuai SOP,
 * dan saldo tidak pernah negatif.
 */
class StockWorkflowTest extends TestCase
{
    use CreatesStockFixtures;
    use RefreshDatabase;

    private StockWorkflow $workflow;

    protected function beforeRefreshingDatabase(): void
    {
        $this->guardTestDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockFixtures();
        $this->workflow = app(StockWorkflow::class);
    }

    public function test_saldo_hanya_berubah_saat_mutasi_disetujui(): void
    {
        $m = $this->makeMovement(['quantity' => 100]);

        $this->workflow->submit($m, $this->op);
        $this->assertDatabaseMissing('food_stocks', ['commodity_id' => $this->commodityId]);

        $this->workflow->verify($m, $this->ver);
        $this->assertDatabaseMissing('food_stocks', ['commodity_id' => $this->commodityId]);

        $this->workflow->approve($m, $this->boss);
        $this->assertEquals(100.0, $this->balance());
        $this->assertDatabaseHas('stock_movements', ['id' => $m->id, 'status' => 'approved', 'approved_by' => $this->boss->id]);
        $this->assertDatabaseCount('approval_histories', 3);
        $this->assertDatabaseHas('audit_logs', ['action' => 'approved', 'entity_id' => (string) $m->id]);
    }

    public function test_pengeluaran_melebihi_saldo_ditolak_saat_persetujuan(): void
    {
        $this->movement('in', 50);

        $out = $this->makeMovement(['movement_type' => 'out', 'quantity' => 80]);
        $this->workflow->submit($out, $this->op);
        $this->workflow->verify($out, $this->ver);

        $this->assertDomainError(fn () => $this->workflow->approve($out, $this->boss));

        $this->assertEquals(50.0, $this->balance());
        $this->assertSame('verified', $out->fresh()->status);
    }

    public function test_dua_pengeluaran_tidak_boleh_melebihi_saldo_bersama(): void
    {
        $this->movement('in', 100);

        $a = $this->makeMovement(['movement_type' => 'out', 'quantity' => 60]);
        $b = $this->makeMovement(['movement_type' => 'out', 'quantity' => 60]);

        foreach ([$a, $b] as $m) {
            $this->workflow->submit($m, $this->op);
            $this->workflow->verify($m, $this->ver);
        }

        $this->workflow->approve($a, $this->boss);
        $this->assertEquals(40.0, $this->balance());

        // Masing-masing pengeluaran sah sendirian, tetapi bersama-sama melebihi saldo.
        $this->assertDomainError(fn () => $this->workflow->approve($b, $this->boss));
        $this->assertEquals(40.0, $this->balance());
        $this->assertSame('verified', $b->fresh()->status);
    }

    public function test_pengeluaran_sama_dengan_saldo_diperbolehkan_dan_saldo_nol(): void
    {
        $this->movement('in', 25.5);
        $this->movement('out', 25.5);

        $this->assertEquals(0.0, $this->balance());
    }

    public function test_penginput_tidak_dapat_memverifikasi_atau_menyetujui_sendiri(): void
    {
        $m = $this->makeMovement();
        $this->workflow->submit($m, $this->op);

        $this->assertDomainError(fn () => $this->workflow->verify($m, $this->op));

        $this->workflow->verify($m, $this->ver);

        $this->assertDomainError(fn () => $this->workflow->approve($m, $this->op));   // penginput
        $this->assertDomainError(fn () => $this->workflow->approve($m, $this->ver));  // pemeriksa
        $this->assertSame('verified', $m->fresh()->status);
    }

    public function test_tidak_dapat_menyetujui_mutasi_yang_belum_diverifikasi(): void
    {
        $m = $this->makeMovement();
        $this->workflow->submit($m, $this->op);

        $this->assertDomainError(fn () => $this->workflow->approve($m, $this->boss));
        $this->assertDatabaseMissing('food_stocks', ['commodity_id' => $this->commodityId]);
    }

    public function test_orang_lain_tidak_dapat_mengajukan_mutasi_milik_pembuat(): void
    {
        $m = $this->makeMovement();

        $this->assertDomainError(fn () => $this->workflow->submit($m, $this->ver));
    }

    public function test_stok_awal_hanya_boleh_satu_kali(): void
    {
        $this->movement('opening', 100);

        $second = $this->makeMovement(['movement_type' => 'opening', 'quantity' => 50]);
        $this->workflow->submit($second, $this->op);
        $this->workflow->verify($second, $this->ver);

        $this->assertDomainError(fn () => $this->workflow->approve($second, $this->boss));
        $this->assertEquals(100.0, $this->balance());
    }

    public function test_koreksi_penerimaan_mengembalikan_saldo_dan_tidak_bisa_dua_kali(): void
    {
        $in = $this->movement('in', 100);

        $reversal = $this->workflow->createReversal($in, $this->op, 'Salah input jumlah');
        $this->assertSame('reversal', $reversal->movement_type);
        $this->assertSame($in->id, $reversal->reverses_id);

        $this->approveMovement($reversal);
        $this->assertEquals(0.0, $this->balance());

        $this->assertDomainError(fn () => $this->workflow->createReversal($in, $this->op, 'Koreksi lagi'));
    }

    public function test_koreksi_penerimaan_gagal_bila_stok_sudah_terpakai(): void
    {
        $in = $this->movement('in', 100);
        $this->movement('out', 70);

        $reversal = $this->workflow->createReversal($in, $this->op, 'Salah input');
        $this->workflow->submit($reversal, $this->op);
        $this->workflow->verify($reversal, $this->ver);

        $this->assertDomainError(fn () => $this->workflow->approve($reversal, $this->boss));
        $this->assertEquals(30.0, $this->balance()); // saldo tidak pernah negatif
    }

    public function test_koreksi_pengeluaran_mengembalikan_stok(): void
    {
        $this->movement('in', 100);
        $out = $this->movement('out', 40);
        $this->assertEquals(60.0, $this->balance());

        $this->approveMovement($this->workflow->createReversal($out, $this->op, 'Penyaluran dibatalkan'));

        $this->assertEquals(100.0, $this->balance());
    }

    public function test_koreksi_hanya_untuk_mutasi_disetujui_dan_bukan_koreksi(): void
    {
        $draft = $this->makeMovement();
        $this->assertDomainError(fn () => $this->workflow->createReversal($draft, $this->op, 'x'));

        $in = $this->movement('in', 10);
        $reversal = $this->approveMovement($this->workflow->createReversal($in, $this->op, 'Salah input'));
        $this->assertDomainError(fn () => $this->workflow->createReversal($reversal, $this->op, 'Koreksi koreksi'));
    }

    public function test_koreksi_yang_ditolak_dapat_dibuat_ulang(): void
    {
        $in = $this->movement('in', 10);

        $first = $this->workflow->createReversal($in, $this->op, 'Percobaan pertama');
        $this->workflow->submit($first, $this->op);
        $this->workflow->reject($first, $this->ver, 'Alasan belum jelas');

        $second = $this->workflow->createReversal($in, $this->op, 'Percobaan kedua');
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_penolakan_oleh_pemeriksa_dan_oleh_penyetuju(): void
    {
        $a = $this->makeMovement();
        $this->workflow->submit($a, $this->op);
        $this->workflow->reject($a, $this->ver, 'Dokumen belum lengkap');
        $this->assertSame('rejected', $a->fresh()->status);

        // Dapat diperbaiki lalu diajukan ulang
        $this->workflow->submit($a, $this->op);
        $this->assertSame('submitted', $a->fresh()->status);
        $this->assertNull($a->fresh()->rejection_reason);

        $b = $this->makeMovement();
        $this->workflow->submit($b, $this->op);
        $this->workflow->verify($b, $this->ver);
        $this->workflow->reject($b, $this->boss, 'Jumlah tidak sesuai berita acara');
        $this->assertSame('rejected', $b->fresh()->status);
        $this->assertNull($b->fresh()->verified_by);
    }

    public function test_pemeriksa_dan_pembuat_tidak_dapat_menolak_di_tahap_yang_salah(): void
    {
        $m = $this->makeMovement();
        $this->workflow->submit($m, $this->op);

        $this->assertDomainError(fn () => $this->workflow->reject($m, $this->op, 'menolak sendiri'));

        $this->workflow->verify($m, $this->ver);
        $this->assertDomainError(fn () => $this->workflow->reject($m, $this->ver, 'menolak hasil verifikasi sendiri'));
    }

    public function test_periode_terkunci_menghalangi_persetujuan(): void
    {
        $m = $this->makeMovement();
        $this->workflow->submit($m, $this->op);
        $this->workflow->verify($m, $this->ver);

        DB::table('reporting_periods')->insert([
            'period_type' => 'daily', 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
            'label' => 'Hari ini', 'status' => 'locked', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertDomainError(fn () => $this->workflow->approve($m, $this->boss));
        $this->assertEquals(0.0, $this->balance());
    }

    public function test_mutasi_yang_sudah_diajukan_tidak_boleh_diubah_isinya(): void
    {
        $m = $this->makeMovement(['quantity' => 100]);
        $this->workflow->submit($m, $this->op);

        $submitted = StockMovement::withoutGlobalScopes()->find($m->id);
        $submitted->quantity = 999;

        $this->expectException(LogicException::class);
        $submitted->save();
    }

    public function test_mutasi_yang_sudah_disetujui_tidak_boleh_dihapus(): void
    {
        $approved = $this->movement('in', 10);

        $this->expectException(LogicException::class);
        $approved->delete();
    }

    public function test_draf_dapat_dihapus_pembuatnya(): void
    {
        $draft = $this->makeMovement();
        $this->workflow->deleteDraft($draft, $this->op);

        $this->assertDatabaseMissing('stock_movements', ['id' => $draft->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deleted', 'entity_id' => (string) $draft->id]);
    }

    public function test_baris_saldo_dikunci_saat_persetujuan_di_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Penguncian baris (FOR UPDATE) hanya berlaku di MySQL.');
        }

        $m = $this->makeMovement();
        $this->workflow->submit($m, $this->op);
        $this->workflow->verify($m, $this->ver);

        DB::enableQueryLog();
        $this->workflow->approve($m, $this->boss);

        $locked = collect(DB::getQueryLog())->pluck('query')->filter(
            fn ($q) => str_contains($q, 'food_stocks') && str_contains(strtolower($q), 'for update')
        );

        $this->assertNotEmpty($locked, 'Baris food_stocks harus dikunci dengan FOR UPDATE saat persetujuan.');
    }
}
