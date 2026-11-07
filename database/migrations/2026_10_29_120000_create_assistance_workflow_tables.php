<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistance_applications')) {
            Schema::create('assistance_applications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('beneficiary', 150);
                $table->string('username', 80);
                $table->string('program_id', 40);
                $table->string('program_name', 180);
                $table->string('benefit', 120);
                $table->string('reason', 1000);
                $table->string('status', 30)->default('Submitted');
                $table->string('reviewed_by', 150)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 1000)->nullable();
                $table->timestamps();

                $table->index(['user_id', 'program_id', 'status'], 'assistance_applications_resident_program_status_index');
            });
        }

        if (! Schema::hasTable('distributions')) {
            Schema::create('distributions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('application_id')->nullable()->unique();
                $table->string('assistance_id', 40)->nullable()->unique();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('beneficiary', 150);
                $table->string('benefit', 120);
                $table->string('amount', 120);
                $table->string('distributed_by', 150);
                $table->timestamp('distributed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distributions');
        Schema::dropIfExists('assistance_applications');
    }
};
