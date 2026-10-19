<?php

namespace App\Livewire\Prices;

use App\Models\Commodity;
use App\Models\PriceObservation;
use App\Services\PriceWorkflow;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Harga Pangan - SIKAP KAMPAR')]
class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $commodity = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    // Panel konfirmasi verifikasi / penolakan
    public ?int $panelId = null;
    public string $panelType = '';
    public string $note = '';

    public function updated(string $name): void
    {
        if (in_array($name, ['status', 'commodity', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function submitPrice(int $id): void
    {
        $price = PriceObservation::findOrFail($id);
        $this->authorize('submit', $price);

        try {
            app(PriceWorkflow::class)->submit($price, auth()->user());
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        session()->flash('status', 'Harga berhasil diajukan untuk diverifikasi.');
    }

    public function deletePrice(int $id): void
    {
        $price = PriceObservation::findOrFail($id);
        $this->authorize('delete', $price);

        try {
            app(PriceWorkflow::class)->deleteDraft($price, auth()->user());
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        session()->flash('status', 'Draf dihapus.');
    }

    public function openPanel(int $id, string $type): void
    {
        if (! in_array($type, ['verify', 'reject', 'unpublish'], true)) {
            return;
        }

        $price = PriceObservation::findOrFail($id);
        $this->authorize($type, $price);

        $this->panelId = $id;
        $this->panelType = $type;
        $this->note = '';
        $this->resetErrorBag();
    }

    public function cancelPanel(): void
    {
        $this->reset(['panelId', 'panelType', 'note']);
        $this->resetErrorBag();
    }

    public function confirmPanel(): void
    {
        if ($this->panelId === null) {
            return;
        }

        $price = PriceObservation::findOrFail($this->panelId);
        $workflow = app(PriceWorkflow::class);
        $user = auth()->user();

        try {
            if ($this->panelType === 'verify') {
                $this->authorize('verify', $price);
                $workflow->verify($price, $user, trim($this->note) ?: null);
                session()->flash('status', 'Harga diverifikasi dan diterbitkan.');
            } elseif ($this->panelType === 'unpublish') {
                $this->authorize('unpublish', $price);
                $this->validate(
                    ['note' => ['required', 'string', 'min:5', 'max:500']],
                    [],
                    ['note' => 'alasan penarikan']
                );
                $workflow->unpublish($price, $user, trim($this->note));
                session()->flash('status', 'Harga ditarik dari publikasi. Data tetap tersimpan dan tidak lagi tampil di portal publik.');
            } elseif ($this->panelType === 'reject') {
                $this->authorize('reject', $price);
                $this->validate(
                    ['note' => ['required', 'string', 'min:5', 'max:500']],
                    [],
                    ['note' => 'alasan penolakan']
                );
                $workflow->reject($price, $user, trim($this->note));
                session()->flash('status', 'Harga dikembalikan ke pembuat dengan alasan penolakan.');
            }
        } catch (DomainException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $this->cancelPanel();
    }

    public function render()
    {
        $this->authorize('viewAny', PriceObservation::class);

        $rows = PriceObservation::query()
            ->select('price_observations.*')
            ->addSelect(['retracted_at' => DB::table('publications')
                ->select('unpublished_at')
                ->whereColumn('publications.publishable_id', 'price_observations.id')
                ->where('publications.publishable_type', (new PriceObservation())->getMorphClass())
                ->whereNotNull('unpublished_at')
                ->orderByDesc('id')
                ->limit(1)])
            ->with(['market', 'commodity', 'unit', 'creator'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->commodity !== '', fn ($q) => $q->where('commodity_id', (int) $this->commodity))
            ->when($this->from !== '', fn ($q) => $q->whereDate('observed_on', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('observed_on', '<=', $this->to))
            ->orderByDesc('observed_on')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.prices.index', [
            'rows'        => $rows,
            'commodities' => Commodity::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'panelPrice'  => $this->panelId ? PriceObservation::with(['market', 'commodity'])->find($this->panelId) : null,
        ]);
    }
}
