<?php

namespace App\Livewire\Stock;

use App\Models\FoodStock;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Services\DataScope;
use App\Services\StockLedger;
use App\Services\StockWorkflow;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Stok dan Cadangan Pangan - SIKAP KAMPAR')]
class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $location = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    // Panel konfirmasi: verify | approve | reject | reverse
    public ?int $panelId = null;
    public string $panelType = '';
    public string $note = '';

    private const PANEL_TYPES = ['verify', 'approve', 'reject', 'reverse'];

    public function updated(string $name): void
    {
        if (in_array($name, ['status', 'type', 'location', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function submitMovement(int $id): void
    {
        $movement = StockMovement::findOrFail($id);
        $this->authorize('submit', $movement);

        try {
            app(StockWorkflow::class)->submit($movement, auth()->user());
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        session()->flash('status', 'Mutasi diajukan untuk diverifikasi.');
    }

    public function deleteMovement(int $id): void
    {
        $movement = StockMovement::findOrFail($id);
        $this->authorize('delete', $movement);

        try {
            app(StockWorkflow::class)->deleteDraft($movement, auth()->user());
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        session()->flash('status', 'Draf dihapus.');
    }

    public function openPanel(int $id, string $type): void
    {
        if (! in_array($type, self::PANEL_TYPES, true)) {
            return;
        }

        $movement = StockMovement::findOrFail($id);
        $this->authorize($type, $movement);

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
        if ($this->panelId === null || ! in_array($this->panelType, self::PANEL_TYPES, true)) {
            return;
        }

        $movement = StockMovement::findOrFail($this->panelId);
        $this->authorize($this->panelType, $movement);

        $workflow = app(StockWorkflow::class);
        $user = auth()->user();
        $note = trim($this->note);

        try {
            switch ($this->panelType) {
                case 'verify':
                    $workflow->verify($movement, $user, $note ?: null);
                    session()->flash('status', 'Mutasi diverifikasi dan diteruskan untuk persetujuan.');
                    break;

                case 'approve':
                    $workflow->approve($movement, $user, $note ?: null);
                    session()->flash('status', 'Mutasi disetujui dan saldo stok diperbarui.');
                    break;

                case 'reject':
                    $this->validate(['note' => ['required', 'string', 'min:5', 'max:500']], [], ['note' => 'alasan penolakan']);
                    $workflow->reject($movement, $user, $note);
                    session()->flash('status', 'Mutasi dikembalikan ke pembuat dengan alasan penolakan.');
                    break;

                case 'reverse':
                    $this->validate(['note' => ['required', 'string', 'min:5', 'max:500']], [], ['note' => 'alasan koreksi']);
                    $workflow->createReversal($movement, $user, $note);
                    session()->flash('status', 'Draf koreksi dibuat. Ajukan agar diverifikasi dan disetujui.');
                    break;
            }
        } catch (DomainException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $this->cancelPanel();
    }

    public function render()
    {
        $this->authorize('viewAny', StockMovement::class);

        $rows = StockMovement::query()
            ->with(['location', 'commodity', 'unit', 'creator'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->type !== '', fn ($q) => $q->where('movement_type', $this->type))
            ->when($this->location !== '', fn ($q) => $q->where('storage_location_id', (int) $this->location))
            ->when($this->from !== '', fn ($q) => $q->whereDate('movement_date', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('movement_date', '<=', $this->to))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(15);

        $balances = FoodStock::query()
            ->with(['location', 'commodity', 'unit'])
            ->get()
            ->sortBy(fn ($s) => $s->location->name . '|' . $s->commodity->name)
            ->values();

        $panelMovement = $this->panelId
            ? StockMovement::with(['location', 'commodity', 'unit'])->find($this->panelId)
            : null;

        $preview = null;
        if ($panelMovement && $this->panelType === 'approve') {
            $current = StockLedger::toMilli(
                FoodStock::withoutGlobalScopes()
                    ->where('storage_location_id', $panelMovement->storage_location_id)
                    ->where('commodity_id', $panelMovement->commodity_id)
                    ->value('balance')
            );
            $effect = app(StockLedger::class)->effectMilli($panelMovement);
            $preview = [
                'current' => $current / 1000,
                'effect'  => $effect / 1000,
                'after'   => ($current + $effect) / 1000,
                'enough'  => ($current + $effect) >= 0,
            ];
        }

        return view('livewire.stock.index', [
            'rows'          => $rows,
            'balances'      => $balances,
            'locations'     => app(DataScope::class)->storageLocations(auth()->user())->orderBy('name')->get(['id', 'name']),
            'panelMovement' => $panelMovement,
            'preview'       => $preview,
        ]);
    }
}
