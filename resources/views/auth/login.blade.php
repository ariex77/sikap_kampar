<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk - SIKAP KAMPAR</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
    <div class="w-full max-w-sm rounded-lg bg-white p-6 shadow">
        <h1 class="text-xl font-semibold text-emerald-700">SIKAP KAMPAR</h1>
        <p class="mb-5 text-sm text-gray-500">Sistem Informasi Ketahanan Pangan Kabupaten Kampar</p>

        <form method="POST" action="{{ url('/login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded border border-gray-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                @error('email')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Kata sandi</label>
                <input id="password" name="password" type="password" required
                       class="w-full rounded border border-gray-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" value="1"> Ingat saya
            </label>

            <button type="submit" class="w-full rounded bg-emerald-700 py-2 font-medium text-white hover:bg-emerald-800">
                Masuk
            </button>
        </form>
    </div>
</body>
</html>
