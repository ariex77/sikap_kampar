<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reporting_periods', function (Blueprint $t) {
            $t->id();
            $t->string('period_type', 10);         // daily | weekly | monthly | yearly
            $t->date('start_date');
            $t->date('end_date');
            $t->string('label', 50);
            $t->string('status', 10)->default('open'); // open | locked
            $t->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
            $t->unique(['period_type', 'start_date']);
        });

        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();      // mis. price.outlier_threshold_pct
            $t->json('value');
            $t->string('description', 255)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 50);              // created | updated | deleted | approved | exported | login ...
            $t->string('entity_type', 100);
            $t->string('entity_id', 64);
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity_type', 'entity_id']);
            $t->index(['user_id', 'created_at']);
            // Log bersifat append-only: tidak ada updated_at, tidak ada soft delete.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('reporting_periods');
    }
};
