<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $title ?? 'SIKAP KAMPAR')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
    <header class="bg-emerald-700 text-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-3">
            <nav class="flex flex-wrap items-center gap-5 text-sm">
                <a href="{{ route('dashboard') }}" class="font-semibold">SIKAP KAMPAR</a>
                <a href="{{ route('dashboard') }}" class="hover:underline">Dashboard</a>
                @can('price.view')
                    <a href="{{ route('prices.index') }}" class="hover:underline">Harga Pangan</a>
                @endcan
                @can('stock.view')
                    <a href="{{ route('stock.index') }}" class="hover:underline">Stok dan Cadangan</a>
                @endcan
                <a href="{{ route('public.prices') }}" target="_blank" rel="noopener" class="hover:underline">Portal publik</a>
            </nav>
            @auth
                <form method="POST" action="{{ route('logout') }}" class="flex items-center gap-3 text-sm">
                    @csrf
                    <span>{{ auth()->user()->name }}</span>
                    <button type="submit" class="rounded bg-white/20 px-3 py-1 hover:bg-white/30">Keluar</button>
                </form>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        {{-- Halaman Blade biasa memakai @section('content'); komponen Livewire memakai $slot --}}
        @isset($slot)
            {{ $slot }}
        @else
            @yield('content')
        @endisset
    </main>
</body>
</html>
