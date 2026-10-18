<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('units', function (Blueprint $t) {
            $t->id();
            $t->string('code', 15)->unique();      // kg, l, butir, ikat
            $t->string('name', 50);
            $t->foreignId('base_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $t->decimal('to_base_factor', 14, 6)->nullable(); // konversi sah ke satuan dasar
            $t->timestamps();
        });

        Schema::create('commodities', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 120);
            $t->string('category', 50);            // beras, sayur, daging, ...
            $t->foreignId('default_unit_id')->constrained('units');
            $t->boolean('is_strategic')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('markets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('district_id')->constrained()->restrictOnDelete();
            $t->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 150);
            $t->string('market_type', 30)->nullable();     // tradisional | modern
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['district_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('markets');
        Schema::dropIfExists('commodities');
        Schema::dropIfExists('units');
    }
};
