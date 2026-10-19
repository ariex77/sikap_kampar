<?php

namespace App\Livewire\Prices;

use App\Services\DataScope;
use App\Services\PriceImporter;
use DomainException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Impor Harga - SIKAP KAMPAR')]
class Import extends Component
{
    use WithFileUploads;

    public $file = null;

    public ?int $logId = null;

    public bool $submitAfter = false;

    /** @var array<string, int>|null */
    public ?array $outcome = null;

    public function mount(): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user->can('import.run') && $user->can('price.create'), 403);
    }

    public function updatedFile(): void
    {
        $this->guard();
        $this->resetErrorBag();
        $this->logId = null;
        $this->outcome = null;

        $this->validate(
            ['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']],
            [],
            ['file' => 'berkas']
        );

        $content = (string) file_get_contents($this->file->getRealPath());
        $original = $this->file->getClientOriginalName();

        try {
            $path = $this->file->store('imports');
            $this->logId = app(PriceImporter::class)->preview($content, $original, (string) $path, auth()->user());
        } catch (DomainException $e) {
            $this->addError('file', $e->getMessage());
        }
    }

    public function commit(): void
    {
        $this->guard();

        if ($this->logId === null) {
            return;
        }

        try {
            $this->outcome = app(PriceImporter::class)->commit($this->logId, auth()->user(), $this->submitAfter);
        } catch (DomainException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->file = null;
    }

    public function startOver(): void
    {
        $this->reset(['file', 'logId', 'outcome', 'submitAfter']);
        $this->resetErrorBag();
    }

    public function downloadTemplate()
    {
        $this->guard();

        $user = auth()->user();
        $market = app(DataScope::class)->markets($user)->orderBy('name')->value('name') ?? 'Pasar Contoh';
        $commodity = DB::table('commodities')->where('is_active', true)->orderBy('name')->value('name') ?? 'Beras';
        $date = today()->format('d/m/Y');

        $csv = "\xEF\xBB\xBF" . "tanggal;pasar;komoditas;harga;keterangan\r\n"
            . "{$date};{$market};{$commodity};15500;Survei pedagang\r\n"
            . "{$date};{$market};{$commodity};15000;\r\n";

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'templat-impor-harga.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $this->guard();

        $log = null;
        $errorRows = collect();
        $validRows = collect();

        if ($this->logId !== null) {
            $log = DB::table('import_logs')
                ->where('id', $this->logId)
                ->where('imported_by', auth()->id())
                ->first();

            if ($log) {
                $decode = fn ($r) => [
                    'row'    => $r->row_number,
                    'raw'    => json_decode($r->payload, true)['raw'] ?? [],
                    'errors' => json_decode($r->errors ?? '[]', true) ?: [],
                    'result' => $r->result,
                ];

                $errorRows = DB::table('import_rows')->where('import_log_id', $log->id)
                    ->whereIn('result', ['error', 'duplicate'])->orderBy('row_number')->limit(100)->get()->map($decode);
                $validRows = DB::table('import_rows')->where('import_log_id', $log->id)
                    ->whereIn('result', ['valid', 'imported'])->orderBy('row_number')->limit(20)->get()->map($decode);
            }
        }

        return view('livewire.prices.import', [
            'log'       => $log,
            'errorRows' => $errorRows,
            'validRows' => $validRows,
        ]);
    }
}
