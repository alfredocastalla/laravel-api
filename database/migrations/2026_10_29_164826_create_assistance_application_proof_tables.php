<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_application_proof_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')
                ->unique()
                ->constrained('assistance_applications')
                ->cascadeOnDelete();
            $table->string('requested_by', 150);
            $table->string('note', 1000);
            $table->string('status', 20)->default('Requested');
            $table->timestamp('requested_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('assistance_application_proof_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proof_request_id')
                ->constrained('assistance_application_proof_requests')
                ->cascadeOnDelete();
            $table->string('storage_path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size');
            $table->timestamp('uploaded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_application_proof_files');
        Schema::dropIfExists('assistance_application_proof_requests');
    }
};
