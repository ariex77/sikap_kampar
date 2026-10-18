@extends('layouts.app')

@section('title', 'Dashboard - SIKAP KAMPAR')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">Dashboard</h1>

    <div class="rounded-lg bg-white p-5 shadow">
        <p>Selamat datang, <strong>{{ auth()->user()->name }}</strong>.</p>
        <p class="mt-2 text-sm text-gray-600">
            Peran:
            @foreach (auth()->user()->getRoleNames() as $role)
                <span class="mr-1 rounded bg-emerald-100 px-2 py-0.5 text-emerald-800">{{ $role }}</span>
            @endforeach
        </p>
        <p class="mt-2 text-sm text-gray-600">
            Jumlah izin: {{ auth()->user()->getAllPermissions()->count() }}
        </p>
    </div>
@endsection
