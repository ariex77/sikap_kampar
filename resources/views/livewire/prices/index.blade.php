<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">Harga Pangan</h1>
        @can('create', \App\Models\PriceObservation::class)
            <a href="{{ route('prices.create') }}"
               class="rounded bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">+ Input harga</a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif
    @error('action')
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ $message }}</div>
    @enderror

    {{-- Panel verifikasi / penolakan --}}
    @if ($panelPrice)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="font-medium">
                {{ ['verify' => 'Verifikasi', 'reject' => 'Tolak', 'unpublish' => 'Tarik dari publikasi'][$panelType] ?? '' }}:
                {{ $panelPrice->commodity->name }} di {{ $panelPrice->market->name }},
                {{ $panelPrice->observed_on->format('d/m/Y') }},
                Rp {{ number_format((float) $panelPrice->price, 0, ',', '.') }}
            </p>
            @if ($panelPrice->previous_price !== null)
                <p class="mt-1 text-sm text-gray-600">
                    Harga terbit sebelumnya Rp {{ number_format((float) $panelPrice->previous_price, 0, ',', '.') }}
                    ({{ $panelPrice->changePercent() > 0 ? '+' : '' }}{{ $panelPrice->changePercent() }}%).
                    @if ($panelPrice->is_outlier)
                        <strong class="text-red-700">Di luar ambang wajar, catatan verifikasi wajib diisi.</strong>
                    @endif
                </p>
            @endif

            <label for="note" class="mt-3 block text-sm font-medium">
                {{ ['verify' => 'Catatan verifikasi', 'reject' => 'Alasan penolakan (wajib)', 'unpublish' => 'Alasan penarikan (wajib)'][$panelType] ?? '' }}
            </label>
            <textarea id="note" wire:model="note" rows="2"
                      class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm"></textarea>
            @error('note') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="confirmPanel"
                        class="rounded px-4 py-2 text-sm font-medium text-white {{ $panelType === 'verify' ? 'bg-emerald-700 hover:bg-emerald-800' : 'bg-red-600 hover:bg-red-700' }}">
                    {{ ['verify' => 'Verifikasi dan terbitkan', 'reject' => 'Tolak dan kembalikan', 'unpublish' => 'Tarik dari publikasi'][$panelType] ?? '' }}
                </button>
                <button type="button" wire:click="cancelPanel"
                        class="rounded border border-gray-300 bg-white px-4 py-2 text-sm">Batal</button>
            </div>
        </div>
    @endif

    {{-- Filter --}}
    <div class="mb-4 grid gap-3 rounded-lg bg-white p-4 shadow sm:grid-cols-4">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Status</label>
            <select wire:model.live="status" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                <option value="">Semua</option>
                @foreach (\App\Models\PriceObservation::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Komoditas</label>
            <select wire:model.live="commodity" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                <option value="">Semua</option>
                @foreach ($commodities as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
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

    {{-- Tabel --}}
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                <tr>
                    <th class="px-3 py-2">Tanggal</th>
                    <th class="px-3 py-2">Pasar</th>
                    <th class="px-3 py-2">Komoditas</th>
                    <th class="px-3 py-2 text-right">Harga</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Input oleh</th>
                    <th class="px-3 py-2">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    @php
                        $badge = match ($row->status) {
                            'published' => 'bg-emerald-100 text-emerald-800',
                            'submitted' => 'bg-blue-100 text-blue-800',
                            'rejected'  => 'bg-red-100 text-red-800',
                            default     => 'bg-gray-100 text-gray-700',
                        };
                    @endphp
                    <tr wire:key="price-{{ $row->id }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row->observed_on->format('d/m/Y') }}</td>
                        <td class="px-3 py-2">{{ $row->market->name }}</td>
                        <td class="px-3 py-2">{{ $row->commodity->name }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            Rp {{ number_format((float) $row->price, 0, ',', '.') }}/{{ $row->unit->code }}
                            @if ($row->is_outlier)
                                <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-700"
                                      title="Perubahan melebihi ambang wajar">
                                    {{ $row->changePercent() > 0 ? '+' : '' }}{{ $row->changePercent() }}%
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs {{ $badge }}">{{ $row->statusLabel() }}</span>
                            @if ($row->retracted_at)
                                <span class="ml-1 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800">Ditarik dari publikasi</span>
                            @endif
                            @if ($row->status === 'rejected' && $row->rejection_reason)
                                <p class="mt-1 max-w-xs text-xs text-red-700">{{ $row->rejection_reason }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $row->creator->name }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            @can('update', $row)
                                <a href="{{ route('prices.edit', $row->id) }}" class="text-emerald-700 hover:underline">Ubah</a>
                            @endcan
                            @can('submit', $row)
                                <button type="button" wire:click="submitPrice({{ $row->id }})"
                                        class="ml-2 text-blue-700 hover:underline">Ajukan</button>
                            @endcan
                            @can('verify', $row)
                                <button type="button" wire:click="openPanel({{ $row->id }}, 'verify')"
                                        class="ml-2 text-emerald-700 hover:underline">Verifikasi</button>
                            @endcan
                            @can('reject', $row)
                                <button type="button" wire:click="openPanel({{ $row->id }}, 'reject')"
                                        class="ml-2 text-red-600 hover:underline">Tolak</button>
                            @endcan
                            @can('unpublish', $row)
                                <button type="button" wire:click="openPanel({{ $row->id }}, 'unpublish')"
                                        class="ml-2 text-amber-700 hover:underline">Tarik</button>
                            @endcan
                            @can('delete', $row)
                                <button type="button" wire:click="deletePrice({{ $row->id }})"
                                        wire:confirm="Hapus draf ini?"
                                        class="ml-2 text-gray-500 hover:underline">Hapus</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-8 text-center text-gray-500">Belum ada data harga pada cakupan Anda.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
</div>
