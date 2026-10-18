<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Notifikasi memakai tabel bawaan Laravel: php artisan make:notifications-table
return new class extends Migration {
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('attachable_type', 100);
            $t->unsignedBigInteger('attachable_id');
            $t->string('disk', 20)->default('private');
            $t->string('path', 255);
            $t->string('original_name', 255);
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->string('checksum_sha256', 64)->nullable();
            $t->boolean('is_sensitive')->default(false);
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['attachable_type', 'attachable_id']);
        });

        Schema::create('approval_histories', function (Blueprint $t) {
            $t->id();
            $t->string('approvable_type', 100);
            $t->unsignedBigInteger('approvable_id');
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->text('note')->nullable();
            $t->foreignId('acted_by')->constrained('users');
            $t->timestamp('acted_at')->useCurrent();
            $t->index(['approvable_type', 'approvable_id']);
        });

        Schema::create('publications', function (Blueprint $t) {
            $t->id();
            $t->string('publishable_type', 100);
            $t->unsignedBigInteger('publishable_id');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('published_by')->constrained('users');
            $t->timestamp('published_at');
            $t->foreignId('unpublished_by')->nullable()->constrained('users');
            $t->timestamp('unpublished_at')->nullable();
            $t->text('unpublish_reason')->nullable();
            $t->index(['publishable_type', 'publishable_id']);
        });

        Schema::create('import_logs', function (Blueprint $t) {
            $t->id();
            $t->string('import_type', 40);         // price | stock | region ...
            $t->string('original_filename', 255);
            $t->string('file_path', 255);
            $t->string('status', 20)->default('previewed'); // previewed|committed|failed
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('error_rows')->default(0);
            $t->foreignId('imported_by')->constrained('users');
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_log_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('payload');
            $t->string('result', 15);              // valid | error | duplicate | imported
            $t->json('errors')->nullable();
            $t->index(['import_log_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_logs');
        Schema::dropIfExists('publications');
        Schema::dropIfExists('approval_histories');
        Schema::dropIfExists('attachments');
    }
};
