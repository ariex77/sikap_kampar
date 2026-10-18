<?php

namespace App\Livewire\Stock;

use App\Models\Commodity;
use App\Models\StockMovement;
use App\Services\DataScope;
use App\Services\StockWorkflow;
use App\Support\Audit;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Input Mutasi Stok - SIKAP KAMPAR')]
class Form extends Component
{
    use AuthorizesRequests;

    public ?int $movementId = null;

    public string $storage_location_id = '';
    public string $commodity_id = '';
    public string $movement_type = 'in';
    public string $movement_date = '';
    public string $quantity = '';
    public string $reference_no = '';
    public string $counterparty = '';
    public string $notes = '';

    public ?string $rejectionReason = null;

    public function mount(?int $movementId = null): void
    {
        if ($movementId === null) {
            $this->authorize('create', StockMovement::class);
            $this->movement_date = today()->toDateString();

            return;
        }

        // LocationScope membuat data di luar cakupan / draf orang lain menghasilkan 404.
        $record = StockMovement::findOrFail($movementId);
        $this->authorize('update', $record);

        $this->movementId = $record->id;
        $this->storage_location_id = (string) $record->storage_location_id;
        $this->commodity_id = (string) $record->commodity_id;
        $this->movement_type = $record->movement_type;
        $this->movement_date = $record->movement_date->toDateString();
        $this->quantity = rtrim(rtrim((string) $record->quantity, '0'), '.');
        $this->reference_no = (string) $record->reference_no;
        $this->counterparty = (string) $record->counterparty;
        $this->notes = (string) $record->notes;
        $this->rejectionReason = $record->status === 'rejected' ? $record->rejection_reason : null;
    }

    protected function rules(): array
    {
        $needsDocument = fn () => in_array($this->movement_type, ['in', 'out'], true);

        return [
            'storage_location_id' => ['required', 'integer', Rule::exists('storage_locations', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'commodity_id'        => ['required', 'integer', Rule::exists('commodities', 'id')->where('is_active', true)],
            'movement_type'       => ['required', Rule::in(['in', 'out', 'opening'])],
            'movement_date'       => ['required', 'date', 'before_or_equal:today'],
            'quantity'            => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'reference_no'        => [Rule::requiredIf($needsDocument), 'nullable', 'string', 'max:100'],
            'counterparty'        => [Rule::requiredIf($needsDocument), 'nullable', 'string', 'max:150'],
            'notes'               => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'storage_location_id' => 'lokasi penyimpanan',
            'commodity_id'        => 'komoditas',
            'movement_type'       => 'jenis mutasi',
            'movement_date'       => 'tanggal',
            'quantity'            => 'jumlah',
            'reference_no'        => 'nomor dokumen',
            'counterparty'        => $this->movement_type === 'out' ? 'tujuan penyaluran' : 'asal barang',
            'notes'               => 'catatan',
        ];
    }

    protected function messages(): array
    {
        return [
            'quantity.regex' => 'Jumlah hanya boleh angka positif dengan maksimal 3 angka di belakang titik desimal.',
        ];
    }

    public function save(bool $submit = false)
    {
        $user = auth()->user();

        // Mengajukan membutuhkan izin tersendiri; periksa sebelum menyimpan apa pun.
        abort_unless(! $submit || $user->can('stock.submit'), 403);

        $this->validate();

        $location = app(DataScope::class)->storageLocations($user)->whereKey((int) $this->storage_location_id)->first();
        if (! $location) {
            $this->addError('storage_location_id', 'Lokasi ini di luar wilayah kerja Anda.');

            return null;
        }

        $locked = DB::table('reporting_periods')
            ->where('status', 'locked')
            ->whereDate('start_date', '<=', $this->movement_date)
            ->whereDate('end_date', '>=', $this->movement_date)
            ->exists();
        if ($locked) {
            $this->addError('movement_date', 'Periode pelaporan untuk tanggal ini sudah dikunci.');

            return null;
        }

        $commodity = Commodity::findOrFail((int) $this->commodity_id);

        if ($this->movementId) {
            $record = StockMovement::findOrFail($this->movementId);
            $this->authorize('update', $record);
        } else {
            $this->authorize('create', StockMovement::class);
            $record = new StockMovement();
            $record->uuid = (string) Str::uuid();
            $record->created_by = $user->id;
            $record->status = 'draft';
        }

        $record->storage_location_id = $location->id;
        $record->commodity_id = $commodity->id;
        $record->unit_id = $commodity->default_unit_id;
        $record->movement_type = $this->movement_type;
        $record->movement_date = $this->movement_date;
        $record->quantity = $this->quantity;
        $record->reference_no = trim($this->reference_no) ?: null;
        $record->counterparty = trim($this->counterparty) ?: null;
        $record->notes = trim($this->notes) ?: null;

        DB::transaction(function () use ($record) {
            $isNew = ! $record->exists;
            $record->save();
            Audit::log($isNew ? 'created' : 'updated', 'stock_movement', $record->id, null,
                $record->only(['storage_location_id', 'commodity_id', 'movement_type', 'quantity', 'movement_date']));
        });

        // Simpan ID agar percobaan berikutnya (mis. setelah pengajuan gagal) memperbarui draf yang sama.
        $this->movementId = $record->id;

        if ($submit) {
            try {
                app(StockWorkflow::class)->submit($record, $user);
            } catch (DomainException $e) {
                $this->addError('quantity', $e->getMessage());

                return null;
            }
        }

        session()->flash('status', $submit ? 'Mutasi disimpan dan diajukan untuk diverifikasi.' : 'Draf mutasi disimpan.');

        return $this->redirectRoute('stock.index');
    }

    public function render()
    {
        $unitCode = null;
        if ($this->commodity_id !== '') {
            $unitCode = Commodity::with('defaultUnit')->find((int) $this->commodity_id)?->defaultUnit?->code;
        }

        return view('livewire.stock.form', [
            'locations'   => app(DataScope::class)->storageLocations(auth()->user())->orderBy('name')->get(['id', 'name']),
            'commodities' => Commodity::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'unitCode'    => $unitCode,
        ]);
    }
}
