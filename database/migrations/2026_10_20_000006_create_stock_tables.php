<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('district_id')->constrained();
            $t->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 150);
            $t->string('address', 255)->nullable();
            $t->decimal('capacity', 16, 3)->nullable();
            $t->foreignId('capacity_unit_id')->nullable()->constrained('units');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        // Snapshot saldo SAAT INI. Hanya berubah lewat persetujuan stock_movements, dalam transaksi + lockForUpdate.
        Schema::create('food_stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('storage_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('commodity_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('units');
            $t->decimal('balance', 16, 3)->default(0);
            $t->unsignedBigInteger('last_movement_id')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->unique(['storage_location_id', 'commodity_id']);
        });

        // Sumber kebenaran: tidak pernah diedit/dihapus. Koreksi = mutasi pembalik (reverses_id).
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('storage_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('commodity_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('units');
            $t->string('movement_type', 15);       // in | out | opening | reversal
            $t->decimal('quantity', 16, 3);        // selalu positif; arah ditentukan movement_type
            $t->date('movement_date');
            $t->string('reference_no', 100)->nullable(); // BA/SPK/nomor dokumen
            $t->string('counterparty', 150)->nullable(); // asal/tujuan penyaluran
            $t->text('notes')->nullable();
            $t->foreignId('reverses_id')->nullable()->constrained('stock_movements')->restrictOnDelete();

            $t->string('status', 20)->default('draft');     // draft|submitted|verified|approved|rejected
            $t->text('rejection_reason')->nullable();
            $t->foreignId('reporting_period_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();

            $t->index(['storage_location_id', 'commodity_id', 'movement_date'], 'stock_mv_loc_comm_date_idx');
            $t->index(['status', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('food_stocks');
        Schema::dropIfExists('storage_locations');
    }
};
