<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('programs') && ! Schema::hasColumn('programs', 'benefit')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->string('benefit', 80)->default('All residents')->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('programs') && Schema::hasColumn('programs', 'benefit')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->dropColumn('benefit');
            });
        }
    }
};
