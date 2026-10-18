<?php

namespace Tests\Feature\Prices;

use App\Models\PriceObservation;
use App\Services\PriceWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPriceFixtures;
use Tests\TestCase;

/**
 * Kriteria penerimaan: alur input -> verifikasi -> terbit berjalan sesuai aturan,
 * penginput tidak menyetujui sendiri, dan lonjakan harga terdeteksi.
 */
class PriceWorkflowTest extends TestCase
{
    use CreatesPriceFixtures;
    use RefreshDatabase;

    private PriceWorkflow $workflow;

    protected function beforeRefreshingDatabase(): void
    {
        $this->guardTestDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPriceFixtures();
        $this->workflow = app(PriceWorkflow::class);
    }

    public function test_alur_normal_draf_diajukan_diverifikasi_terbit(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $ver = $this->makeUser('verifikator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op);

        $this->workflow->submit($price, $op);
        $this->assertSame('submitted', $price->fresh()->status);

        $this->workflow->verify($price, $ver);

        $this->assertDatabaseHas('price_observations', [
            'id' => $price->id, 'status' => 'published', 'verified_by' => $ver->id,
        ]);
        $this->assertDatabaseHas('publications', ['publishable_id' => $price->id, 'published_by' => $ver->id]);
        $this->assertDatabaseCount('approval_histories', 3); // diajukan, diverifikasi, terbit
        $this->assertDatabaseHas('audit_logs', ['action' => 'verified', 'entity_id' => (string) $price->id]);
    }

    public function test_penginput_tidak_dapat_memverifikasi_data_sendiri(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op);
        $this->workflow->submit($price, $op);

        $this->expectException(DomainException::class);
        $this->workflow->verify($price, $op);
    }

    public function test_penginput_tidak_dapat_menolak_data_sendiri(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op);
        $this->workflow->submit($price, $op);

        $this->expectException(DomainException::class);
        $this->workflow->reject($price, $op, 'alasan apa pun');
    }

    public function test_orang_lain_tidak_dapat_mengajukan_data_milik_pembuat(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $other = $this->makeUser('operator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op);

        $this->expectException(DomainException::class);
        $this->workflow->submit($price, $other);
    }

    public function test_data_yang_belum_diajukan_tidak_dapat_diverifikasi(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $ver = $this->makeUser('verifikator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op); // masih draf

        $this->expectException(DomainException::class);
        $this->workflow->verify($price, $ver);
    }

    public function test_penolakan_mengembalikan_data_dan_dapat_diajukan_ulang(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $ver = $this->makeUser('verifikator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op);

        $this->workflow->submit($price, $op);
        $this->workflow->reject($price, $ver, 'Harga tidak sesuai dengan pengecekan lapangan');

        $this->assertDatabaseHas('price_observations', [
            'id' => $price->id, 'status' => 'rejected',
            'rejection_reason' => 'Harga tidak sesuai dengan pengecekan lapangan',
        ]);

        $this->workflow->submit($price, $op);

        $fresh = $price->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_lonjakan_harga_ditandai_dan_wajib_catatan_saat_verifikasi(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $ver = $this->makeUser('verifikator-dkp', [['kabupaten']]);

        $this->makePrice($op, [
            'status' => 'published', 'price' => 10000, 'observed_on' => today()->subDay()->toDateString(),
        ]);
        $price = $this->makePrice($op, ['price' => 15000]); // +50%, ambang bawaan 20%

        $this->workflow->submit($price, $op);

        $fresh = $price->fresh();
        $this->assertTrue($fresh->is_outlier);
        $this->assertEquals(10000.0, (float) $fresh->previous_price);

        try {
            $this->workflow->verify($price, $ver); // tanpa catatan
            $this->fail('Verifikasi tanpa catatan seharusnya ditolak untuk data lonjakan.');
        } catch (DomainException) {
            $this->assertSame('submitted', $price->fresh()->status);
        }

        $this->workflow->verify($price, $ver, 'Sudah dicek ke pedagang, stok sedang menipis');
        $this->assertSame('published', $price->fresh()->status);
    }

    public function test_perubahan_dalam_ambang_tidak_ditandai(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);

        $this->makePrice($op, [
            'status' => 'published', 'price' => 10000, 'observed_on' => today()->subDay()->toDateString(),
        ]);
        $price = $this->makePrice($op, ['price' => 11000]); // +10%

        $this->workflow->submit($price, $op);

        $this->assertFalse($price->fresh()->is_outlier);
    }

    public function test_ambang_dapat_diubah_lewat_pengaturan(): void
    {
        DB::table('system_settings')->where('key', 'price.outlier_threshold_pct')->update(['value' => json_encode(5)]);

        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $this->makePrice($op, [
            'status' => 'published', 'price' => 10000, 'observed_on' => today()->subDay()->toDateString(),
        ]);
        $price = $this->makePrice($op, ['price' => 11000]); // +10% > 5%

        $this->workflow->submit($price, $op);

        $this->assertTrue($price->fresh()->is_outlier);
    }

    public function test_harga_pembanding_hanya_dari_data_yang_sudah_terbit(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);

        // Hari sebelumnya baru diajukan (belum terbit) sehingga tidak boleh jadi pembanding
        $this->makePrice($op, [
            'status' => 'submitted', 'price' => 10000, 'observed_on' => today()->subDay()->toDateString(),
        ]);
        $price = $this->makePrice($op, ['price' => 15000]);

        $this->workflow->submit($price, $op);

        $fresh = $price->fresh();
        $this->assertNull($fresh->previous_price);
        $this->assertFalse($fresh->is_outlier);
    }

    public function test_periode_terkunci_menolak_pengajuan(): void
    {
        DB::table('reporting_periods')->insert([
            'period_type' => 'daily', 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
            'label' => 'Hari ini', 'status' => 'locked', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op);

        $this->expectException(DomainException::class);
        $this->workflow->submit($price, $op);
    }

    public function test_draf_dapat_dihapus_pembuatnya_tetapi_data_diajukan_tidak(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);

        $draft = $this->makePrice($op);
        $this->workflow->deleteDraft($draft, $op);
        $this->assertDatabaseMissing('price_observations', ['id' => $draft->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deleted', 'entity_id' => (string) $draft->id]);

        $submitted = $this->makePrice($op, ['status' => 'submitted', 'observed_on' => today()->subDay()->toDateString()]);
        $this->expectException(DomainException::class);
        $this->workflow->deleteDraft($submitted, $op);
    }

    public function test_setiap_aksi_tercatat_di_audit_log(): void
    {
        $op = $this->makeUser('operator-dkp', [['kabupaten']]);
        $price = $this->makePrice($op);

        $this->workflow->submit($price, $op);

        $this->assertDatabaseHas('audit_logs', ['action' => 'submitted', 'entity_type' => 'price_observation', 'entity_id' => (string) $price->id]);
        $this->assertInstanceOf(PriceObservation::class, $price->fresh());
    }
}
