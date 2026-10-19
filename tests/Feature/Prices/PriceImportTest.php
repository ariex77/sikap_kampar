<?php

namespace Tests\Feature\Prices;

use App\Livewire\Prices\Import;
use App\Models\PriceObservation;
use App\Services\PriceImporter;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

class PriceImportTest extends TestCase
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

    private function d(int $daysAgo): string
    {
        return today()->subDays($daysAgo)->format('d/m/Y');
    }

    private function csv(array $lines): string
    {
        return "tanggal;pasar;komoditas;harga;keterangan\n" . implode("\n", $lines) . "\n";
    }

    private function preview(string $csv, $user = null): int
    {
        return app(PriceImporter::class)->preview($csv, 'harga.csv', 'imports/harga.csv', $user ?? $this->op);
    }

    private function rowResult(int $logId, int $row): object
    {
        return DB::table('import_rows')->where('import_log_id', $logId)->where('row_number', $row)->first();
    }

    // ---------- Pratinjau ----------

    public function test_pratinjau_memeriksa_semua_baris_dan_tidak_membuat_harga(): void
    {
        $logId = $this->preview($this->csv([
            "{$this->d(1)};Pasar A;Beras;15.500;Survei",   // 2 valid
            "{$this->d(2)};pasar a;BRS;Rp 15.000;",        // 3 valid (nama tidak peka huruf besar, kode komoditas)
            "{$this->d(3)};Pasar Z;Beras;15000;",          // 4 pasar tidak ada
            "{$this->d(4)};Pasar A;Kopi;15000;",           // 5 komoditas tidak ada
            "{$this->d(5)};Pasar A;Beras;abc;",            // 6 harga salah
            "{$this->d(6)};Pasar A;Beras;0;",              // 7 harga nol
            "besok;Pasar A;Beras;15000;",                  // 8 tanggal salah
            today()->addDay()->format('d/m/Y') . ";Pasar A;Beras;15000;", // 9 masa depan
        ]));

        $log = DB::table('import_logs')->find($logId);
        $this->assertSame('previewed', $log->status);
        $this->assertSame(8, $log->total_rows);
        $this->assertSame(2, $log->valid_rows);
        $this->assertSame(6, $log->error_rows);

        $this->assertSame('valid', $this->rowResult($logId, 2)->result);
        $this->assertSame('valid', $this->rowResult($logId, 3)->result);
        foreach ([4, 5, 6, 7, 8, 9] as $row) {
            $this->assertSame('error', $this->rowResult($logId, $row)->result, "baris $row");
            $this->assertNotEmpty(json_decode($this->rowResult($logId, $row)->errors, true));
        }

        $this->assertSame(0, PriceObservation::withoutGlobalScopes()->count());
    }

    public function test_pasar_di_luar_cakupan_ditolak(): void
    {
        $scoped = $this->makeUser('operator-dkp', [['district', $this->districtA, 'harga']]);

        $logId = $this->preview($this->csv([
            "{$this->d(1)};Pasar A;Beras;15000;",
            "{$this->d(2)};Pasar B;Beras;15000;",
        ]), $scoped);

        $this->assertSame('valid', $this->rowResult($logId, 2)->result);
        $this->assertSame('error', $this->rowResult($logId, 3)->result);
        $this->assertStringContainsString('wilayah kerja', $this->rowResult($logId, 3)->errors);
    }

    public function test_ganda_di_berkas_dan_di_sistem_dan_periode_terkunci(): void
    {
        $this->makePrice($this->op, ['observed_on' => today()->subDays(5)->toDateString()]); // sudah ada
        DB::table('reporting_periods')->insert([
            'period_type' => 'daily', 'label' => 'terkunci',
            'start_date' => today()->subDays(9)->toDateString(), 'end_date' => today()->subDays(9)->toDateString(),
            'status' => 'locked', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $logId = $this->preview($this->csv([
            "{$this->d(1)};Pasar A;Beras;15000;",
            "{$this->d(1)};Pasar A;Beras;16000;",   // ganda di berkas
            "{$this->d(5)};Pasar A;Beras;15000;",   // ganda di sistem
            "{$this->d(9)};Pasar A;Beras;15000;",   // periode terkunci
        ]));

        $this->assertSame('valid', $this->rowResult($logId, 2)->result);
        $this->assertSame('duplicate', $this->rowResult($logId, 3)->result);
        $this->assertSame('duplicate', $this->rowResult($logId, 4)->result);
        $this->assertSame('error', $this->rowResult($logId, 5)->result);
        $this->assertStringContainsString('dikunci', $this->rowResult($logId, 5)->errors);
    }

    public function test_berkas_tanpa_kolom_wajib_ditolak(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Kolom wajib');

        $this->preview("tanggal;pasar;harga\n1;2;3\n");
    }

    // ---------- Impor ----------

    public function test_impor_membuat_draf_milik_pengimpor_bukan_data_terbit(): void
    {
        $logId = $this->preview($this->csv([
            "{$this->d(1)};Pasar A;Beras;15.500;Survei pedagang",
            "{$this->d(2)};Pasar A;Beras;15000;",
            "{$this->d(3)};Pasar Z;Beras;15000;",
        ]));

        $result = app(PriceImporter::class)->commit($logId, $this->op);

        $this->assertSame(['imported' => 2, 'skipped' => 1, 'submitted' => 0, 'submit_failed' => 0], $result);

        $rows = PriceObservation::withoutGlobalScopes()->orderBy('observed_on')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $p) {
            $this->assertSame('draft', $p->status);
            $this->assertSame('import', $p->source_type);
            $this->assertSame($this->op->id, $p->created_by);
            $this->assertSame($this->districtA, $p->district_id);
            $this->assertSame($this->unitId, $p->unit_id);
        }
        $this->assertSame('15000.00', (string) $rows[0]->price);
        $this->assertSame('Impor harga.csv', $rows[0]->source_reference);
        $this->assertSame('Survei pedagang', $rows[1]->source_reference);

        $log = DB::table('import_logs')->find($logId);
        $this->assertSame('committed', $log->status);
        $this->assertNotNull($log->committed_at);
        $this->assertSame(2, $this->countRows($logId, 'imported'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'imported', 'entity_id' => (string) $logId]);
    }

    private function countRows(int $logId, string $result): int
    {
        return DB::table('import_rows')->where('import_log_id', $logId)->where('result', $result)->count();
    }

    public function test_impor_tidak_dapat_dijalankan_dua_kali(): void
    {
        $logId = $this->preview($this->csv(["{$this->d(1)};Pasar A;Beras;15000;"]));
        $importer = app(PriceImporter::class);

        $importer->commit($logId, $this->op);

        $this->assertDomainError(fn () => $importer->commit($logId, $this->op));
        $this->assertSame(1, PriceObservation::withoutGlobalScopes()->count());
    }

    public function test_pengguna_lain_tidak_dapat_mengimpor_pratinjau_orang_lain(): void
    {
        $logId = $this->preview($this->csv(["{$this->d(1)};Pasar A;Beras;15000;"]));

        $this->assertDomainError(fn () => app(PriceImporter::class)->commit($logId, $this->ver));
        $this->assertSame(0, PriceObservation::withoutGlobalScopes()->count());
    }

    public function test_pemeriksaan_diulang_saat_impor(): void
    {
        $logId = $this->preview($this->csv(["{$this->d(1)};Pasar A;Beras;15000;"]));

        // Setelah pratinjau, orang lain menginput harga yang sama.
        $this->makePrice($this->ver, ['observed_on' => today()->subDay()->toDateString(), 'source_type' => 'field']);

        $result = app(PriceImporter::class)->commit($logId, $this->op);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, PriceObservation::withoutGlobalScopes()->count());
        $this->assertSame('error', $this->rowResult($logId, 2)->result);
    }

    public function test_opsi_langsung_ajukan(): void
    {
        $logId = $this->preview($this->csv([
            "{$this->d(1)};Pasar A;Beras;15000;",
            "{$this->d(2)};Pasar A;Beras;15200;",
        ]));

        $result = app(PriceImporter::class)->commit($logId, $this->op, true);

        $this->assertSame(2, $result['submitted']);
        $this->assertSame(2, PriceObservation::withoutGlobalScopes()->where('status', 'submitted')->count());
    }

    public function test_data_impor_tidak_dihitung_sebelum_terbit(): void
    {
        $logId = $this->preview($this->csv(["{$this->d(0)};Pasar A;Beras;15500;"]));
        app(PriceImporter::class)->commit($logId, $this->op, true);

        $this->get('/publik')->assertOk()->assertDontSee('15.500');

        $price = PriceObservation::withoutGlobalScopes()->first();
        app(\App\Services\PriceWorkflow::class)->verify($price, $this->ver);

        $this->get('/publik')->assertOk()->assertSee('Rp 15.500');
    }

    // ---------- Halaman ----------

    public function test_akses_halaman_impor_menurut_izin(): void
    {
        $this->actingAs($this->op)->get('/harga/impor')->assertOk();
        $this->actingAs($this->ver)->get('/harga/impor')->assertForbidden();
        $this->actingAs($this->makeUser('operator-kecamatan', [['district', $this->districtA, 'harga']]))
            ->get('/harga/impor')->assertForbidden();
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/harga/impor')->assertRedirect('/login');
    }

    public function test_unggah_pratinjau_dan_impor_lewat_komponen(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('harga.csv', $this->csv([
            "{$this->d(1)};Pasar A;Beras;15000;",
            "{$this->d(2)};Pasar Z;Beras;15000;",
        ]));

        Livewire::actingAs($this->op)->test(Import::class)
            ->set('file', $file)
            ->assertHasNoErrors()
            ->assertSee('Hasil pemeriksaan')
            ->assertSee('tidak ditemukan atau di luar wilayah kerja')
            ->assertSee('Impor 1 baris')
            ->call('commit')
            ->assertSee('Impor selesai.')
            ->assertSee('1 harga dibuat sebagai draf.');

        $this->assertSame(1, PriceObservation::withoutGlobalScopes()->count());
    }

    public function test_komponen_menolak_berkas_dengan_kolom_salah(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->op)->test(Import::class)
            ->set('file', UploadedFile::fake()->createWithContent('salah.csv', "a;b\n1;2\n"))
            ->assertHasErrors(['file'])
            ->assertSee('Kolom wajib tidak ditemukan');

        $this->assertSame(0, DB::table('import_logs')->count());
    }

    public function test_komponen_menolak_tipe_berkas_selain_csv(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->op)->test(Import::class)
            ->set('file', UploadedFile::fake()->create('harga.pdf', 10, 'application/pdf'))
            ->assertHasErrors(['file']);
    }

    public function test_templat_dapat_diunduh(): void
    {
        Livewire::actingAs($this->op)->test(Import::class)
            ->call('downloadTemplate')
            ->assertFileDownloaded('templat-impor-harga.csv');
    }
}
