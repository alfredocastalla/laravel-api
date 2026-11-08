<?php

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

#[Signature('barangaycare:import-wamp-data {--apply : Write the additive import to Railway} {--backup-confirmed : Confirm a Railway backup exists} {--confirm-database= : Must be set to laravel before writing}')]
#[Description('Merge local WAMP records into Railway without overwriting existing records')]
class ImportWampDataToRailway extends Command
{
    private const TABLES = [
        'users',
        'programs',
        'beneficiaries',
        'assistance',
        'assistance_applications',
        'assistance_application_proof_requests',
        'assistance_application_proof_files',
        'distributions',
        'resident_messages',
        'settings',
        'logs',
    ];

    /**
     * @var array<string, int>
     */
    private array $userIds = [];

    /**
     * @var array<string, string>
     */
    private array $programIds = [];

    /**
     * @var array<string, string>
     */
    private array $assistanceIds = [];

    /**
     * @var array<string, int>
     */
    private array $applicationIds = [];

    /**
     * @var array<string, array{inserted: int, matched: int}>
     */
    private array $results = [];

    public function handle(): int
    {
        if ($this->option('apply')
            && (! $this->option('backup-confirmed') || $this->option('confirm-database') !== 'laravel')) {
            $this->error('Writing requires --backup-confirmed and --confirm-database=laravel. No data was changed.');

            return self::FAILURE;
        }

        if (! app()->environment(['local', 'testing'])) {
            $this->error('Run this command from the local WAMP project, not from Railway or another production environment.');

            return self::FAILURE;
        }

        $sourceName = (string) config('database.default');
        if ($sourceName === 'railway') {
            $this->error('The default database cannot be the Railway target. Configure local WAMP as the default source.');

            return self::FAILURE;
        }

        $source = DB::connection($sourceName);
        $target = DB::connection('railway');

        if (! $this->connectionsAreSafe($sourceName, $source, $target)) {
            return self::FAILURE;
        }

        if (! $this->schemasMatch($sourceName)) {
            return self::FAILURE;
        }

        $sourceCounts = [];
        $targetCounts = [];
        foreach (self::TABLES as $table) {
            $sourceCounts[$table] = $source->table($table)->count();
            $targetCounts[$table] = $target->table($table)->count();
        }

        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[] = [$table, $sourceCounts[$table], $targetCounts[$table]];
        }
        $this->table(['Table', 'WAMP rows', 'Railway rows'], $rows);

        if ($sourceCounts['assistance_application_proof_files'] > 0) {
            $this->error('WAMP has proof-file records. Copy and verify the referenced storage files separately before importing; no data was changed.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Read-only dry run complete. No data was changed.');
            $this->line('After taking a Railway backup and verifying the counts, rerun with --apply --backup-confirmed --confirm-database=laravel.');

            return self::SUCCESS;
        }

        $target->transaction(function () use ($source, $target): void {
            $this->importUsers($source, $target);

            $this->programIds = $this->importStringRows(
                $source,
                $target,
                'programs',
                fn (array $row): array => $row,
            );
            $this->importStringRows(
                $source,
                $target,
                'beneficiaries',
                function (array $row): array {
                    if ($row['verified_by'] !== null) {
                        $row['verified_by'] = $this->mappedId($this->userIds, $row['verified_by'], 'beneficiaries');
                    }

                    return $row;
                },
            );
            $this->assistanceIds = $this->importStringRows(
                $source,
                $target,
                'assistance',
                fn (array $row): array => $row,
            );
            $this->applicationIds = $this->importAutoRows(
                $source,
                $target,
                'assistance_applications',
                function (array $row): array {
                    $row['user_id'] = $this->mappedId($this->userIds, $row['user_id'], 'assistance_applications');
                    $row['program_id'] = $this->mappedStringId($this->programIds, $row['program_id'], 'assistance_applications');

                    return $row;
                },
            );
            $this->importAutoRows(
                $source,
                $target,
                'assistance_application_proof_requests',
                function (array $row): array {
                    $row['application_id'] = $this->mappedId(
                        $this->applicationIds,
                        $row['application_id'],
                        'assistance_application_proof_requests',
                    );

                    return $row;
                },
                ['application_id'],
            );
            $this->importAutoRows(
                $source,
                $target,
                'distributions',
                function (array $row): array {
                    if ($row['application_id'] !== null) {
                        $row['application_id'] = $this->mappedId($this->applicationIds, $row['application_id'], 'distributions');
                    }
                    if ($row['assistance_id'] !== null) {
                        $row['assistance_id'] = $this->mappedStringId($this->assistanceIds, $row['assistance_id'], 'distributions');
                    }
                    if ($row['user_id'] !== null) {
                        $row['user_id'] = $this->mappedId($this->userIds, $row['user_id'], 'distributions');
                    }
                    if ($row['program_id'] !== null) {
                        $row['program_id'] = $this->mappedStringId($this->programIds, $row['program_id'], 'distributions');
                    }

                    return $row;
                },
                ['application_id', 'assistance_id'],
            );
            $this->importAutoRows(
                $source,
                $target,
                'resident_messages',
                function (array $row): array {
                    $row['user_id'] = $this->mappedId($this->userIds, $row['user_id'], 'resident_messages');

                    return $row;
                },
            );
            $this->importSettings($source, $target);
            $this->importAutoRows($source, $target, 'logs', fn (array $row): array => $row);
        });

