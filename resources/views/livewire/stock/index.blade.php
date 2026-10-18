<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">Stok dan Cadangan Pangan</h1>
        @can('create', \App\Models\StockMovement::class)
            <a href="{{ route('stock.create') }}"
               class="rounded bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">+ Input mutasi</a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @error('action')
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ $message }}</div>
    @enderror

    {{-- Panel konfirmasi --}}
    @if ($panelMovement)
        @php
            $panelTitle = ['verify' => 'Verifikasi', 'approve' => 'Setujui', 'reject' => 'Tolak', 'reverse' => 'Koreksi'][$panelType] ?? '';
        @endphp
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="font-medium">
                {{ $panelTitle }}: {{ $panelMovement->typeLabel() }}
                {{ $panelMovement->commodity->name }} {{ $panelMovement->formattedQuantity() }} {{ $panelMovement->unit->code }}
                di {{ $panelMovement->location->name }}, {{ $panelMovement->movement_date->format('d/m/Y') }}
            </p>

            @if ($preview)
                <p class="mt-1 text-sm text-gray-700">
                    Saldo saat ini {{ rtrim(rtrim(number_format($preview['current'], 3, ',', '.'), '0'), ',') }}
                    → setelah disetujui {{ rtrim(rtrim(number_format($preview['after'], 3, ',', '.'), '0'), ',') }}
                    {{ $panelMovement->unit->code }}.
                    @unless ($preview['enough'])
                        <strong class="text-red-700">Saldo tidak mencukupi, persetujuan akan ditolak sistem.</strong>
                    @endunless
                </p>
            @endif

            @if ($panelType === 'reverse')
                <p class="mt-1 text-sm text-gray-600">
                    Koreksi membuat mutasi pembalik berjumlah sama sebagai draf. Mutasi asli tidak diubah.
                </p>
            @endif

            <label for="note" class="mt-3 block text-sm font-medium">
                @if (in_array($panelType, ['reject', 'reverse'], true))
                    {{ $panelType === 'reject' ? 'Alasan penolakan (wajib)' : 'Alasan koreksi (wajib)' }}
                @else
                    Catatan (opsional)
                @endif
            </label>
            <textarea id="note" wire:model="note" rows="2" class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm"></textarea>
            @error('note') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="confirmPanel"
                        class="rounded px-4 py-2 text-sm font-medium text-white {{ $panelType === 'reject' ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-700 hover:bg-emerald-800' }}">
                    {{ ['verify' => 'Verifikasi', 'approve' => 'Setujui dan perbarui saldo', 'reject' => 'Tolak dan kembalikan', 'reverse' => 'Buat draf koreksi'][$panelType] ?? 'Lanjut' }}
                </button>
                <button type="button" wire:click="cancelPanel" class="rounded border border-gray-300 bg-white px-4 py-2 text-sm">Batal</button>
            </div>
        </div>
    @endif

    {{-- Saldo saat ini --}}
    <h2 class="mb-2 text-lg font-semibold">Saldo saat ini</h2>
    <div class="mb-6 overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                <tr>
                    <th class="px-3 py-2">Lokasi</th>
                    <th class="px-3 py-2">Komoditas</th>
                    <th class="px-3 py-2 text-right">Saldo</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($balances as $stock)
                    <tr wire:key="stock-{{ $stock->id }}">
                        <td class="px-3 py-2">{{ $stock->location->name }}</td>
                        <td class="px-3 py-2">{{ $stock->commodity->name }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ rtrim(rtrim(number_format((float) $stock->balance, 3, ',', '.'), '0'), ',') }} {{ $stock->unit->code }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">Belum ada saldo. Saldo muncul setelah mutasi pertama disetujui.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Mutasi --}}
    <h2 class="mb-2 text-lg font-semibold">Mutasi</h2>
    <div class="mb-4 grid gap-3 rounded-lg bg-white p-4 shadow sm:grid-cols-5">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Status</label>
            <select wire:model.live="status" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                <option value="">Semua</option>
                @foreach (\App\Models\StockMovement::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Jenis</label>
            <select wire:model.live="type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                <option value="">Semua</option>
                @foreach (\App\Models\StockMovement::TYPE_LABELS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Lokasi</label>
            <select wire:model.live="location" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                <option value="">Semua</option>
                @foreach ($locations as $l)
                    <option value="{{ $l->id }}">{{ $l->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Dari tanggal</label>
            <input type="date" wire:model.live="from" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Sampai tanggal</label>
            <input type="date" wire:model.live="to" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                <tr>
                    <th class="px-3 py-2">Tanggal</th>
                    <th class="px-3 py-2">Jenis</th>
                    <th class="px-3 py-2">Lokasi</th>
                    <th class="px-3 py-2">Komoditas</th>
                    <th class="px-3 py-2 text-right">Jumlah</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Input oleh</th>
                    <th class="px-3 py-2">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    @php
                        $badge = match ($row->status) {
                            'approved'  => 'bg-emerald-100 text-emerald-800',
                            'verified'  => 'bg-indigo-100 text-indigo-800',
                            'submitted' => 'bg-blue-100 text-blue-800',
                            'rejected'  => 'bg-red-100 text-red-800',
                            default     => 'bg-gray-100 text-gray-700',
                        };
                        $sign = match ($row->movement_type) { 'out' => '-', 'in', 'opening' => '+', default => '' };
                    @endphp
                    <tr wire:key="movement-{{ $row->id }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row->movement_date->format('d/m/Y') }}</td>
                        <td class="px-3 py-2">
                            {{ $row->typeLabel() }}
                            @if ($row->reverses_id)
                                <span class="block text-xs text-gray-500">mengoreksi #{{ $row->reverses_id }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $row->location->name }}</td>
                        <td class="px-3 py-2">{{ $row->commodity->name }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ $sign }}{{ $row->formattedQuantity() }} {{ $row->unit->code }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs {{ $badge }}">{{ $row->statusLabel() }}</span>
                            @if ($row->status === 'rejected' && $row->rejection_reason)
                                <p class="mt-1 max-w-xs text-xs text-red-700">{{ $row->rejection_reason }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $row->creator->name }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            @can('update', $row)
                                <a href="{{ route('stock.edit', $row->id) }}" class="text-emerald-700 hover:underline">Ubah</a>
                            @endcan
                            @can('submit', $row)
                                <button type="button" wire:click="submitMovement({{ $row->id }})" class="ml-2 text-blue-700 hover:underline">Ajukan</button>
                            @endcan
                            @can('verify', $row)
                                <button type="button" wire:click="openPanel({{ $row->id }}, 'verify')" class="ml-2 text-emerald-700 hover:underline">Verifikasi</button>
                            @endcan
                            @can('approve', $row)
                                <button type="button" wire:click="openPanel({{ $row->id }}, 'approve')" class="ml-2 font-medium text-emerald-800 hover:underline">Setujui</button>
                            @endcan
                            @can('reject', $row)
                                <button type="button" wire:click="openPanel({{ $row->id }}, 'reject')" class="ml-2 text-red-600 hover:underline">Tolak</button>
                            @endcan
                            @can('reverse', $row)
                                <button type="button" wire:click="openPanel({{ $row->id }}, 'reverse')" class="ml-2 text-amber-700 hover:underline">Koreksi</button>
                            @endcan
                            @can('delete', $row)
                                <button type="button" wire:click="deleteMovement({{ $row->id }})" wire:confirm="Hapus draf ini?" class="ml-2 text-gray-500 hover:underline">Hapus</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-gray-500">Belum ada mutasi pada cakupan Anda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
</div>
