<?php

namespace Tests\Feature\Dashboard;

use App\Models\PriceObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

/**
 * Kriteria penerimaan nomor 6: portal publik hanya menampilkan informasi yang telah disetujui
 * untuk dipublikasikan, dan tidak membocorkan data internal.
 */
class PublicPortalTest extends TestCase
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

    public function test_tamu_dapat_membuka_portal_dan_melihat_harga_terbit(): void
    {
        $this->published(['price' => 15500]);

        $this->get('/publik')
            ->assertOk()
            ->assertSee('Harga Pangan Kabupaten Kampar')
            ->assertSee('Beras')
            ->assertSee('Pasar A')
            ->assertSee('Rp 15.500');
    }

    public function test_data_yang_belum_terbit_tidak_pernah_tampil(): void
    {
        $this->published(['price' => 15500]);
        $this->makePrice($this->op, ['status' => 'draft', 'price' => 11111, 'observed_on' => $this->daysAgo(1)]);
        $this->makePrice($this->op, ['status' => 'submitted', 'price' => 22222, 'observed_on' => $this->daysAgo(2)]);
        $this->makePrice($this->op, ['status' => 'rejected', 'price' => 33333, 'observed_on' => $this->daysAgo(3)]);

        $this->get('/publik')
            ->assertOk()
            ->assertSee('Rp 15.500')
            ->assertDontSee('11.111')
            ->assertDontSee('22.222')
            ->assertDontSee('33.333');
    }

    public function test_data_internal_tidak_bocor_ke_halaman_publik(): void
    {
        DB::table('users')->where('id', $this->op->id)->update(['name' => 'Petugas Rahasia Zed']);
        DB::table('users')->where('id', $this->ver->id)->update(['name' => 'Verifikator Rahasia Yan']);

        $this->published([
            'price'            => 15500,
            'source_reference' => 'Pedagang Rahasia Budi',
            'rejection_reason' => 'Alasan Internal Rahasia',
            'is_outlier'       => true,
        ]);

        $this->get('/publik')
            ->assertOk()
            ->assertSee('Rp 15.500')
            ->assertDontSee('Petugas Rahasia Zed')
            ->assertDontSee('Verifikator Rahasia Yan')
            ->assertDontSee('Pedagang Rahasia Budi')
            ->assertDontSee('Alasan Internal Rahasia');
    }

    public function test_data_yang_ditarik_dari_publikasi_tidak_tampil(): void
    {
        $price = $this->published(['price' => 15500]);

        DB::table('publications')->insert([
            'publishable_type' => $price->getMorphClass(),
            'publishable_id'   => $price->id,
            'version'          => 1,
            'published_by'     => $this->ver->id,
            'published_at'     => now(),
            'unpublished_by'   => $this->boss->id,
            'unpublished_at'   => now(),
            'unpublish_reason' => 'Salah input',
        ]);

        $this->get('/publik')
            ->assertOk()
            ->assertDontSee('15.500')
            ->assertSee('Belum ada data harga yang dipublikasikan.');
    }

    public function test_publikasi_aktif_tidak_menyembunyikan_data(): void
    {
        $price = $this->published(['price' => 15500]);

        DB::table('publications')->insert([
            'publishable_type' => $price->getMorphClass(),
            'publishable_id'   => $price->id,
            'version'          => 1,
            'published_by'     => $this->ver->id,
            'published_at'     => now(),
        ]);

        $this->get('/publik')->assertOk()->assertSee('Rp 15.500');
    }

    public function test_data_yang_dihapus_lunak_tidak_tampil(): void
    {
        $this->published(['price' => 15500])->delete();

        $this->get('/publik')->assertOk()->assertDontSee('15.500');
    }

    public function test_data_yang_terlalu_lama_tidak_tampil(): void
    {
        $this->published(['price' => 15500, 'observed_on' => $this->daysAgo(100)]);

        $this->get('/publik')
            ->assertOk()
            ->assertDontSee('15.500')
            ->assertSee('Belum ada data harga yang dipublikasikan.');
    }

    public function test_portal_menampilkan_semua_wilayah_meski_pengguna_login_dibatasi(): void
    {
        $this->published([
            'market_id' => $this->marketB, 'district_id' => $this->districtB, 'price' => 17777,
        ]);

        // Pengguna dengan cakupan Kecamatan A tidak boleh melihat harga Kecamatan B di dashboard,
        // tetapi informasi publik sama untuk semua orang.
        $scoped = $this->makeUser('operator-kecamatan', [['district', $this->districtA, 'harga']]);

        $this->get('/publik')->assertOk()->assertSee('Pasar B')->assertSee('Rp 17.777');
        $this->actingAs($scoped)->get('/publik')->assertOk()->assertSee('Pasar B')->assertSee('Rp 17.777');
    }

    public function test_parameter_tidak_valid_tidak_menimbulkan_galat(): void
    {
        $this->published(['price' => 15500]);

        $this->get('/publik?komoditas=abc&rentang=999')->assertOk()->assertSee('Rp 15.500');
        $this->get('/publik?komoditas[]=1&rentang[]=7')->assertOk();
        $this->get('/publik?komoditas=999999')->assertOk()->assertSee('Beras');
        $this->get('/publik?rentang=7')->assertOk();
    }

    public function test_komoditas_tanpa_data_terbit_tidak_muncul(): void
    {
        $this->published(['price' => 15500]);

        $jagungId = DB::table('commodities')->insertGetId([
            'code' => 'JGG', 'name' => 'Jagung', 'category' => 'Serealia',
            'default_unit_id' => $this->unitId, 'is_strategic' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makePrice($this->op, ['commodity_id' => $jagungId, 'status' => 'submitted', 'price' => 8888]);

        $this->get('/publik')->assertOk()->assertDontSee('Jagung')->assertDontSee('8.888');
        $this->get('/publik?komoditas=' . $jagungId)->assertOk()->assertDontSee('Jagung')->assertDontSee('8.888');
    }

    public function test_grafik_dan_tabel_tersedia_bila_ada_data(): void
    {
        $this->published(['price' => 15500]);

        $this->get('/publik')
            ->assertOk()
            ->assertSee('data-public-chart', false)
            ->assertSee('Lihat dalam bentuk tabel');
    }

    public function test_permintaan_berlebihan_dibatasi(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get('/publik')->assertOk();
        }

        $this->get('/publik')->assertStatus(429);
    }
}
