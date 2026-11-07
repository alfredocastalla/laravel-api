<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resident_messages')) {
            return;
        }

        Schema::create('resident_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('resident_name', 150);
            $table->string('username', 80);
            $table->string('kind', 30);
            $table->string('body', 1000);
            $table->string('from_role', 20);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_messages');
    }
};
