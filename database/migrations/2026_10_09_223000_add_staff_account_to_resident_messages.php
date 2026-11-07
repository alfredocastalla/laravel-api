<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resident_messages') || Schema::hasColumn('resident_messages', 'staff_username')) {
            return;
        }

        Schema::table('resident_messages', function (Blueprint $table): void {
            $table->string('staff_name', 150)->nullable()->after('from_role');
            $table->string('staff_username', 80)->nullable()->after('staff_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('resident_messages') || ! Schema::hasColumn('resident_messages', 'staff_username')) {
            return;
        }

        Schema::table('resident_messages', function (Blueprint $table): void {
            $table->dropColumn(['staff_name', 'staff_username']);
        });
    }
};
