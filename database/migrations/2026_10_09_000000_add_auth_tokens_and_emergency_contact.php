<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'emergency_contact')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('emergency_contact', 80)->default('');
            });
        }

        if (! Schema::hasTable('auth_tokens')) {
            Schema::create('auth_tokens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_tokens');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'emergency_contact')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('emergency_contact');
            });
        }
    }
};
