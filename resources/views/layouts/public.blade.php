<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Informasi Harga Pangan - Kabupaten Kampar')</title>
    <meta name="description" content="@yield('description', 'Informasi harga pangan hasil pemantauan Dinas Ketahanan Pangan Kabupaten Kampar.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
    <header class="bg-emerald-700 text-white">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-2 px-4 py-3">
            <a href="{{ route('public.prices') }}" class="font-semibold">SIKAP KAMPAR</a>
            <a href="{{ route('login') }}" class="rounded bg-white/20 px-3 py-1 text-sm hover:bg-white/30">Masuk petugas</a>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6">
        @yield('content')
    </main>

    <footer class="border-t bg-white">
        <div class="mx-auto max-w-5xl px-4 py-4 text-xs text-gray-500">
            Dinas Ketahanan Pangan Kabupaten Kampar. Informasi pada laman ini hanya memuat data yang telah diverifikasi dan diterbitkan.
        </div>
    </footer>
</body>
</html>
