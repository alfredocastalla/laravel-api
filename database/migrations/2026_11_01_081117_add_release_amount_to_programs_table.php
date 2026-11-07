<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('programs', 'release_amount')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->string('release_amount', 120)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('programs', 'release_amount')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->dropColumn('release_amount');
            });
        }
    }
};
