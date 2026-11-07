<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('programs', 'program_time')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->time('program_time')->nullable();
            });
        }

        if (! Schema::hasColumn('programs', 'assigned_staff')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->string('assigned_staff', 150)->nullable();
            });
        }

        if (! Schema::hasColumn('programs', 'instructions')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->text('instructions')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('programs', 'assigned_staff')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->dropColumn('assigned_staff');
            });
        }

        if (Schema::hasColumn('programs', 'program_time')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->dropColumn('program_time');
            });
        }

        if (Schema::hasColumn('programs', 'instructions')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->dropColumn('instructions');
            });
        }
    }
};
