<div>
    @php $f = \App\Support\Format::class; @endphp

    {{-- Kepala halaman dan filter --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Dashboard</h1>
            <p class="mt-1 text-sm text-gray-600">
                Selamat datang, <strong>{{ $user->name }}</strong>.
                @foreach ($user->getRoleNames() as $role)
                    <span class="ml-1 rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">{{ $role }}</span>
                @endforeach
            </p>
        </div>

        @if ($price || $stock)
            <div class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-3 shadow">
                @if ($price)
                    <div>
                        <label for="commodity" class="mb-1 block text-xs font-medium text-gray-500">Komoditas (grafik harga)</label>
                        <select id="commodity" wire:model.live="commodity" class="rounded border border-gray-300 px-2 py-1.5 text-sm">
                            <option value="">Otomatis: {{ $price['selected_name'] ?? '-' }}</option>
                            @foreach ($price['commodities'] as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label for="days" class="mb-1 block text-xs font-medium text-gray-500">Periode</label>
                    <select id="days" wire:model.live="days" class="rounded border border-gray-300 px-2 py-1.5 text-sm">
                        @foreach ($periods as $p)
                            <option value="{{ $p }}">{{ $p }} hari terakhir</option>
                        @endforeach
                    </select>
                </div>
                <p class="pb-1.5 text-xs text-gray-500">{{ $f::date($from) }} – {{ $f::date($to) }}</p>
            </div>
        @endif
    </div>

    {{-- Perlu tindakan --}}
    @if (count($queue) > 0)
        <section class="mb-8" aria-labelledby="queue-heading">
            <h2 id="queue-heading" class="mb-2 text-lg font-semibold">Perlu tindakan Anda</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($queue as $item)
                    <a href="{{ $item['url'] }}" wire:key="queue-{{ $item['key'] }}"
                       class="block rounded-lg border bg-white p-4 shadow-sm hover:border-emerald-600 {{ $item['count'] > 0 && $item['urgent'] ? 'border-amber-400' : 'border-gray-200' }}">
                        <p class="text-3xl font-semibold {{ $item['count'] > 0 && $item['urgent'] ? 'text-amber-700' : 'text-gray-800' }}">{{ $item['count'] }}</p>
                        <p class="text-sm text-gray-700">{{ $item['label'] }}</p>
                        @if ($item['note'])
                            <p class="mt-1 text-xs text-red-700">{{ $item['note'] }}</p>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Harga pangan --}}
    @if ($price)
        <section class="mb-8" aria-labelledby="price-heading">
            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="price-heading" class="text-lg font-semibold">Harga pangan</h2>
                <p class="text-xs text-gray-500">{{ $price['scope_label'] }} · hanya data berstatus Terbit</p>
            </div>

            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                <a href="{{ $price['links']['all'] }}" class="block rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:border-emerald-600">
                    <p class="text-3xl font-semibold">{{ $price['totals']['observations'] }}</p>
                    <p class="text-sm text-gray-700">Data harga terbit</p>
                    <p class="text-xs text-gray-400">{{ $f::date($from) }} – {{ $f::date($to) }}</p>
                </a>
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-3xl font-semibold">{{ $price['totals']['markets'] }}</p>
                    <p class="text-sm text-gray-700">Pasar terpantau</p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-3xl font-semibold">{{ $price['totals']['commodities'] }}</p>
                    <p class="text-sm text-gray-700">Komoditas terpantau</p>
                </div>
            </div>

            {{-- Grafik tren --}}
            <div class="mb-4 rounded-lg bg-white p-4 shadow">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-medium">
                        Tren harga {{ $price['selected_name'] ?? '' }}@if ($price['unit']) (per {{ $price['unit'] }})@endif
                    </h3>
                    <a href="{{ $price['links']['source'] }}" class="text-sm text-emerald-700 hover:underline">Lihat data sumber →</a>
                </div>

                @if (count($price['chart']['labels']) > 0)
                    <div wire:ignore wire:key="trend-{{ $price['chart_key'] }}" x-data x-init="renderTrendChart($refs.canvas)">
                        <div class="relative h-64">
                            <canvas x-ref="canvas" role="img"
                                    aria-label="Grafik tren harga {{ $price['selected_name'] ?? '' }}"
                                    data-chart="{{ json_encode($price['chart'], JSON_UNESCAPED_UNICODE) }}"></canvas>
                        </div>
                    </div>

                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-gray-600">Data di balik grafik</summary>
                        <div class="mt-2 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                                    <tr>
                                        <th class="px-3 py-2">Tanggal</th>
                                        <th class="px-3 py-2 text-right">Rata-rata</th>
                                        <th class="px-3 py-2 text-right">Terendah</th>
                                        <th class="px-3 py-2 text-right">Tertinggi</th>
                                        <th class="px-3 py-2 text-right">Jumlah data</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($price['trend'] as $row)
                                        <tr wire:key="trend-row-{{ $row['day'] }}">
                                            <td class="px-3 py-2">{{ $f::date($row['day']) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $f::rupiah($row['avg']) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $f::rupiah($row['min']) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $f::rupiah($row['max']) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $row['observations'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @else
                    <p class="py-8 text-center text-sm text-gray-500">Belum ada harga terbit untuk komoditas ini pada periode terpilih.</p>
                @endif
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                {{-- Harga terakhir per pasar --}}
                <div class="overflow-x-auto rounded-lg bg-white shadow">
                    <h3 class="px-4 pt-4 font-medium">Harga terakhir per pasar: {{ $price['selected_name'] ?? '-' }}</h3>
                    <p class="px-4 pb-2 text-xs text-gray-500">Pengamatan terbit dalam 60 hari terakhir.</p>
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                            <tr>
                                <th class="px-3 py-2">Pasar</th>
                                <th class="px-3 py-2">Tanggal</th>
                                <th class="px-3 py-2 text-right">Harga</th>
                                <th class="px-3 py-2 text-right">Perubahan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($price['markets'] as $row)
                                <tr wire:key="market-{{ $loop->index }}">
                                    <td class="px-3 py-2">{{ $row['market'] }}<span class="block text-xs text-gray-500">{{ $row['district'] }}</span></td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $f::date($row['day']) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::rupiah($row['price']) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::percent($row['change']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">Belum ada data terbit pada cakupan Anda.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Ringkasan komoditas --}}
                <div class="overflow-x-auto rounded-lg bg-white shadow">
                    <h3 class="px-4 pt-4 font-medium">Ringkasan komoditas</h3>
                    <p class="px-4 pb-2 text-xs text-gray-500">Rata-rata antar pasar pada tanggal pengamatan terbaru. Klik nama untuk melihat grafiknya.</p>
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                            <tr>
                                <th class="px-3 py-2">Komoditas</th>
                                <th class="px-3 py-2">Tanggal</th>
                                <th class="px-3 py-2 text-right">Rata-rata</th>
                                <th class="px-3 py-2 text-right">Perubahan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($price['overview'] as $row)
                                <tr wire:key="overview-{{ $row['commodity_id'] }}">
                                    <td class="px-3 py-2">
                                        <a href="{{ route('dashboard', ['commodity' => $row['commodity_id'], 'days' => $periodDays]) }}"
                                           class="text-emerald-700 hover:underline">{{ $row['name'] }}</a>
                                        <span class="block text-xs text-gray-500">{{ $row['markets'] }} pasar · per {{ $row['unit'] }}</span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $f::date($row['day']) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::rupiah($row['avg']) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::percent($row['change']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">Belum ada data terbit.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

    {{-- Stok dan cadangan --}}
    @if ($stock)
        <section class="mb-8" aria-labelledby="stock-heading">
            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="stock-heading" class="text-lg font-semibold">Stok dan cadangan pangan</h2>
                <p class="text-xs text-gray-500">{{ $stock['scope_label'] }} · hanya mutasi yang sudah disetujui</p>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                        <tr>
                            <th class="px-3 py-2">Komoditas</th>
                            <th class="px-3 py-2 text-right">Saldo saat ini</th>
                            <th class="px-3 py-2 text-right">Lokasi</th>
                            <th class="px-3 py-2 text-right">Penerimaan ({{ $periodDays }} hari)</th>
                            <th class="px-3 py-2 text-right">Penyaluran ({{ $periodDays }} hari)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stock['rows'] as $row)
                            <tr wire:key="stock-{{ $row['commodity_id'] }}">
                                <td class="px-3 py-2">{{ $row['name'] }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap font-medium">{{ $f::quantity($row['balance_milli']) }} {{ $row['unit'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['locations'] }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::quantity($row['inflow_milli']) }} {{ $row['unit'] }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::quantity($row['outflow_milli']) }} {{ $row['unit'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Belum ada saldo pada cakupan Anda. Saldo muncul setelah mutasi pertama disetujui.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                Penerimaan dan penyaluran adalah angka bersih dari mutasi yang disetujui pada periode terpilih; koreksi dihitung pada tanggal koreksi.
                <a href="{{ $stock['source_url'] }}" class="text-emerald-700 hover:underline">Lihat mutasi sumber →</a>
            </p>
        </section>
    @endif

    @if (! $price && ! $stock)
        <div class="rounded-lg bg-white p-5 text-sm text-gray-600 shadow">
            Peran Anda tidak memiliki akses ke data harga atau stok. Hubungi admin bila ini tidak sesuai.
        </div>
    @endif
</div>
