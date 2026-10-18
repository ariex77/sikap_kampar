<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();      // kode wilayah resmi (Kemendagri/BPS)
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('villages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('district_id')->constrained()->restrictOnDelete();
            $t->string('code', 20)->unique();
            $t->string('name', 100);
            $t->string('type', 20)->default('desa'); // desa | kelurahan
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['district_id', 'name']);
        });

        Schema::create('geo_boundaries', function (Blueprint $t) {
            $t->id();
            $t->string('boundary_type', 20);        // district | village
            $t->unsignedBigInteger('boundary_id');
            $t->json('geojson');
            $t->string('source', 150)->nullable();
            $t->date('valid_from')->nullable();
            $t->timestamps();
            $t->unique(['boundary_type', 'boundary_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_boundaries');
        Schema::dropIfExists('villages');
        Schema::dropIfExists('districts');
    }
};
