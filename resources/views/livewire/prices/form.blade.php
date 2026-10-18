<div class="mx-auto max-w-xl">
    <h1 class="mb-4 text-2xl font-semibold">{{ $priceId ? 'Ubah Harga' : 'Input Harga' }}</h1>

    @if ($rejectionReason)
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <strong>Dikembalikan oleh verifikator:</strong> {{ $rejectionReason }}
        </div>
    @endif

    <form class="space-y-4 rounded-lg bg-white p-5 shadow" wire:submit.prevent>
        <div>
            <label for="market_id" class="mb-1 block text-sm font-medium">Pasar</label>
            <select id="market_id" wire:model="market_id" class="w-full rounded border border-gray-300 px-3 py-2">
                <option value="">Pilih pasar</option>
                @foreach ($markets as $m)
                    <option value="{{ $m->id }}">{{ $m->name }}</option>
                @endforeach
            </select>
            @error('market_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @if ($markets->isEmpty())
                <p class="mt-1 text-sm text-amber-700">Belum ada pasar pada wilayah kerja Anda. Hubungi admin.</p>
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

        <div>
            <label for="observed_on" class="mb-1 block text-sm font-medium">Tanggal pengamatan</label>
            <input id="observed_on" type="date" wire:model="observed_on" max="{{ today()->toDateString() }}"
                   class="w-full rounded border border-gray-300 px-3 py-2">
            @error('observed_on') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="price" class="mb-1 block text-sm font-medium">
                Harga (Rp{{ $unitCode ? ' per ' . $unitCode : '' }})
            </label>
            <input id="price" type="number" step="0.01" min="0" inputmode="decimal" wire:model="price"
                   class="w-full rounded border border-gray-300 px-3 py-2" placeholder="contoh: 15000">
            <p class="mt-1 text-xs text-gray-500">Tulis angka saja, tanpa titik atau koma ribuan.</p>
            @error('price') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="source_reference" class="mb-1 block text-sm font-medium">Keterangan sumber (opsional)</label>
            <input id="source_reference" type="text" wire:model="source_reference" maxlength="255"
                   class="w-full rounded border border-gray-300 px-3 py-2" placeholder="mis. pedagang A, Los Sayur">
            @error('source_reference') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap gap-2 pt-2">
            <button type="button" wire:click="save(false)" wire:loading.attr="disabled"
                    class="rounded border border-emerald-700 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                Simpan draf
            </button>
            <button type="button" wire:click="save(true)" wire:loading.attr="disabled"
                    class="rounded bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                Simpan dan ajukan
            </button>
            <a href="{{ route('prices.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">Batal</a>
        </div>
    </form>
</div>
