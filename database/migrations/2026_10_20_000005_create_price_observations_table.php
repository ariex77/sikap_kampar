<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_observations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();            // siap sinkronisasi offline (fase 2)
            $t->foreignId('market_id')->constrained()->restrictOnDelete();
            $t->foreignId('commodity_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('units');
            $t->date('observed_on');
            $t->decimal('price', 14, 2);
            $t->decimal('previous_price', 14, 2)->nullable();
            $t->boolean('is_outlier')->default(false);
            $t->string('source_type', 20)->default('field'); // field | import | national
            $t->string('source_reference', 255)->nullable();

            $t->string('status', 20)->default('draft');      // draft|submitted|verified|published|rejected
            $t->text('rejection_reason')->nullable();
            $t->foreignId('reporting_period_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('district_id')->constrained();

            $t->timestamp('client_created_at')->nullable(); // waktu input di perangkat (offline)
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['market_id', 'commodity_id', 'observed_on', 'source_type'], 'price_obs_unique');
            $t->index(['district_id', 'status', 'observed_on']);
            $t->index(['commodity_id', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_observations');
    }
};
