<?php

namespace App\Livewire\Prices;

use App\Models\Commodity;
use App\Models\Market;
use App\Models\PriceObservation;
use App\Services\DataScope;
use App\Services\PriceWorkflow;
use App\Support\Audit;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Input Harga - SIKAP KAMPAR')]
class Form extends Component
{
    use AuthorizesRequests;

    public ?int $priceId = null;

    public string $market_id = '';
    public string $commodity_id = '';
    public string $observed_on = '';
    public string $price = '';
    public string $source_reference = '';

    public ?string $rejectionReason = null;

    public function mount(?int $priceId = null): void
    {
        if ($priceId === null) {
            $this->authorize('create', PriceObservation::class);
            $this->observed_on = today()->toDateString();

            return;
        }

        // PriceScope membuat data di luar cakupan/draf orang lain menghasilkan 404.
        $record = PriceObservation::findOrFail($priceId);
        $this->authorize('update', $record);

        $this->priceId = $record->id;
        $this->market_id = (string) $record->market_id;
        $this->commodity_id = (string) $record->commodity_id;
        $this->observed_on = $record->observed_on->toDateString();
        $this->price = (string) (float) $record->price;
        $this->source_reference = (string) $record->source_reference;
        $this->rejectionReason = $record->status === 'rejected' ? $record->rejection_reason : null;
    }

    protected function rules(): array
    {
        return [
            'market_id'        => ['required', 'integer', Rule::exists('markets', 'id')->where('is_active', true)],
            'commodity_id'     => ['required', 'integer', Rule::exists('commodities', 'id')->where('is_active', true)],
            'observed_on'      => ['required', 'date', 'before_or_equal:today'],
            'price'            => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'market_id'        => 'pasar',
            'commodity_id'     => 'komoditas',
            'observed_on'      => 'tanggal pengamatan',
            'price'            => 'harga',
            'source_reference' => 'keterangan sumber',
        ];
    }

    public function save(bool $submit = false)
    {
        $user = auth()->user();
        $this->validate();

        // Pasar harus berada dalam cakupan pengguna (dicek di server, bukan hanya disembunyikan di pilihan).
        $market = app(DataScope::class)->markets($user)->whereKey((int) $this->market_id)->first();
        if (! $market) {
            $this->addError('market_id', 'Pasar ini di luar wilayah kerja Anda.');

            return null;
        }

        $locked = DB::table('reporting_periods')
            ->where('status', 'locked')
            ->whereDate('start_date', '<=', $this->observed_on)
            ->whereDate('end_date', '>=', $this->observed_on)
            ->exists();
        if ($locked) {
            $this->addError('observed_on', 'Periode pelaporan untuk tanggal ini sudah dikunci.');

            return null;
        }

        $duplicate = PriceObservation::withoutGlobalScopes()
            ->where('market_id', $market->id)
            ->where('commodity_id', (int) $this->commodity_id)
            ->whereDate('observed_on', $this->observed_on)
            ->where('source_type', 'field')
            ->when($this->priceId, fn ($q) => $q->where('id', '!=', $this->priceId))
            ->exists();
        if ($duplicate) {
            $this->addError('commodity_id', 'Harga untuk pasar, komoditas, dan tanggal ini sudah ada.');

            return null;
        }

        $commodity = Commodity::findOrFail((int) $this->commodity_id);

        if ($this->priceId) {
            $record = PriceObservation::findOrFail($this->priceId);
            $this->authorize('update', $record);
        } else {
            $this->authorize('create', PriceObservation::class);
            $record = new PriceObservation();
            $record->uuid = (string) Str::uuid();
            $record->created_by = $user->id;
            $record->source_type = 'field';
            $record->status = 'draft';
        }

        $organizationId = DB::table('user_organization')->where('user_id', $user->id)->value('organization_id');

        $record->market_id = $market->id;
        $record->district_id = $market->district_id;
        $record->commodity_id = $commodity->id;
        $record->unit_id = $commodity->default_unit_id;
        $record->observed_on = $this->observed_on;
        $record->price = $this->price;
        $record->source_reference = trim($this->source_reference) ?: null;
        $record->organization_id = $record->organization_id ?? $organizationId;
        $record->updated_by = $user->id;

        try {
            DB::transaction(function () use ($record) {
                $isNew = ! $record->exists;
                $record->save();
                Audit::log($isNew ? 'created' : 'updated', 'price_observation', $record->id, null, $record->only(['market_id', 'commodity_id', 'observed_on', 'price']));
            });
        } catch (UniqueConstraintViolationException) {
            $this->addError('commodity_id', 'Harga untuk pasar, komoditas, dan tanggal ini sudah ada.');

            return null;
        }

        if ($submit) {
            try {
                app(PriceWorkflow::class)->submit($record, $user);
            } catch (DomainException $e) {
                $this->addError('price', $e->getMessage());

                return null;
            }
        }

        session()->flash('status', $submit ? 'Harga disimpan dan diajukan untuk diverifikasi.' : 'Draf harga disimpan.');

        return $this->redirectRoute('prices.index');
    }

    public function render()
    {
        $unitCode = null;
        if ($this->commodity_id !== '') {
            $unitCode = Commodity::with('defaultUnit')->find((int) $this->commodity_id)?->defaultUnit?->code;
        }

        return view('livewire.prices.form', [
            'markets'     => app(DataScope::class)->markets(auth()->user())->orderBy('name')->get(['id', 'name']),
            'commodities' => Commodity::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'unitCode'    => $unitCode,
        ]);
    }
}
