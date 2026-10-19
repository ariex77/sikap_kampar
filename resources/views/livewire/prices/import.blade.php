<div class="mx-auto max-w-4xl">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">Impor Harga dari CSV</h1>
        <a href="{{ route('prices.index') }}" class="text-sm text-emerald-700 hover:underline">Kembali ke daftar harga</a>
    </div>

    @if ($outcome)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
            <p class="font-medium text-emerald-900">Impor selesai.</p>
            <ul class="mt-2 list-disc pl-5 text-sm text-emerald-900">
                <li>{{ $outcome['imported'] }} harga dibuat sebagai draf.</li>
                @if ($outcome['skipped'] > 0)
                    <li>{{ $outcome['skipped'] }} baris dilewati karena bermasalah.</li>
                @endif
                @if ($submitAfter)
                    <li>{{ $outcome['submitted'] }} harga langsung diajukan untuk verifikasi.
                        @if ($outcome['submit_failed'] > 0) {{ $outcome['submit_failed'] }} gagal diajukan, ajukan manual dari daftar. @endif
                    </li>
                @endif
            </ul>
            <div class="mt-3 flex gap-3 text-sm">
                <a href="{{ route('prices.index', ['status' => $submitAfter ? 'submitted' : 'draft']) }}" class="text-emerald-800 underline">Lihat di daftar harga</a>
                <button type="button" wire:click="startOver" class="text-emerald-800 underline">Impor berkas lain</button>
            </div>
        </div>
    @else
        <div class="rounded-lg bg-white p-5 shadow">
            <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
                <li>Unduh templat, isi di Excel, lalu simpan sebagai <strong>CSV</strong> (File &rarr; Save As &rarr; CSV UTF-8).</li>
                <li>Kolom wajib: <code>tanggal</code>, <code>pasar</code>, <code>komoditas</code>, <code>harga</code>. Kolom <code>keterangan</code> opsional.</li>
                <li>Unggah berkas. Semua baris diperiksa dulu; belum ada data yang tersimpan sampai Anda menekan tombol impor.</li>
                <li>Data masuk sebagai <strong>draf</strong> dan tetap melewati verifikasi seperti input biasa.</li>
            </ol>

            <button type="button" wire:click="downloadTemplate" class="mt-3 text-sm text-emerald-700 underline">Unduh templat CSV</button>

            <div class="mt-4">
                <label for="file" class="mb-1 block text-sm font-medium">Berkas CSV (maksimal 2 MB, 2.000 baris)</label>
                <input id="file" type="file" wire:model="file" accept=".csv,.txt" class="block w-full text-sm">
                <div wire:loading wire:target="file" class="mt-1 text-sm text-gray-500">Memeriksa berkas...</div>
                @error('file') <p class="mt-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        @if ($log)
            <div class="mt-4 rounded-lg bg-white p-5 shadow">
                <h2 class="text-lg font-semibold">Hasil pemeriksaan: {{ $log->original_filename }}</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div class="rounded bg-gray-50 p-3"><p class="text-xs text-gray-500">Total baris</p><p class="text-2xl font-semibold">{{ $log->total_rows }}</p></div>
                    <div class="rounded bg-emerald-50 p-3"><p class="text-xs text-emerald-700">Siap diimpor</p><p class="text-2xl font-semibold text-emerald-800">{{ $log->valid_rows }}</p></div>
                    <div class="rounded bg-red-50 p-3"><p class="text-xs text-red-700">Bermasalah</p><p class="text-2xl font-semibold text-red-700">{{ $log->error_rows }}</p></div>
                </div>

                @if ($errorRows->isNotEmpty())
                    <h3 class="mt-5 text-sm font-semibold text-red-700">Baris bermasalah (tidak akan diimpor) @if ($log->error_rows > 100), 100 pertama @endif</h3>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                                <tr><th class="px-3 py-2">Baris</th><th class="px-3 py-2">Tanggal</th><th class="px-3 py-2">Pasar</th><th class="px-3 py-2">Komoditas</th><th class="px-3 py-2">Harga</th><th class="px-3 py-2">Masalah</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($errorRows as $r)
                                    <tr wire:key="err-{{ $r['row'] }}">
                                        <td class="px-3 py-2">{{ $r['row'] }}</td>
                                        <td class="px-3 py-2">{{ $r['raw']['tanggal'] ?? '' }}</td>
                                        <td class="px-3 py-2">{{ $r['raw']['pasar'] ?? '' }}</td>
                                        <td class="px-3 py-2">{{ $r['raw']['komoditas'] ?? '' }}</td>
                                        <td class="px-3 py-2">{{ $r['raw']['harga'] ?? '' }}</td>
                                        <td class="px-3 py-2 text-red-700">{{ implode(' ', $r['errors']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($validRows->isNotEmpty())
                    <h3 class="mt-5 text-sm font-semibold text-emerald-800">Contoh baris yang akan diimpor @if ($log->valid_rows > 20), 20 pertama @endif</h3>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                                <tr><th class="px-3 py-2">Baris</th><th class="px-3 py-2">Tanggal</th><th class="px-3 py-2">Pasar</th><th class="px-3 py-2">Komoditas</th><th class="px-3 py-2">Harga</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($validRows as $r)
                                    <tr wire:key="ok-{{ $r['row'] }}">
                                        <td class="px-3 py-2">{{ $r['row'] }}</td>
                                        <td class="px-3 py-2">{{ $r['raw']['tanggal'] ?? '' }}</td>
                                        <td class="px-3 py-2">{{ $r['raw']['pasar'] ?? '' }}</td>
                                        <td class="px-3 py-2">{{ $r['raw']['komoditas'] ?? '' }}</td>
                                        <td class="px-3 py-2">{{ $r['raw']['harga'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($log->valid_rows > 0)
                    <label class="mt-5 flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="submitAfter">
                        Langsung ajukan untuk verifikasi (tanpa ini, data tersimpan sebagai draf)
                    </label>
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="commit" wire:loading.attr="disabled" wire:target="commit"
                                class="rounded bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-50">
                            Impor {{ $log->valid_rows }} baris
                        </button>
                        <button type="button" wire:click="startOver" class="rounded border border-gray-300 bg-white px-4 py-2 text-sm">Batal</button>
                    </div>
                @else
                    <p class="mt-4 text-sm text-gray-600">Tidak ada baris yang dapat diimpor. Perbaiki berkas lalu unggah ulang.</p>
                    <button type="button" wire:click="startOver" class="mt-2 rounded border border-gray-300 bg-white px-4 py-2 text-sm">Unggah berkas lain</button>
                @endif
            </div>
        @endif
    @endif
</div>
