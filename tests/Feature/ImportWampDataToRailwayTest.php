<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportWampDataToRailwayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.railway' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('railway');

        $this->artisan('migrate:fresh', [
            '--database' => 'railway',
            '--force' => true,
        ])->assertExitCode(0);
    }

    public function test_dry_run_reports_source_and_target_counts_without_writing(): void
    {
        DB::table('users')->insert($this->user('fred', 'Local Fred Hash'));

        $this->artisan('barangaycare:import-wamp-data')
            ->expectsTable(
                ['Table', 'WAMP rows', 'Railway rows'],
                [
                    ['users', '1', '0'],
                    ['programs', '0', '0'],
                    ['beneficiaries', '0', '0'],
                    ['assistance', '0', '0'],
                    ['assistance_applications', '0', '0'],
                    ['assistance_application_proof_requests', '0', '0'],
                    ['assistance_application_proof_files', '0', '0'],
                    ['distributions', '0', '0'],
                    ['resident_messages', '0', '0'],
                    ['settings', '0', '0'],
                    ['logs', '0', '0'],
                ],
            )
            ->expectsOutputToContain('Read-only dry run complete. No data was changed.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 1, 'sqlite');
        $this->assertDatabaseCount('users', 0, 'railway');
    }

    public function test_apply_preserves_matching_railway_user_and_imports_other_records_idempotently(): void
    {
        DB::table('users')->insert([
            $this->user('fred', 'WAMP Fred Hash'),
            $this->user('resident-one', 'WAMP Resident Hash'),
        ]);
        DB::table('programs')->insert($this->program('program-one', 'Food support'));
        DB::table('beneficiaries')->insert($this->beneficiary('resident-one', 'Resident One'));
        DB::table('assistance')->insert($this->assistance('assistance-one', 'Cash assistance'));
        DB::table('assistance_applications')->insert([
            'user_id' => 2,
            'beneficiary' => 'Resident One',
            'username' => 'resident-one',
            'program_id' => 'program-one',
            'program_name' => 'Food support',
            'benefit' => 'Food pack',
            'reason' => 'Household support',
            'status' => 'Submitted',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);
        DB::table('distributions')->insert([
            'application_id' => 1,
            'assistance_id' => 'assistance-one',
            'user_id' => 2,
            'beneficiary' => 'Resident One',
            'benefit' => 'Food pack',
            'amount' => 'One food pack',
            'distributed_by' => 'staff-one',
            'distributed_at' => '2026-10-02 09:00:00',
            'created_at' => '2026-10-02 09:00:00',
            'updated_at' => '2026-10-02 09:00:00',
            'program_id' => 'program-one',
        ]);
        DB::table('settings')->insert([
            'setting_key' => 'barangayName',
            'setting_value' => 'Local Barangay',
        ]);

        DB::connection('railway')->table('users')->insert([
            $this->user('fred', 'Railway Fred Hash'),
        ]);
        DB::connection('railway')->table('programs')->insert($this->program('program-one', 'Railway food support'));
        DB::connection('railway')->table('beneficiaries')->insert($this->beneficiary('resident-one', 'Railway resident'));
        DB::connection('railway')->table('assistance')->insert($this->assistance('assistance-one', 'Existing assistance'));
        DB::connection('railway')->table('settings')->insert([
            'setting_key' => 'barangayName',
            'setting_value' => 'Railway Barangay',
        ]);

        $this->artisan('barangaycare:import-wamp-data', [
            '--apply' => true,
            '--backup-confirmed' => true,
            '--confirm-database' => 'laravel',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('users', 2, 'railway');
        $this->assertDatabaseCount('programs', 2, 'railway');
        $this->assertDatabaseCount('beneficiaries', 2, 'railway');
        $this->assertDatabaseCount('assistance', 2, 'railway');
        $this->assertDatabaseCount('assistance_applications', 1, 'railway');
        $this->assertDatabaseCount('distributions', 1, 'railway');
        $this->assertDatabaseHas('users', [
            'username' => 'fred',
            'password' => 'Railway Fred Hash',
        ], 'railway');
        $this->assertDatabaseHas('users', [
            'username' => 'resident-one',
            'password' => 'WAMP Resident Hash',
        ], 'railway');
        $this->assertDatabaseHas('programs', [
            'id' => 'program-one',
            'name' => 'Railway food support',
        ], 'railway');
        $this->assertDatabaseHas('beneficiaries', [
            'id' => 'resident-one',
            'name' => 'Railway resident',
        ], 'railway');
        $importedProgram = DB::connection('railway')->table('programs')->where('name', 'Food support')->first();
        $importedBeneficiary = DB::connection('railway')->table('beneficiaries')->where('name', 'Resident One')->first();
        $importedAssistance = DB::connection('railway')->table('assistance')->where('type', 'Cash assistance')->first();
        $importedApplication = DB::connection('railway')->table('assistance_applications')->first();
        $importedDistribution = DB::connection('railway')->table('distributions')->first();

        $this->assertNotNull($importedProgram);
        $this->assertNotNull($importedBeneficiary);
        $this->assertNotNull($importedAssistance);
        $this->assertNotNull($importedApplication);
        $this->assertNotNull($importedDistribution);
        $this->assertSame($importedProgram->id, $importedApplication->program_id);
        $this->assertSame($importedProgram->id, $importedDistribution->program_id);
        $this->assertSame((int) $importedApplication->user_id, (int) $importedDistribution->user_id);
        $this->assertSame((int) $importedApplication->id, (int) $importedDistribution->application_id);
        $this->assertSame($importedAssistance->id, $importedDistribution->assistance_id);
        $this->assertDatabaseHas('settings', [
            'setting_key' => 'barangayName',
            'setting_value' => 'Railway Barangay',
        ], 'railway');

        $this->artisan('barangaycare:import-wamp-data', [
            '--apply' => true,
            '--backup-confirmed' => true,
            '--confirm-database' => 'laravel',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('users', 2, 'railway');
        $this->assertDatabaseCount('programs', 2, 'railway');
        $this->assertDatabaseCount('beneficiaries', 2, 'railway');
        $this->assertDatabaseCount('assistance', 2, 'railway');
        $this->assertDatabaseCount('assistance_applications', 1, 'railway');
        $this->assertDatabaseCount('distributions', 1, 'railway');
        $this->assertDatabaseCount('settings', 1, 'railway');
    }

    public function test_apply_refuses_to_write_without_backup_and_target_confirmation(): void
    {
        DB::table('users')->insert($this->user('resident-one', 'Resident Hash'));

        $this->artisan('barangaycare:import-wamp-data', ['--apply' => true])
            ->expectsOutput('Writing requires --backup-confirmed and --confirm-database=laravel. No data was changed.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0, 'railway');
    }

    public function test_apply_refuses_to_import_proof_file_references_without_copying_storage(): void
    {
        $userId = DB::table('users')->insertGetId($this->user('resident-one', 'Resident Hash'));
        DB::table('programs')->insert($this->program('program-one', 'Food support'));
        $applicationId = DB::table('assistance_applications')->insertGetId([
            'user_id' => $userId,
            'beneficiary' => 'Resident One',
            'username' => 'resident-one',
            'program_id' => 'program-one',
            'program_name' => 'Food support',
            'benefit' => 'Food pack',
            'reason' => 'Household support',
            'status' => 'Submitted',
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);
        $proofRequestId = DB::table('assistance_application_proof_requests')->insertGetId([
            'application_id' => $applicationId,
            'requested_by' => 'staff-one',
            'note' => 'Please provide proof.',
            'status' => 'Requested',
            'requested_at' => '2026-10-01 09:00:00',
            'submitted_at' => null,
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);
        DB::table('assistance_application_proof_files')->insert([
            'proof_request_id' => $proofRequestId,
            'storage_path' => 'proofs/local-file.pdf',
            'original_name' => 'local-file.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'uploaded_at' => '2026-10-01 09:00:00',
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);

        $this->artisan('barangaycare:import-wamp-data', [
            '--apply' => true,
            '--backup-confirmed' => true,
            '--confirm-database' => 'laravel',
        ])
            ->expectsOutput('WAMP has proof-file records. Copy and verify the referenced storage files separately before importing; no data was changed.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0, 'railway');
        $this->assertDatabaseCount('assistance_application_proof_files', 0, 'railway');
    }

    private function user(string $username, string $password): array
    {
        return [
            'name' => ucfirst($username),
            'role' => $username === 'fred' ? 'Admin' : 'User',
            'beneficiary_name' => '',
            'username' => $username,
            'password' => $password,
            'status' => 'Active',
            'contact' => '',
            'email' => '',
            'position' => '',
            'address' => '',
            'profile_image' => null,
            'emergency_contact' => '',
            'must_change_password' => false,
        ];
    }

    private function program(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'benefit' => 'Food pack',
            'date' => '2026-10-01',
            'program_time' => null,
            'venue' => 'Barangay hall',
            'slots' => 10,
            'status' => 'Scheduled',
            'assigned_staff' => null,
            'instructions' => null,
            'release_amount' => null,
        ];
    }

    private function beneficiary(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'purok' => '1',
            'category' => 'Senior Citizen',
            'contact' => '',
            'status' => 'Active',
            'last_aid' => '',
            'updated' => '2026-10-01',
            'verification_status' => 'Pending',
            'verified_by' => null,
            'verified_at' => null,
            'possible_duplicate' => false,
        ];
    }

    private function assistance(string $id, string $type): array
    {
        return [
            'id' => $id,
            'beneficiary' => 'Resident One',
            'type' => $type,
            'amount' => 'One food pack',
            'date' => '2026-10-01',
            'status' => 'Completed',
            'staff' => 'staff-one',
            'claimed_at' => null,
            'claimed_by' => null,
        ];
    }
}
