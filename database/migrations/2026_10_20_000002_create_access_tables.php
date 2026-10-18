<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel roles/permissions disediakan paket spatie/laravel-permission (php artisan vendor:publish).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('nip', 30)->nullable()->unique()->after('email');
            $t->string('phone', 20)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamp('last_login_at')->nullable();
            $t->softDeletes();
        });

        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('organization_type', 30);   // dkp | opd | kecamatan | lainnya
            $t->foreignId('parent_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('user_organization', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('position', 100)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'organization_id']);
        });

        // Cakupan data: peran = APA yang boleh, scope = DI DATA MANA.
        Schema::create('user_scopes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('scope_type', 20);          // kabupaten | organization | district | village | market
            $t->unsignedBigInteger('scope_id')->nullable(); // null untuk kabupaten (semua)
            $t->string('data_domain', 30)->default('*'); // harga | stok | kerawanan | konsumsi | pengawasan | kwt | *
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('assignment_ref', 150)->nullable(); // nomor SK/penugasan
            $t->timestamps();
            $t->index(['user_id', 'scope_type', 'scope_id', 'data_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_scopes');
        Schema::dropIfExists('user_organization');
        Schema::dropIfExists('organizations');
        Schema::table('users', function (Blueprint $t) {
            $t->dropSoftDeletes();
            $t->dropColumn(['nip', 'phone', 'is_active', 'last_login_at']);
        });
    }
};