        foreach ($this->results as $table => $result) {
            $this->line(sprintf(
                '%s: %d inserted, %d already present or preserved.',
                $table,
                $result['inserted'],
                $result['matched'],
            ));
        }

        $this->info('The additive import completed successfully. Existing Railway records and credentials were preserved.');

        return self::SUCCESS;
    }

    private function connectionsAreSafe(string $sourceName, Connection $source, Connection $target): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        if ($source->getDriverName() !== 'mysql'
            || $source->getDatabaseName() !== 'barangaycare_db'
            || $target->getDriverName() !== 'mysql'
            || $target->getDatabaseName() !== 'laravel') {
            $this->error('Expected local MySQL database [barangaycare_db] as source and Railway MySQL database [laravel] as target. No data was changed.');

            return false;
        }

        $sourceConfig = config("database.connections.{$sourceName}");
        $targetConfig = config('database.connections.railway');
        $sourceHost = strtolower((string) ($sourceConfig['host'] ?? ''));
        $targetHost = strtolower((string) parse_url((string) ($targetConfig['url'] ?? ''), PHP_URL_HOST));
        $targetHost = $targetHost !== '' ? $targetHost : strtolower((string) ($targetConfig['host'] ?? ''));
        $targetPort = (string) ($targetConfig['port'] ?? '');
        $sourcePort = (string) ($sourceConfig['port'] ?? '');

        if ($targetHost === ''
            || in_array($targetHost, ['localhost', '127.0.0.1', '::1'], true)
            || ($sourceHost === $targetHost && $sourcePort === $targetPort)) {
            $this->error('Configure the Railway public MySQL host and port in RAILWAY_DB_*; the target must not be the local WAMP database.');

            return false;
        }

        return true;
    }

    private function schemasMatch(string $sourceName): bool
    {
        $sourceSchema = Schema::connection($sourceName);
        $targetSchema = Schema::connection('railway');

        foreach (self::TABLES as $table) {
            if (! $sourceSchema->hasTable($table) || ! $targetSchema->hasTable($table)) {
                $this->error("Required table [{$table}] is missing from one database. No data was changed.");

                return false;
            }

            $sourceColumns = $sourceSchema->getColumnListing($table);
            $targetColumns = $targetSchema->getColumnListing($table);
            sort($sourceColumns);
            sort($targetColumns);

            if ($sourceColumns !== $targetColumns) {
                $this->error("Table [{$table}] has different source and target columns. Update the Railway schema before importing; no data was changed.");

                return false;
            }
        }

        return true;
    }

    private function importUsers(Connection $source, Connection $target): void
    {
        foreach ($source->table('users')->orderBy('id')->get() as $user) {
            $row = (array) $user;
            $sourceId = (string) $row['id'];
            $username = mb_strtolower(trim((string) $row['username']));
            if ($username === '') {
                throw new RuntimeException('A source account has no username; the import was rolled back.');
            }

            $existing = $target->table('users')
                ->whereRaw('LOWER(username) = ?', [$username])
                ->first();

            if ($existing !== null) {
                $this->userIds[$sourceId] = (int) $existing->id;
                $this->record('users', 'matched');

                continue;
            }

            $sourceUserId = $row['id'];
            if ($target->table('users')->where('id', $sourceUserId)->exists()) {
                unset($row['id']);
                $targetUserId = (int) $target->table('users')->insertGetId($row);
            } else {
                $target->table('users')->insert($row);
                $targetUserId = (int) $sourceUserId;
            }

            $this->userIds[$sourceId] = $targetUserId;
            $this->record('users', 'inserted');
        }
    }

    /**
     * @return array<string, string>
     */
    private function importStringRows(Connection $source, Connection $target, string $table, Closure $transform): array
    {
        $ids = [];

        foreach ($source->table($table)->orderBy('id')->get() as $record) {
            $row = $transform((array) $record);
            $sourceId = (string) $row['id'];
            $existing = $target->table($table)->where('id', $sourceId)->first();

            if ($existing !== null && $this->rowMatches($existing, $row)) {
                $ids[$sourceId] = $sourceId;
                $this->record($table, 'matched');

                continue;
            }

            if ($existing !== null) {
                $row['id'] = 'wamp-'.substr(hash('sha256', $table.'|'.$sourceId), 0, 35);
                $existing = $target->table($table)->where('id', $row['id'])->first();

                if ($existing !== null) {
                    if (! $this->rowMatches($existing, $row)) {
                        throw new RuntimeException("A deterministic identifier conflict occurred in [{$table}]; the import was rolled back.");
                    }

                    $ids[$sourceId] = (string) $row['id'];
                    $this->record($table, 'matched');

                    continue;
                }
            }

            $target->table($table)->insert($row);
            $ids[$sourceId] = (string) $row['id'];
            $this->record($table, 'inserted');
        }

        return $ids;
    }

    /**
     * @param  list<string>  $uniqueColumns
     * @return array<string, int>
     */
    private function importAutoRows(
        Connection $source,
        Connection $target,
        string $table,
        Closure $transform,
        array $uniqueColumns = [],
    ): array {
        $ids = [];

        foreach ($source->table($table)->orderBy('id')->get() as $record) {
            $row = $transform((array) $record);
            $sourceId = (string) $row['id'];
            unset($row['id']);

            $existing = $this->findMatchingRow($target, $table, $row);
            if ($existing !== null) {
                $ids[$sourceId] = (int) $existing->id;
                $this->record($table, 'matched');

                continue;
            }

            foreach ($uniqueColumns as $column) {
                if (($row[$column] ?? null) !== null) {
                    $conflict = $target->table($table)->where($column, $row[$column])->exists();
                    if ($conflict) {
                        throw new RuntimeException("A unique relationship conflicts in [{$table}]; the import was rolled back.");
                    }
                }
            }

            $ids[$sourceId] = (int) $target->table($table)->insertGetId($row);
            $this->record($table, 'inserted');
        }

        return $ids;
    }

    private function importSettings(Connection $source, Connection $target): void
    {
        foreach ($source->table('settings')->orderBy('setting_key')->get() as $setting) {
            $row = (array) $setting;
            if ($target->table('settings')->where('setting_key', $row['setting_key'])->exists()) {
                $this->record('settings', 'matched');

                continue;
            }

            $target->table('settings')->insert($row);
            $this->record('settings', 'inserted');
        }
    }

    private function findMatchingRow(Connection $connection, string $table, array $row): ?object
    {
        $query = $connection->table($table);
        foreach ($row as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }

        return $query->first();
    }

    private function rowMatches(object $existing, array $expected): bool
    {
        foreach ($expected as $column => $value) {
            $actual = $existing->{$column};
            if ($value === null ? $actual !== null : (string) $actual !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function mappedId(array $map, mixed $sourceId, string $table): int
    {
        $key = (string) $sourceId;
        if (! array_key_exists($key, $map)) {
            throw new RuntimeException("A related source record is missing for [{$table}]; the import was rolled back.");
        }

        return (int) $map[$key];
    }

    private function mappedStringId(array $map, mixed $sourceId, string $table): string
    {
        $key = (string) $sourceId;
        if (! array_key_exists($key, $map)) {
            throw new RuntimeException("A related source record is missing for [{$table}]; the import was rolled back.");
        }

        return (string) $map[$key];
    }

    private function record(string $table, string $result): void
    {
        $this->results[$table] ??= ['inserted' => 0, 'matched' => 0];
        $this->results[$table][$result]++;
    }
}
