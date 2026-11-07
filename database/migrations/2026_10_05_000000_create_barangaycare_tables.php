<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beneficiaries')) {
            Schema::create('beneficiaries', function (Blueprint $table) {
                $table->string('id', 40)->primary();
                $table->string('name', 150);
                $table->string('purok', 100);
                $table->string('category', 255);
                $table->string('contact', 40);
                $table->string('status', 30);
                $table->string('last_aid', 120);
                $table->date('updated');
            });
        }

        if (! Schema::hasTable('programs')) {
            Schema::create('programs', function (Blueprint $table) {
                $table->string('id', 40)->primary();
                $table->string('name', 180);
                $table->date('date');
                $table->string('venue', 180);
                $table->unsignedInteger('slots')->default(0);
                $table->string('status', 30);
            });
        }

        if (! Schema::hasTable('assistance')) {
            Schema::create('assistance', function (Blueprint $table) {
                $table->string('id', 40)->primary();
                $table->string('beneficiary', 150);
                $table->string('type', 120);
                $table->string('amount', 120);
                $table->date('date');
                $table->string('status', 30);
                $table->string('staff', 150);
                $table->date('claimed_at')->nullable();
                $table->string('claimed_by', 150)->nullable();
            });
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('role', 30);
                $table->string('beneficiary_name', 150)->default('');
                $table->string('username', 80)->unique();
                $table->string('password');
                $table->string('status', 30);
                $table->string('contact', 40)->default('');
                $table->string('email', 150)->default('');
                $table->string('position', 120)->default('');
                $table->string('address', 255)->default('');
                $table->longText('profile_image')->nullable();
            });
        } else {
            $this->upgradeExistingUsersTable();
        }

        if (! Schema::hasTable('logs')) {
            Schema::create('logs', function (Blueprint $table) {
                $table->id();
                $table->string('event_time', 60);
                $table->string('actor', 150);
                $table->string('action', 255);
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->string('setting_key', 80)->primary();
                $table->text('setting_value');
            });
        }

        if (! Schema::hasTable('app_state')) {
            Schema::create('app_state', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->longText('payload');
                $table->timestamp('updated_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_state');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('logs');
        Schema::dropIfExists('assistance');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('beneficiaries');
    }

    private function upgradeExistingUsersTable(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 30)->default('User');
            }
            if (! Schema::hasColumn('users', 'beneficiary_name')) {
                $table->string('beneficiary_name', 150)->default('');
            }
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username', 80)->nullable();
            }
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 30)->default('Active');
            }
            if (! Schema::hasColumn('users', 'contact')) {
                $table->string('contact', 40)->default('');
            }
            if (! Schema::hasColumn('users', 'position')) {
                $table->string('position', 120)->default('');
            }
            if (! Schema::hasColumn('users', 'address')) {
                $table->string('address', 255)->default('');
            }
            if (! Schema::hasColumn('users', 'profile_image')) {
                $table->longText('profile_image')->nullable();
            }
        });

        DB::table('users')->whereNull('username')->orWhere('username', '')->orderBy('id')
            ->each(function (object $user): void {
                DB::table('users')->where('id', $user->id)->update(['username' => 'legacy-'.$user->id]);
            });

        $indexes = Schema::getIndexes('users');
        $hasUsernameIndex = collect($indexes)->contains('name', 'users_username_unique');
        if (! $hasUsernameIndex) {
            Schema::table('users', fn (Blueprint $table) => $table->unique('username'));
        }

        $hasEmailIndex = collect($indexes)->contains('name', 'users_email_unique');
        if ($hasEmailIndex) {
            Schema::table('users', fn (Blueprint $table) => $table->dropUnique('users_email_unique'));
        }
    }
};
