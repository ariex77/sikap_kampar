<?php

namespace Tests\Feature\Stock;

use App\Models\StockMovement;
use App\Services\StockLedger;
use App\Services\StockWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

/**
 * Kriteria penerimaan nomor 3: saldo stok konsisten terhadap seluruh transaksi yang telah disetujui.
 */
class StockLedgerTest extends TestCase
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

    public function test_rekonsiliasi_bersih_setelah_rangkaian_mutasi(): void
    {
        $this->movement('opening', 500);
        $in = $this->movement('in', 120.5);
        $out = $this->movement('out', 80.25);
        $this->movement('in', 30, ['storage_location_id' => $this->locB]);

        $workflow = app(StockWorkflow::class);
        $this->approveMovement($workflow->createReversal($out, $this->op, 'Penyaluran batal'));
        $this->approveMovement($workflow->createReversal($in, $this->op, 'Salah catat'));

        $this->assertSame([], app(StockLedger::class)->reconcile());
        $this->assertEquals(500.0 + 120.5 - 80.25 + 80.25 - 120.5, $this->balance());
        $this->assertEquals(30.0, $this->balance($this->locB));
    }

    public function test_mutasi_yang_belum_disetujui_tidak_memengaruhi_saldo_maupun_rekonsiliasi(): void
    {
        $this->movement('in', 100);

        $pending = $this->makeMovement(['movement_type' => 'out', 'quantity' => 40]);
        app(StockWorkflow::class)->submit($pending, $this->op);

        $this->assertEquals(100.0, $this->balance());
        $this->assertSame([], app(StockLedger::class)->reconcile());
    }

    public function test_rekonsiliasi_mendeteksi_saldo_yang_menyimpang(): void
    {
        $this->movement('in', 100);

        DB::table('food_stocks')->update(['balance' => 999]); // simulasi perubahan langsung ke database

        $mismatches = app(StockLedger::class)->reconcile();

        $this->assertCount(1, $mismatches);
        $this->assertSame($this->locA, $mismatches[0]['storage_location_id']);
        $this->assertSame('999.000', $mismatches[0]['balance']);
        $this->assertSame('100.000', $mismatches[0]['ledger']);
    }

    public function test_rekonsiliasi_mendeteksi_saldo_yang_hilang(): void
    {
        $this->movement('in', 100);

        DB::table('food_stocks')->delete();

        $this->assertCount(1, app(StockLedger::class)->reconcile());
    }

    public function test_perintah_artisan_melaporkan_hasil_lewat_kode_keluar(): void
    {
        $this->movement('in', 100);

        $this->artisan('stock:reconcile')->assertExitCode(0);

        DB::table('food_stocks')->update(['balance' => 1]);

        $this->artisan('stock:reconcile')->assertExitCode(1);
    }

    /**
     * Uji acak berulang dengan benih tetap: ratusan kombinasi penerimaan, pengeluaran, dan koreksi.
     * Setelah setiap langkah saldo tidak boleh negatif, dan di akhir saldo harus sama dengan
     * hasil hitung independen serta lolos rekonsiliasi.
     */
    public function test_invarian_saldo_pada_rangkaian_acak(): void
    {
        mt_srand(20261017);

        $workflow = app(StockWorkflow::class);
        $expected = 0.0;
        $approvedIds = [];

        for ($i = 0; $i < 80; $i++) {
            $roll = mt_rand(1, 10);

            if ($roll <= 2 && $approvedIds) {
                // koreksi atas mutasi acak yang sudah disetujui
                $target = StockMovement::withoutGlobalScopes()->find($approvedIds[array_rand($approvedIds)]);

                try {
                    $reversal = $workflow->createReversal($target, $this->op, 'Koreksi uji acak');
                } catch (DomainException) {
                    continue; // sudah pernah dikoreksi
                }

                $movement = $reversal;
                $effect = in_array($target->movement_type, ['in', 'opening'], true)
                    ? -(float) $target->quantity
                    : (float) $target->quantity;
            } else {
                $type = mt_rand(1, 10) <= 6 ? 'in' : 'out';
                $quantity = mt_rand(1, 3000) / 10;
                $movement = $this->makeMovement(['movement_type' => $type, 'quantity' => $quantity]);
                $effect = $type === 'in' ? $quantity : -$quantity;
            }

            $workflow->submit($movement, $this->op);
            $workflow->verify($movement, $this->ver);

            try {
                $workflow->approve($movement, $this->boss);
            } catch (DomainException) {
                // Saldo tidak cukup: mutasi tetap Diverifikasi, saldo tidak berubah.
                $this->assertGreaterThanOrEqual(0.0, $this->balance());
                continue;
            }

            $expected += $effect;
            if ($movement->movement_type !== 'reversal') {
                $approvedIds[] = $movement->id;
            }

            $this->assertGreaterThanOrEqual(0.0, $this->balance(), "Saldo negatif pada langkah $i");
            $this->assertEqualsWithDelta($expected, $this->balance(), 0.0005, "Saldo menyimpang pada langkah $i");
        }

        $this->assertSame([], app(StockLedger::class)->reconcile());
        $this->assertGreaterThan(10, count($approvedIds), 'Uji acak harus benar-benar menghasilkan mutasi.');
    }
}
