<?php

namespace Tests\Feature\Prices;

use App\Livewire\Prices\Index;
use App\Models\PriceObservation;
use App\Services\PriceAnalytics;
use App\Services\PriceWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesStockFixtures;
use Tests\TestCase;

class PriceUnpublishTest extends TestCase
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

    /** Harga yang terbit lewat alur sebenarnya (ajukan lalu verifikasi), sehingga ada baris publications. */
    private function publish(array $attrs = []): PriceObservation
    {
        $price = $this->makePrice($this->op, $attrs);
        $workflow = app(PriceWorkflow::class);
        $workflow->submit($price, $this->op);
        $workflow->verify($price, $this->ver, 'Sudah dicek');

        return $price->fresh();
    }

    public function test_kepala_dinas_dapat_menarik_dan_semuanya_tercatat(): void
    {
        $price = $this->publish();

        app(PriceWorkflow::class)->unpublish($price, $this->boss, 'Salah input harga');

        $row = DB::table('publications')->where('publishable_id', $price->id)->first();
        $this->assertNotNull($row->unpublished_at);
        $this->assertSame($this->boss->id, (int) $row->unpublished_by);
        $this->assertSame('Salah input harga', $row->unpublish_reason);

        $this->assertDatabaseHas('approval_histories', [
            'approvable_id' => $price->id, 'from_status' => 'published', 'to_status' => 'unpublished', 'acted_by' => $this->boss->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'unpublished', 'entity_id' => (string) $price->id]);
        $this->assertSame('published', $price->fresh()->status); // data tidak dihapus
    }

    public function test_data_yang_ditarik_hilang_dari_portal_dan_dashboard(): void
    {
        $price = $this->publish(['price' => 15500]);
        $this->get('/publik')->assertSee('Rp 15.500');

        app(PriceWorkflow::class)->unpublish($price, $this->boss, 'Salah input harga');

        $this->get('/publik')->assertDontSee('15.500')->assertSee('Belum ada data harga yang dipublikasikan.');

        $this->actingAs($this->boss);
        $this->assertSame(0, PriceAnalytics::forInternal()->totals(today()->subDays(29), today())['observations']);
    }

    public function test_penarikan_wajib_alasan_dan_tidak_bisa_dua_kali(): void
    {
        $price = $this->publish();
        $workflow = app(PriceWorkflow::class);

        $this->assertDomainError(fn () => $workflow->unpublish($price, $this->boss, '   '));

        $workflow->unpublish($price, $this->boss, 'Salah input harga');
        $this->assertDomainError(fn () => $workflow->unpublish($price, $this->boss, 'Sekali lagi'));
    }

    public function test_hanya_data_terbit_yang_dapat_ditarik(): void
    {
        $draft = $this->makePrice($this->op, ['status' => 'draft']);

        $this->assertDomainError(fn () => app(PriceWorkflow::class)->unpublish($draft, $this->boss, 'Alasan apa pun'));
    }

    public function test_izin_penarikan_hanya_untuk_pemegang_publication_manage(): void
    {
        $price = $this->publish();

        $this->assertTrue($this->boss->can('unpublish', $price));
        $this->assertFalse($this->ver->can('unpublish', $price));
        $this->assertFalse($this->op->can('unpublish', $price));
        $this->assertFalse($this->makeUser('auditor', [['kabupaten']])->can('unpublish', $price));

        app(PriceWorkflow::class)->unpublish($price, $this->boss, 'Salah input harga');
        $this->assertFalse($this->boss->can('unpublish', $price)); // sudah ditarik
    }

    public function test_lewat_komponen_alasan_wajib_dan_penarikan_berhasil(): void
    {
        $price = $this->publish();

        Livewire::actingAs($this->boss)->test(Index::class)
            ->call('openPanel', $price->id, 'unpublish')
            ->set('note', 'abc')
            ->call('confirmPanel')
            ->assertHasErrors(['note'])
            ->set('note', 'Harga salah ketik')
            ->call('confirmPanel')
            ->assertHasNoErrors()
            ->assertSee('Ditarik dari publikasi');

        $this->assertTrue(PriceWorkflow::isRetracted($price->id));
    }

    public function test_verifikator_tidak_dapat_menarik_lewat_komponen(): void
    {
        $price = $this->publish();

        Livewire::actingAs($this->ver)->test(Index::class)
            ->call('openPanel', $price->id, 'unpublish')
            ->assertForbidden();
    }

    public function test_harga_yang_ditarik_tidak_menjadi_pembanding_lonjakan(): void
    {
        $old = $this->publish(['price' => 10000, 'observed_on' => today()->subDays(2)->toDateString()]);
        app(PriceWorkflow::class)->unpublish($old, $this->boss, 'Salah input harga');

        $next = $this->makePrice($this->op, ['price' => 50000, 'observed_on' => today()->toDateString()]);
        app(PriceWorkflow::class)->submit($next, $this->op);

        $fresh = $next->fresh();
        $this->assertNull($fresh->previous_price);
        $this->assertFalse((bool) $fresh->is_outlier);
    }
}
