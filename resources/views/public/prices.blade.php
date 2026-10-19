@extends('layouts.public')

@section('title', 'Harga Pangan Kabupaten Kampar - SIKAP KAMPAR')

@section('content')
    @php $f = \App\Support\Format::class; @endphp

    <h1 class="text-2xl font-semibold">Harga Pangan Kabupaten Kampar</h1>
    <p class="mt-1 text-sm text-gray-600">
        Hasil pemantauan Dinas Ketahanan Pangan yang telah diverifikasi. Harga dapat berbeda dengan harga di masing-masing pedagang.
        @if ($latestDay)
            Data terbaru: <strong>{{ $f::date($latestDay) }}</strong>.
        @endif
    </p>

    @if ($overview->isEmpty())
        <div class="mt-6 rounded-lg bg-white p-6 text-center text-gray-600 shadow">
            Belum ada data harga yang dipublikasikan.
        </div>
    @else
        {{-- Ringkasan semua komoditas --}}
        <section class="mt-6" aria-labelledby="overview-heading">
            <h2 id="overview-heading" class="mb-2 text-lg font-semibold">Harga terbaru</h2>
            <div class="overflow-x-auto rounded-lg bg-white shadow">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                        <tr>
                            <th class="px-3 py-2">Komoditas</th>
                            <th class="px-3 py-2">Tanggal</th>
                            <th class="px-3 py-2 text-right">Rata-rata</th>
                            <th class="px-3 py-2 text-right">Terendah – tertinggi</th>
                            <th class="px-3 py-2 text-right">Pasar</th>
                            <th class="px-3 py-2 text-right">Perubahan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($overview as $row)
                            <tr>
                                <td class="px-3 py-2">
                                    <a href="{{ route('public.prices', ['komoditas' => $row['commodity_id'], 'rentang' => $days]) }}"
                                       class="text-emerald-700 hover:underline">{{ $row['name'] }}</a>
                                    <span class="block text-xs text-gray-500">per {{ $row['unit'] }}</span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $f::date($row['day']) }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap font-medium">{{ $f::rupiah($row['avg']) }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::rupiah($row['min']) }} – {{ $f::rupiah($row['max']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['markets'] }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::percent($row['change']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">Rata-rata antar pasar pada tanggal pengamatan terbaru. Perubahan dibandingkan tanggal pengamatan sebelumnya.</p>
        </section>

        {{-- Rincian satu komoditas --}}
        @if ($selected)
            <section class="mt-8" aria-labelledby="detail-heading">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <h2 id="detail-heading" class="text-lg font-semibold">Tren harga {{ $selected['name'] }} (per {{ $selected['unit'] }})</h2>

                    <form method="GET" action="{{ route('public.prices') }}" class="flex flex-wrap items-end gap-2">
                        <div>
                            <label for="komoditas" class="mb-1 block text-xs font-medium text-gray-500">Komoditas</label>
                            <select id="komoditas" name="komoditas" class="rounded border border-gray-300 px-2 py-1.5 text-sm">
                                @foreach ($overview as $row)
                                    <option value="{{ $row['commodity_id'] }}" @selected($row['commodity_id'] === $selected['commodity_id'])>{{ $row['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="rentang" class="mb-1 block text-xs font-medium text-gray-500">Periode</label>
                            <select id="rentang" name="rentang" class="rounded border border-gray-300 px-2 py-1.5 text-sm">
                                @foreach ($periods as $p)
                                    <option value="{{ $p }}" @selected($p === $days)>{{ $p }} hari terakhir</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="rounded bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-800">Tampilkan</button>
                    </form>
                </div>

                <div class="rounded-lg bg-white p-4 shadow">
                    @if (count($chart['labels']) > 0)
                        <div class="relative h-64">
                            <canvas data-public-chart role="img"
                                    aria-label="Grafik tren harga {{ $selected['name'] }}"
                                    data-chart="{{ json_encode($chart, JSON_UNESCAPED_UNICODE) }}"></canvas>
                        </div>

                        <details class="mt-3 text-sm">
                            <summary class="cursor-pointer text-gray-600">Lihat dalam bentuk tabel</summary>
                            <div class="mt-2 overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-100 text-left text-xs uppercase text-gray-600">
                                        <tr>
                                            <th class="px-3 py-2">Tanggal</th>
                                            <th class="px-3 py-2 text-right">Rata-rata</th>
                                            <th class="px-3 py-2 text-right">Terendah</th>
                                            <th class="px-3 py-2 text-right">Tertinggi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($trend as $row)
                                            <tr>
                                                <td class="px-3 py-2">{{ $f::date($row['day']) }}</td>
                                                <td class="px-3 py-2 text-right">{{ $f::rupiah($row['avg']) }}</td>
                                                <td class="px-3 py-2 text-right">{{ $f::rupiah($row['min']) }}</td>
                                                <td class="px-3 py-2 text-right">{{ $f::rupiah($row['max']) }}</td>
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

                <h3 class="mb-2 mt-6 font-medium">Harga terakhir per pasar</h3>
                <div class="overflow-x-auto rounded-lg bg-white shadow">
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
                            @forelse ($markets as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ $row['market'] }}<span class="block text-xs text-gray-500">Kec. {{ $row['district'] }}</span></td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $f::date($row['day']) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::rupiah($row['price']) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ $f::percent($row['change']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">Belum ada data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
@endsection
