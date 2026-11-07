<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('distributions', 'program_id')) {
            Schema::table('distributions', function (Blueprint $table): void {
                $table->string('program_id', 40)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('distributions', 'program_id')) {
            Schema::table('distributions', function (Blueprint $table): void {
                $table->dropIndex(['program_id']);
                $table->dropColumn('program_id');
            });
        }
    }
};
