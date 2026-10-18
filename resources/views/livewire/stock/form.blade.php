<div class="mx-auto max-w-xl">
    <h1 class="mb-4 text-2xl font-semibold">{{ $movementId ? 'Ubah Mutasi Stok' : 'Input Mutasi Stok' }}</h1>

    @if ($rejectionReason)
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <strong>Dikembalikan:</strong> {{ $rejectionReason }}
        </div>
    @endif

    <form class="space-y-4 rounded-lg bg-white p-5 shadow" wire:submit.prevent>
        <div>
            <label for="movement_type" class="mb-1 block text-sm font-medium">Jenis mutasi</label>
            <select id="movement_type" wire:model.live="movement_type" class="w-full rounded border border-gray-300 px-3 py-2">
                <option value="in">Penerimaan (barang masuk)</option>
                <option value="out">Pengeluaran / penyaluran (barang keluar)</option>
                <option value="opening">Stok awal</option>
            </select>
            @error('movement_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="storage_location_id" class="mb-1 block text-sm font-medium">Lokasi penyimpanan</label>
            <select id="storage_location_id" wire:model="storage_location_id" class="w-full rounded border border-gray-300 px-3 py-2">
                <option value="">Pilih lokasi</option>
                @foreach ($locations as $l)
                    <option value="{{ $l->id }}">{{ $l->name }}</option>
                @endforeach
            </select>
            @error('storage_location_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @if ($locations->isEmpty())
                <p class="mt-1 text-sm text-amber-700">Belum ada lokasi penyimpanan pada wilayah kerja Anda. Hubungi admin.</p>
            @endif
        </div>

        <div>
            <label for="commodity_id" class="mb-1 block text-sm font-medium">Komoditas</label>
            <select id="commodity_id" wire:model.live="commodity_id" class="w-full rounded border border-gray-300 px-3 py-2">
                <option value="">Pilih komoditas</option>
                @foreach ($commodities as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
            @error('commodity_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="movement_date" class="mb-1 block text-sm font-medium">Tanggal</label>
                <input id="movement_date" type="date" wire:model="movement_date" max="{{ today()->toDateString() }}"
                       class="w-full rounded border border-gray-300 px-3 py-2">
                @error('movement_date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="quantity" class="mb-1 block text-sm font-medium">Jumlah{{ $unitCode ? ' (' . $unitCode . ')' : '' }}</label>
                <input id="quantity" type="number" step="0.001" min="0" inputmode="decimal" wire:model="quantity"
                       class="w-full rounded border border-gray-300 px-3 py-2" placeholder="contoh: 1500">
                @error('quantity') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        @if ($movement_type !== 'opening')
            <div>
                <label for="counterparty" class="mb-1 block text-sm font-medium">
                    {{ $movement_type === 'out' ? 'Tujuan penyaluran' : 'Asal barang' }}
                </label>
                <input id="counterparty" type="text" wire:model="counterparty" maxlength="150"
                       class="w-full rounded border border-gray-300 px-3 py-2"
                       placeholder="{{ $movement_type === 'out' ? 'mis. Desa X, kelompok penerima' : 'mis. Bulog, pemasok' }}">
                @error('counterparty') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        <div>
            <label for="reference_no" class="mb-1 block text-sm font-medium">
                Nomor dokumen (berita acara / surat jalan){{ $movement_type === 'opening' ? ' (opsional)' : '' }}
            </label>
            <input id="reference_no" type="text" wire:model="reference_no" maxlength="100"
                   class="w-full rounded border border-gray-300 px-3 py-2">
            @error('reference_no') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="notes" class="mb-1 block text-sm font-medium">Catatan (opsional)</label>
            <textarea id="notes" wire:model="notes" rows="2" maxlength="1000" class="w-full rounded border border-gray-300 px-3 py-2"></textarea>
            @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap gap-2 pt-2">
            <button type="button" wire:click="save(false)" wire:loading.attr="disabled"
                    class="rounded border border-emerald-700 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">Simpan draf</button>
            <button type="button" wire:click="save(true)" wire:loading.attr="disabled"
                    class="rounded bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Simpan dan ajukan</button>
            <a href="{{ route('stock.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">Batal</a>
        </div>
    </form>
</div>
