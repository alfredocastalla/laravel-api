<?php

namespace App\Services;

use App\Support\AccountRole;
use App\Support\BenefitCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BarangayCareStore
{
    // #region Read models and role-scoped data projections
    public function readState(?object $actor = null): array
    {
        $normalizedRole = $actor ? ($actor->normalized_role ?? AccountRole::normalize((string) $actor->role)) : null;
        $residentName = $normalizedRole === 'user'
            ? mb_strtolower(trim((string) ($actor->beneficiary_name ?: $actor->name)))
            : null;
        $beneficiariesQuery = DB::table('beneficiaries')->select(
            'id',
            'name',
            'purok',
            'category',
            'contact',
            'status',
            'last_aid as lastAid',
            'updated',
            'verification_status as verificationStatus',
            'verified_at as verifiedAt',
            'possible_duplicate as possibleDuplicate'
        );
        if ($normalizedRole === 'user') {
            $beneficiariesQuery->whereRaw('LOWER(TRIM(name)) = ?', [$residentName]);
        }
        $beneficiaries = $beneficiariesQuery->orderBy('id')->get()->map(function ($row): array {
            $beneficiary = (array) $row;
            $beneficiary['possibleDuplicate'] = (bool) $beneficiary['possibleDuplicate'];

            return $beneficiary;
        })->all();

        $programsQuery = DB::table('programs')->select('id', 'name', 'benefit', 'date', 'program_time as time', 'venue', 'slots', 'status', 'assigned_staff as assignedStaff', 'instructions', 'release_amount as releaseAmount');
        if ($normalizedRole === 'user') {
            $programsQuery->whereDate('date', '>=', now()->toDateString())
                ->whereIn(DB::raw('LOWER(status)'), ['scheduled', 'open']);
        }
        $programs = $programsQuery->orderBy('date')->orderBy('program_time')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        if ($normalizedRole === 'user') {
            $residentCategories = (string) ($beneficiaries[0]['category'] ?? '');
            $programs = array_values(array_filter(
                $programs,
                fn (array $program): bool => BenefitCategory::matches(
                    $residentCategories,
                    (string) ($program['benefit'] ?? '')
                )
            ));
        }

        $assistanceQuery = DB::table('assistance')->select('id', 'beneficiary', 'type', 'amount', 'date', 'status', 'staff', 'claimed_at as claimedAt', 'claimed_by as claimedBy');
        if ($normalizedRole === 'user') {
            $assistanceQuery->whereRaw('LOWER(TRIM(beneficiary)) = ?', [$residentName])
                ->where('amount', '<>', 'As released');
        }
        $assistance = $assistanceQuery->orderByDesc('id')->get()->map(fn ($row) => (array) $row)->all();

        $usersQuery = DB::table('users')->select('id', 'name', 'role', 'beneficiary_name as beneficiaryName', 'username', 'status', 'contact', 'email', 'position', 'address', 'profile_image as profileImage', 'emergency_contact as emergencyContact');
        if ($normalizedRole === 'user') {
            $usersQuery->where('id', $actor->id);
        }
        $users = $usersQuery->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $actorRoles = [];
        foreach ($users as $user) {
            $userRole = AccountRole::normalize((string) ($user['role'] ?? ''));
            if ($userRole === null) {
                continue;
            }

            foreach ([$user['name'] ?? '', $user['username'] ?? ''] as $identity) {
                $identity = mb_strtolower(trim((string) $identity));
                if ($identity !== '') {
                    $actorRoles[$identity] = $userRole;
                }
            }
        }
        $logsQuery = DB::table('logs')->select('event_time as time', 'actor', 'action');
        if ($normalizedRole === 'user') {
            $logsQuery->whereRaw('1 = 0');
        }
        $logs = $logsQuery
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(function ($row) use ($actorRoles): array {
                $log = (array) $row;
                $actor = mb_strtolower(trim((string) ($log['actor'] ?? '')));
                $role = AccountRole::normalize($actor);

                if ($role === null && in_array($actor, ['super administrator', 'super administrator account'], true)) {
                    $role = 'admin';
                }

                $log['actorRole'] = $role ?? ($actorRoles[$actor] ?? null);

                return $log;
            })
            ->all();
        $messagesQuery = Schema::hasTable('resident_messages')
            ? DB::table('resident_messages')
                ->select('id', 'user_id as userId', 'resident_name as name', 'username', 'kind', 'body', 'from_role as from', 'staff_name as staffName', 'staff_username as staffUsername', 'created_at as time')
            : null;
        if ($normalizedRole === 'user' && $messagesQuery) {
            $messagesQuery->where(function ($query) use ($actor): void {
                $query->where('user_id', $actor->id)
                    ->orWhere('username', $actor->username);
            });
        }
        $messages = $messagesQuery
            ? $messagesQuery
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'userId' => (int) $row->userId,
                    'name' => $row->name,
                    'username' => $row->username,
                    'kind' => $row->kind,
                    'body' => $row->body,
                    'from' => $row->from,
                    'staffName' => $row->staffName ?? '',
                    'staffUsername' => $row->staffUsername ?? '',
                    'time' => (string) $row->time,
                ])
                ->all()
            : [];
        $applicationsQuery = DB::table('assistance_applications')
            ->select('id', 'user_id as userId', 'beneficiary', 'username', 'program_id as programId', 'program_name as programName', 'benefit', 'reason', 'status', 'reviewed_by as reviewedBy', 'reviewed_at as reviewedAt', 'review_note as reviewNote', 'created_at as submittedAt');
        if ($normalizedRole === 'user') {
            $applicationsQuery->where('user_id', $actor->id);
        }
        $applications = $applicationsQuery
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'userId' => (int) $row->userId,
                'beneficiary' => $row->beneficiary,
                'username' => $row->username,
                'programId' => $row->programId,
                'programName' => $row->programName,
                'benefit' => $row->benefit,
                'reason' => $row->reason,
                'status' => $row->status,
                'reviewedBy' => $row->reviewedBy,
                'reviewedAt' => $row->reviewedAt,
                'reviewNote' => $row->reviewNote,
                'submittedAt' => $row->submittedAt,
            ])
            ->all();
        if (in_array($normalizedRole, ['user', 'assistance_officer', 'admin'], true) && $applications !== []) {
            $proofRequests = DB::table('assistance_application_proof_requests')
                ->whereIn('application_id', array_column($applications, 'id'))
                ->get()
                ->keyBy('application_id');
            $proofFiles = DB::table('assistance_application_proof_files')
                ->whereIn('proof_request_id', $proofRequests->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('proof_request_id');

            foreach ($applications as &$application) {
                if (in_array($application['status'], ['Rejected', 'Distributed'], true)) {
                    continue;
                }

                $proofRequest = $proofRequests->get($application['id']);
                if (! $proofRequest) {
                    continue;
                }

                $files = $proofFiles->get($proofRequest->id, collect());
                $application['proofRequest'] = [
                    'status' => $proofRequest->status,
                    'note' => $proofRequest->note,
                    'requestedAt' => $proofRequest->requested_at,
                    'submittedAt' => $proofRequest->submitted_at,
                    'fileCount' => $files->count(),
                ];
                if (in_array($normalizedRole, ['assistance_officer', 'admin'], true)) {
                    $application['proofRequest']['files'] = $files->map(fn (object $file): array => [
                        'id' => (int) $file->id,
                        'name' => $file->original_name,
                        'mimeType' => $file->mime_type,
                        'size' => (int) $file->size,
                        'uploadedAt' => $file->uploaded_at,
                    ])->all();
                }
            }
            unset($application);
        }
        $distributionsQuery = DB::table('distributions')
            ->select('id', 'application_id as applicationId', 'assistance_id as assistanceId', 'program_id as programId', 'user_id as userId', 'beneficiary', 'benefit', 'amount', 'distributed_by as distributedBy', 'distributed_at as distributedAt');
        if ($normalizedRole === 'user') {
            $distributionsQuery->where('user_id', $actor->id);
        }
        $distributions = $distributionsQuery
            ->orderByDesc('distributed_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'applicationId' => $row->applicationId === null ? null : (int) $row->applicationId,
                'assistanceId' => $row->assistanceId,
                'programId' => $row->programId,
                'userId' => $row->userId === null ? null : (int) $row->userId,
                'beneficiary' => $row->beneficiary,
                'benefit' => $row->benefit,
                'amount' => $row->amount,
                'distributedBy' => $row->distributedBy,
                'distributedAt' => $row->distributedAt,
            ])
            ->all();

        $settings = [];
        foreach (DB::table('settings')->select('setting_key', 'setting_value')->get() as $setting) {
            $decoded = json_decode($setting->setting_value, true);
            $settings[$setting->setting_key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->setting_value;
        }

        return compact('beneficiaries', 'programs', 'assistance', 'users', 'logs', 'settings', 'messages', 'applications', 'distributions');
    }

    public function readStateFor(object $actor): array
    {
        $state = $this->readState($actor);
        $role = $actor->normalized_role ?? AccountRole::normalize((string) $actor->role);

        if ($role === 'admin') {
            return $state;
        }

        if ($role === 'barangay') {
            $today = now()->toDateString();
            $recentActivityStart = now()->subDays(29)->toDateString();
            $accounts = array_values(array_filter(
                $state['users'],
                fn (array $user): bool => in_array(
                    AccountRole::normalize((string) ($user['role'] ?? '')),
                    ['user', 'secretary', 'assistance_officer', 'distribution_staff', 'auditor'],
                    true
                )
            ));
            $upcomingSchedules = array_values(array_filter(
                $state['programs'],
                fn (array $program): bool => ($program['date'] ?? '') >= $today
                    && ! in_array(strtolower((string) ($program['status'] ?? '')), ['completed', 'cancelled'], true)
            ));
            $state['monitoring'] = [
                'accounts' => count($accounts),
                'activeAccounts' => count(array_filter($accounts, fn (array $user): bool => strcasecmp((string) ($user['status'] ?? ''), 'Active') === 0)),
                'residentAccounts' => count(array_filter($accounts, fn (array $user): bool => AccountRole::normalize((string) ($user['role'] ?? '')) === 'user')),
                'staffAccounts' => count(array_filter($accounts, fn (array $user): bool => AccountRole::normalize((string) ($user['role'] ?? '')) !== 'user')),
                'upcomingSchedules' => array_map(fn (array $program): array => [
                    'name' => (string) ($program['name'] ?? ''),
                    'date' => (string) ($program['date'] ?? ''),
                    'time' => (string) ($program['time'] ?? ''),
                    'venue' => (string) ($program['venue'] ?? ''),
                ], array_slice($upcomingSchedules, 0, 3)),
                'services' => [
                    'residentProfiles' => count($state['beneficiaries']),
                    'activeResidentProfiles' => count(array_filter(
                        $state['beneficiaries'],
                        fn (array $beneficiary): bool => strcasecmp((string) ($beneficiary['status'] ?? ''), 'Active') === 0
                    )),
                    'assistanceAssignments' => count($state['assistance']),
                    'awaitingHandover' => count(array_filter(
                        $state['assistance'],
                        fn (array $record): bool => in_array($record['status'] ?? '', ['Pending', 'Completed'], true)
                    )),
                    'completedHandovers' => count($state['distributions']),
                    'recentHandovers' => count(array_filter(
                        $state['distributions'],
                        fn (array $distribution): bool => substr((string) ($distribution['distributedAt'] ?? ''), 0, 10) >= $recentActivityStart
                            && substr((string) ($distribution['distributedAt'] ?? ''), 0, 10) <= $today
                    )),
                    'upcomingPrograms' => count(array_filter(
                        $state['programs'],
                        fn (array $program): bool => ($program['date'] ?? '') >= $today
                            && ! in_array(strtolower((string) ($program['status'] ?? '')), ['completed', 'cancelled'], true)
                    )),
                ],
            ];
            $state['users'] = array_map(fn (array $user): array => [
                'id' => $user['id'],
                'name' => $user['name'],
                'role' => $user['role'],
                'status' => $user['status'],
            ], $accounts);
            $state['beneficiaries'] = [];
            $state['programs'] = [];
            $state['assistance'] = [];
            $state['logs'] = [];
            $state['messages'] = [];
            $state['applications'] = [];
            $state['distributions'] = [];
            $state['settings'] = $this->officeSettings($state['settings'] ?? []);

            return $state;
        }

        if (in_array($role, ['secretary', 'assistance_officer', 'distribution_staff', 'auditor'], true)) {
            $state['users'] = in_array($role, ['secretary', 'assistance_officer', 'distribution_staff'], true)
                ? array_values(array_filter(
                    $state['users'],
                    fn (array $user): bool => AccountRole::normalize((string) ($user['role'] ?? '')) === 'user'
                        || (int) ($user['id'] ?? 0) === (int) $actor->id
                ))
                : array_values(array_filter(
                    $state['users'],
                    fn (array $user): bool => (int) ($user['id'] ?? 0) === (int) $actor->id
                ));
            $state['logs'] = $role === 'auditor' ? $state['logs'] : [];
            $state['settings'] = $this->officeSettings($state['settings'] ?? []);
            $state['messages'] = [];
            $state['applications'] = match ($role) {
                'assistance_officer' => $state['applications'],
                'distribution_staff' => array_map(function (array $application): array {
                    unset($application['reason'], $application['reviewNote'], $application['username'], $application['userId']);

                    return $application;
                }, $state['applications']),
                default => [],
            };
            $state['distributions'] = in_array($role, ['secretary', 'assistance_officer', 'distribution_staff', 'auditor'], true)
                ? $state['distributions']
                : [];

            if (in_array($role, ['distribution_staff', 'auditor'], true)) {
                $state['beneficiaries'] = array_map(function (array $beneficiary): array {
                    unset($beneficiary['contact']);

                    return $beneficiary;
                }, $state['beneficiaries']);
            }

            return $state;
        }

        $linkedName = mb_strtolower(trim((string) ($actor->beneficiary_name ?: $actor->name)));
        $username = strtolower((string) $actor->username);
        $state['beneficiaries'] = array_values(array_filter(
            $state['beneficiaries'],
            fn (array $beneficiary): bool => mb_strtolower(trim((string) $beneficiary['name'])) === $linkedName
        ));
        $state['assistance'] = array_values(array_filter(
            $state['assistance'],
            fn (array $record): bool => mb_strtolower(trim((string) $record['beneficiary'])) === $linkedName
        ));
        $state['users'] = array_values(array_filter(
            $state['users'],
            fn (array $user): bool => strtolower((string) ($user['username'] ?? '')) === $username
        ));
        $state['logs'] = [];
        $state['settings'] = $this->officeSettings($state['settings'] ?? []);
        $state['messages'] = array_values(array_filter(
            $state['messages'] ?? [],
            fn (array $message): bool => (int) ($message['userId'] ?? 0) === (int) $actor->id
                || strtolower((string) ($message['username'] ?? '')) === strtolower((string) $actor->username)
        ));
        $state['applications'] = array_values(array_filter(
            $state['applications'],
            fn (array $application): bool => (int) $application['userId'] === (int) $actor->id
        ));
        $state['distributions'] = array_values(array_filter(
            $state['distributions'],
            fn (array $distribution): bool => (int) ($distribution['userId'] ?? 0) === (int) $actor->id
        ));
        $state['beneficiaries'] = array_map(function (array $beneficiary): array {
            unset($beneficiary['verificationStatus'], $beneficiary['verifiedAt'], $beneficiary['possibleDuplicate']);

            return $beneficiary;
        }, $state['beneficiaries']);

        return $state;
    }

    public function publicSummary(): array
    {
        $state = $this->readState();
        $publicPrograms = array_values(array_filter(
            $state['programs'],
            fn (array $program): bool => BenefitCategory::matches(
                null,
                (string) ($program['benefit'] ?? '')
            )
        ));

        return [
            'beneficiaries' => [],
            'programCount' => count($state['programs']),
            'programs' => array_map(fn (array $program): array => [
                'id' => $program['id'],
                'name' => $program['name'],
                'benefit' => $program['benefit'] ?? 'All residents',
                'date' => $program['date'],
                'time' => $program['time'],
                'venue' => $program['venue'],
                'slots' => $program['slots'],
                'status' => $program['status'],
                'assignedStaff' => $program['assignedStaff'],
                'instructions' => $program['instructions'],
                'releaseAmount' => $program['releaseAmount'],
            ], $publicPrograms),
            'assistance' => [],
            'applications' => [],
            'distributions' => [],
            'users' => [],
            'logs' => [],
            'settings' => $this->officeSettings($state['settings'] ?? []),
            'meta' => [
                'beneficiaries' => count($state['beneficiaries']),
                'assistance' => count($state['assistance']),
                'programs' => count($state['programs']),
            ],
        ];
    }

    // #endregion
    // #region State writes, account management, and backup restoration
    public function writeState(array $state, ?object $actor = null): array
    {
        return DB::transaction(function () use ($state, $actor): array {
            $role = $actor
                ? ($actor->normalized_role ?? AccountRole::normalize((string) $actor->role))
                : null;
            if ($actor && $role !== 'secretary') {
                throw new InvalidArgumentException('Only Secretary staff can manage resident assistance assignments.');
            }
            $writeTables = null;
            $auditAction = null;

            $state['users'] ??= [];
            $state['assistance'] ??= [];
            $state['programs'] ??= [];
            $state['logs'] ??= [];
            $state['settings'] ??= [];

            if ($actor && $role === 'secretary') {
                $current = $this->readState();
                $state['beneficiaries'] = is_array($state['beneficiaries'] ?? null)
                    ? $state['beneficiaries']
                    : $current['beneficiaries'];
                $state['programs'] = is_array($state['programs'] ?? null)
                    ? $state['programs']
                    : $current['programs'];
                $state['assistance'] = is_array($state['assistance'] ?? null)
                    ? $state['assistance']
                    : $current['assistance'];
                $state['users'] = $current['users'];
                $state['settings'] = $current['settings'];
                $state['logs'] = $current['logs'];

                $state['assistance'] = $this->ensureIds($state['assistance'], 'A');
                $submittedAssistance = [];
                foreach (is_array($state['assistance']) ? $state['assistance'] : [] as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $submittedAssistance[(string) $item['id']] = $item;
                    }
                }
                $currentAssistance = [];
                foreach ($current['assistance'] as $record) {
                    $submitted = $submittedAssistance[(string) $record['id']] ?? null;
                    if ($record['status'] !== 'Claimed' && ! is_array($submitted)) {
                        continue;
                    }

                    if ($record['status'] !== 'Claimed' && array_key_exists('amount', $submitted)) {
                        if (! is_string($submitted['amount'])) {
                            throw new InvalidArgumentException('Resident assistance amount or items must be text.');
                        }
                        $amount = trim($submitted['amount']);
                        if (mb_strlen($amount) > 120) {
                            throw new InvalidArgumentException('Resident assistance amount or items must be 120 characters or fewer.');
                        }
                        $record['amount'] = $amount;
                    }

                    if ($record['status'] !== 'Claimed' && array_key_exists('date', $submitted)) {
                        $record['date'] = $submitted['date'];
                    }

                    $currentAssistance[] = $record;
                }

                $currentAssistanceIds = array_fill_keys(array_column($current['assistance'], 'id'), true);
                $beneficiaryNames = array_map(
                    fn (array $beneficiary): string => mb_strtolower(trim((string) $beneficiary['name'])),
                    $state['beneficiaries']
                );
                foreach ($state['assistance'] as $item) {
                    if (isset($currentAssistanceIds[(string) $item['id']])) {
                        continue;
                    }

                    $beneficiaryName = trim((string) ($item['beneficiary'] ?? ''));
                    $type = trim((string) ($item['type'] ?? ''));
                    $status = trim((string) ($item['status'] ?? 'Pending'));
                    if ($beneficiaryName === '' || ! in_array(mb_strtolower($beneficiaryName), $beneficiaryNames, true)) {
                        throw new InvalidArgumentException('Choose a registered beneficiary for the assistance assignment.');
                    }
                    if ($type === '') {
                        throw new InvalidArgumentException('Choose an assistance type for the assignment.');
                    }
                    if (! in_array($status, ['Pending', 'Completed'], true)) {
                        throw new InvalidArgumentException('Only Distribution Staff can confirm a completed handover.');
                    }
                    $amount = $item['amount'] ?? '';
                    if (! is_string($amount)) {
                        throw new InvalidArgumentException('Resident assistance amount or items must be text.');
                    }

                    $item['date'] = $this->validatedAssistanceDate($item['date'] ?? null);
                    $item['beneficiary'] = $beneficiaryName;
                    $item['type'] = $type;
                    $item['status'] = $status;
                    $item['amount'] = trim($amount);
                    $item['claimedAt'] = null;
                    $item['claimedBy'] = null;
                    $currentAssistance[] = $item;
                }
                $state['assistance'] = $currentAssistance;
                $state['messages'] = $current['messages'] ?? [];
                $writeTables = ['beneficiaries', 'programs', 'assistance'];
                $auditAction = 'Updated beneficiary or schedule records';
            }

            if ($actor && $state['users'] === []) {
                throw new InvalidArgumentException('Refusing to save an empty account list.');
            }

            $state['beneficiaries'] = $this->ensureIds($state['beneficiaries'] ?? [], 'B');
            $state['programs'] = $this->ensureIds($state['programs'] ?? [], 'P');
            foreach ($state['programs'] as &$program) {
                $status = trim((string) ($program['status'] ?? 'Scheduled'));
                if (! in_array($status, ['Scheduled', 'Open', 'Completed'], true)) {
                    throw new InvalidArgumentException('Choose a supported program status.');
                }

                $program['status'] = $status;
            }
            unset($program);
            $state['assistance'] = $this->ensureIds($state['assistance'] ?? [], 'A');
            $this->assertBeneficiariesCanBeRemoved($state);
            $state = $this->applyBeneficiaryRenames($state);
            $linked = $role === 'admin'
                ? $this->linkCategoriesToAssistance($state['beneficiaries'], $state['assistance'], $actor)
                : ['beneficiaries' => $state['beneficiaries'], 'assistance' => $state['assistance']];
            $state['beneficiaries'] = $linked['beneficiaries'];
            $state['assistance'] = $this->ensureIds($linked['assistance'], 'A');
            $this->replaceOperationalTables($state, $writeTables, $actor);
            if ($writeTables === null || in_array('beneficiaries', $writeTables, true)) {
                $this->refreshPossibleDuplicateFlags();
            }
            if ($actor && $auditAction !== null) {
                $this->log((string) ($actor->name ?: $actor->username), $auditAction);
            }
            if ($actor === null) {
                $this->syncUsers($state['users'] ?? [], $actor);
            }
            $this->storeSnapshot();

            return $this->readState();
        });
    }

    public function updateResidentAccount(object $actor, int $residentId, array $data): array
    {
        $role = $actor->normalized_role ?? AccountRole::normalize((string) $actor->role);
        if (! in_array($role, ['secretary', 'assistance_officer', 'distribution_staff'], true)) {
            throw new InvalidArgumentException('You do not have permission to edit resident accounts.');
        }

        return DB::transaction(function () use ($actor, $residentId, $data): array {
            $resident = DB::table('users')->where('id', $residentId)->lockForUpdate()->first();
            if (! $resident || AccountRole::normalize((string) $resident->role) !== 'user') {
                throw new InvalidArgumentException('Choose a registered resident account.');
            }

            $username = strtolower(trim((string) $data['username']));
            if (DB::table('users')
                ->whereRaw('LOWER(username) = ?', [$username])
                ->where('id', '<>', $residentId)
                ->exists()) {
                throw new InvalidArgumentException('That username is already taken. Please choose another.');
            }

            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email !== '' && DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('id', '<>', $residentId)
                ->exists()) {
                throw new InvalidArgumentException('That email address is already in use.');
            }

            $updates = [
                'name' => trim((string) $data['name']),
                'username' => $username,
                'email' => $email,
                'contact' => trim((string) ($data['contact'] ?? '')),
                'address' => trim((string) ($data['address'] ?? '')),
                'status' => $data['status'],
            ];
            $password = trim((string) ($data['password'] ?? ''));
            if ($password !== '') {
                $updates['password'] = Hash::make($password);
            }

            DB::table('users')->where('id', $residentId)->update($updates);
            if (Schema::hasTable('resident_messages')) {
                DB::table('resident_messages')->where('user_id', $residentId)->update([
                    'resident_name' => $updates['name'],
                    'username' => $username,
                ]);
            }
            if (Schema::hasTable('assistance_applications')) {
                DB::table('assistance_applications')->where('user_id', $residentId)->update([
                    'username' => $username,
                ]);
            }

            $this->log((string) ($actor->name ?: $actor->username), "Updated resident account {$username}");
            $this->bumpVersion();

            return [
                'id' => $residentId,
                'name' => $updates['name'],
                'role' => 'user',
                'beneficiaryName' => (string) $resident->beneficiary_name,
                'username' => $username,
                'status' => $updates['status'],
                'contact' => $updates['contact'],
                'email' => $email,
                'address' => $updates['address'],
            ];
        });
    }

    public function updateStaffAccountStatus(object $actor, int $staffId, string $status): array
    {
        $role = $actor->normalized_role ?? AccountRole::normalize((string) $actor->role);
        if ($role !== 'barangay') {
            throw new InvalidArgumentException('You do not have permission to change staff account status.');
        }

        return DB::transaction(function () use ($actor, $staffId, $status): array {
            $staff = DB::table('users')->where('id', $staffId)->lockForUpdate()->first();
            if (! $staff || ! in_array(AccountRole::normalize((string) $staff->role), ['secretary', 'assistance_officer', 'distribution_staff'], true)) {
                throw new InvalidArgumentException('Choose a Secretary, Assistance Officer, or Distribution Staff account.');
            }

            if ($staff->status !== $status) {
                DB::table('users')->where('id', $staffId)->update(['status' => $status]);
                DB::table('auth_tokens')->where('user_id', $staffId)->delete();
                $this->log(
                    (string) ($actor->name ?: $actor->username),
                    "{$status} {$staff->role} account {$staff->username}"
                );
                $this->bumpVersion();
            }

            return [
                'id' => (int) $staff->id,
                'name' => (string) $staff->name,
                'role' => AccountRole::normalize((string) $staff->role),
                'status' => $status,
            ];
        });
    }

    public function resetStaffAccountPassword(object $actor, int $staffId, string $password): array
    {
        $role = $actor->normalized_role ?? AccountRole::normalize((string) $actor->role);
        if ($role !== 'barangay') {
            throw new InvalidArgumentException('You do not have permission to reset staff account passwords.');
        }

        return DB::transaction(function () use ($actor, $password, $staffId): array {
            $staff = DB::table('users')->where('id', $staffId)->lockForUpdate()->first();
            if (! $staff || ! in_array(AccountRole::normalize((string) $staff->role), ['secretary', 'assistance_officer', 'distribution_staff'], true)) {
                throw new InvalidArgumentException('Choose a Secretary, Assistance Officer, or Distribution Staff account.');
            }

            DB::table('users')->where('id', $staffId)->update([
                'password' => Hash::make($password),
                'must_change_password' => true,
            ]);
            DB::table('auth_tokens')->where('user_id', $staffId)->delete();
            $this->log(
                (string) ($actor->name ?: $actor->username),
                "Reset the password for {$staff->role} account {$staff->username}"
            );
            $this->bumpVersion();

            return [
                'id' => (int) $staff->id,
                'username' => (string) $staff->username,
                'role' => AccountRole::normalize((string) $staff->role),
            ];
        });
    }

    public function deleteStaffAccount(object $actor, int $staffId): array
    {
        $role = $actor->normalized_role ?? AccountRole::normalize((string) $actor->role);
        if ($role !== 'barangay') {
            throw new InvalidArgumentException('You do not have permission to delete staff accounts.');
        }

        return DB::transaction(function () use ($actor, $staffId): array {
            $staff = DB::table('users')->where('id', $staffId)->lockForUpdate()->first();
            if (! $staff || ! in_array(AccountRole::normalize((string) $staff->role), ['secretary', 'assistance_officer', 'distribution_staff'], true)) {
                throw new InvalidArgumentException('Choose a Secretary, Assistance Officer, or Distribution Staff account.');
            }

            if (strtolower((string) $staff->status) !== 'inactive') {
                throw new InvalidArgumentException('Only inactive staff accounts can be deleted.');
            }

            DB::table('auth_tokens')->where('user_id', $staffId)->delete();
            DB::table('sessions')->where('user_id', $staffId)->delete();
            DB::table('users')->where('id', $staffId)->delete();
            $this->log(
                (string) ($actor->name ?: $actor->username),
                "Deleted inactive {$staff->role} account {$staff->username}"
            );
            $this->bumpVersion();

            return [
                'id' => (int) $staff->id,
                'username' => (string) $staff->username,
                'role' => AccountRole::normalize((string) $staff->role),
            ];
        });
    }

    public function restoreState(array $state, object $actor): void
    {
        DB::transaction(function () use ($state, $actor): void {
            $this->writeState($state, $actor);

            if (array_key_exists('applications', $state)) {
                if (! is_array($state['applications'])) {
                    throw new InvalidArgumentException('Backup applications must be a list.');
                }

                DB::table('assistance_applications')->delete();
                foreach ($state['applications'] as $application) {
                    if (! is_array($application)
                        || ! is_numeric($application['id'] ?? null)
                        || ! is_string($application['beneficiary'] ?? null)
                        || ! is_string($application['username'] ?? null)
                        || ! is_string($application['programId'] ?? null)
                        || ! is_string($application['programName'] ?? null)
                        || ! is_string($application['benefit'] ?? null)
                        || ! is_string($application['reason'] ?? null)
                        || ! in_array($application['status'] ?? null, ['Submitted', 'Under Review', 'Approved', 'Rejected', 'Distributed'], true)) {
                        throw new InvalidArgumentException('Backup contains an invalid assistance application.');
                    }

                    $resident = DB::table('users')
                        ->whereRaw('LOWER(username) = ?', [mb_strtolower($application['username'])])
                        ->first();
                    if (! $resident || AccountRole::normalize((string) $resident->role) !== 'user') {
                        throw new InvalidArgumentException('Backup assistance applications must belong to a resident account.');
                    }

                    DB::table('assistance_applications')->insert([
                        'id' => (int) $application['id'],
                        'user_id' => $resident->id,
                        'beneficiary' => $application['beneficiary'],
                        'username' => $application['username'],
                        'program_id' => $application['programId'],
                        'program_name' => $application['programName'],
                        'benefit' => $application['benefit'],
                        'reason' => $application['reason'],
                        'status' => $application['status'],
                        'reviewed_by' => $application['reviewedBy'] ?? null,
                        'reviewed_at' => $application['reviewedAt'] ?? null,
                        'review_note' => $application['reviewNote'] ?? null,
                        'created_at' => $application['submittedAt'] ?? now(),
                        'updated_at' => $application['submittedAt'] ?? now(),
                    ]);
                }
            }

            if (array_key_exists('distributions', $state)) {
                if (! is_array($state['distributions'])) {
                    throw new InvalidArgumentException('Backup distributions must be a list.');
                }

                DB::table('distributions')->delete();
                foreach ($state['distributions'] as $distribution) {
                    if (! is_array($distribution)) {
                        throw new InvalidArgumentException('Backup contains an invalid distribution record.');
                    }

                    $applicationId = $distribution['applicationId'] ?? null;
                    $assistanceId = $distribution['assistanceId'] ?? null;
                    if (! is_numeric($distribution['id'] ?? null)
                        || (int) (($applicationId !== null) + ($assistanceId !== null)) !== 1
                        || ! is_string($distribution['beneficiary'] ?? null)
                        || ! is_string($distribution['benefit'] ?? null)
                        || ! is_string($distribution['amount'] ?? null)
                        || ! is_string($distribution['distributedBy'] ?? null)
                        || ! is_string($distribution['distributedAt'] ?? null)) {
                        throw new InvalidArgumentException('Backup contains an invalid distribution record.');
                    }

                    $programId = $distribution['programId'] ?? null;
                    if ($programId !== null
                        && (! is_string($programId)
                            || strlen($programId) > 40
                            || ! DB::table('programs')->where('id', $programId)->exists())) {
                        throw new InvalidArgumentException('Backup distribution refers to a missing assistance program.');
                    }

                    $residentId = null;
                    if ($applicationId !== null) {
                        $application = DB::table('assistance_applications')->where('id', (int) $applicationId)->first();
                        if (! $application) {
                            throw new InvalidArgumentException('Backup distribution refers to a missing assistance application.');
                        }
                        $residentId = $application->user_id;
                    } else {
                        $resident = DB::table('users')
                            ->whereRaw('LOWER(TRIM(beneficiary_name)) = ?', [mb_strtolower(trim($distribution['beneficiary']))])
                            ->get(['id', 'role'])
                            ->first(fn (object $user): bool => AccountRole::normalize((string) $user->role) === 'user');
                        $residentId = $resident?->id;
                    }

                    DB::table('distributions')->insert([
                        'id' => (int) $distribution['id'],
                        'application_id' => $applicationId === null ? null : (int) $applicationId,
                        'assistance_id' => $assistanceId,
                        'program_id' => $programId,
                        'user_id' => $residentId,
                        'beneficiary' => $distribution['beneficiary'],
                        'benefit' => $distribution['benefit'],
                        'amount' => $distribution['amount'],
                        'distributed_by' => $distribution['distributedBy'],
                        'distributed_at' => $distribution['distributedAt'],
                        'created_at' => $distribution['distributedAt'],
                        'updated_at' => $distribution['distributedAt'],
                    ]);
                }
            }

            $this->storeSnapshot();
        });
    }

    // #endregion
    // #region Assistance applications, evidence, and claim transactions
    public function reviewApplication(object $actor, int $applicationId, string $status, ?string $reviewNote): array
    {
        $proofPaths = [];
        $applicationRecord = DB::transaction(function () use ($actor, $applicationId, $status, $reviewNote, &$proofPaths): array {
            $application = DB::table('assistance_applications')
                ->where('id', $applicationId)
                ->lockForUpdate()
                ->first();
            if (! $application) {
                throw new InvalidArgumentException('Assistance application was not found.');
            }

            $transitions = [
                'Submitted' => ['Under Review'],
                'Under Review' => ['Approved', 'Rejected'],
            ];
            if (! in_array($status, $transitions[$application->status] ?? [], true)) {
                throw new InvalidArgumentException('That application status change is not allowed.');
            }
            if ($status === 'Approved' && DB::table('assistance_application_proof_requests')
                ->where('application_id', $applicationId)
                ->where('status', 'Requested')
                ->exists()) {
                throw new InvalidArgumentException('The resident must submit the requested proof before approval.');
            }

            $reviewer = trim((string) ($actor->name ?: $actor->username));
            if ($status === 'Rejected') {
                $proofPaths = $this->applicationProofPaths($applicationId);
            }
            DB::table('assistance_applications')->where('id', $applicationId)->update([
                'status' => $status,
                'reviewed_by' => $reviewer,
                'reviewed_at' => now(),
                'review_note' => trim((string) $reviewNote) ?: null,
                'updated_at' => now(),
            ]);
            $this->log($reviewer, "{$status} assistance application {$applicationId} for {$application->beneficiary}");
            $this->storeSnapshot();

            return $this->applicationById($applicationId);
        });

        if ($status === 'Rejected') {
            $this->deleteApplicationProof($applicationId, $proofPaths);
        }

        return $applicationRecord;
    }

    public function requestApplicationProof(object $actor, int $applicationId, string $note): array
    {
        return DB::transaction(function () use ($actor, $applicationId, $note): array {
            $application = DB::table('assistance_applications')
                ->where('id', $applicationId)
                ->lockForUpdate()
                ->first();
            if (! $application) {
                throw new InvalidArgumentException('Assistance application was not found.');
            }
            if ($application->status !== 'Under Review') {
                throw new InvalidArgumentException('Proof can only be requested while an application is under review.');
            }
            if (DB::table('assistance_application_proof_requests')->where('application_id', $applicationId)->exists()) {
                throw new InvalidArgumentException('Proof has already been requested for this application.');
            }

            $requestedAt = now();
            DB::table('assistance_application_proof_requests')->insert([
                'application_id' => $applicationId,
                'requested_by' => trim((string) ($actor->name ?: $actor->username)),
                'note' => trim($note),
                'status' => 'Requested',
                'requested_at' => $requestedAt,
                'submitted_at' => null,
                'created_at' => $requestedAt,
                'updated_at' => $requestedAt,
            ]);
            $this->log(
                trim((string) ($actor->name ?: $actor->username)),
                "Requested eligibility proof for assistance application {$applicationId}"
            );
            $this->storeSnapshot();

            return [
                'status' => 'Requested',
                'note' => trim($note),
                'requestedAt' => $requestedAt->toDateTimeString(),
                'submittedAt' => null,
                'fileCount' => 0,
            ];
        });
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{status: string, note: string, requestedAt: string, submittedAt: string, fileCount: int}
     */
    public function uploadApplicationProofs(object $actor, int $applicationId, array $files): array
    {
        if (($actor->normalized_role ?? AccountRole::normalize((string) $actor->role)) !== 'user') {
            throw new InvalidArgumentException('Only a resident account can submit proof documents.');
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($actor, $applicationId, $files, &$storedPaths): array {
                $application = DB::table('assistance_applications')
                    ->where('id', $applicationId)
                    ->lockForUpdate()
                    ->first();
                if (! $application || (int) $application->user_id !== (int) $actor->id) {
                    throw new InvalidArgumentException('Assistance application was not found.');
                }
                if ($application->status !== 'Under Review') {
                    throw new InvalidArgumentException('Proof can only be submitted while an application is under review.');
                }

                $proofRequest = DB::table('assistance_application_proof_requests')
                    ->where('application_id', $applicationId)
                    ->lockForUpdate()
                    ->first();
                if (! $proofRequest || $proofRequest->status !== 'Requested') {
                    throw new InvalidArgumentException('There is no outstanding proof request for this application.');
                }

                $disk = Storage::disk('local');
                $uploadedAt = now();
                foreach ($files as $file) {
                    $storagePath = $file->store(
                        "assistance-application-proofs/{$applicationId}/{$proofRequest->id}",
                        'local'
                    );
                    if (! is_string($storagePath) || $storagePath === '') {
                        throw new RuntimeException('A proof document could not be stored securely.');
                    }
                    $storedPaths[] = $storagePath;

                    $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'proof-document';
                    $originalName = mb_substr($originalName, 0, 255);
                    DB::table('assistance_application_proof_files')->insert([
                        'proof_request_id' => $proofRequest->id,
                        'storage_path' => $storagePath,
                        'original_name' => $originalName,
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'size' => (int) $file->getSize(),
                        'uploaded_at' => $uploadedAt,
                        'created_at' => $uploadedAt,
                        'updated_at' => $uploadedAt,
                    ]);
                }

                DB::table('assistance_application_proof_requests')
                    ->where('id', $proofRequest->id)
                    ->update([
                        'status' => 'Submitted',
                        'submitted_at' => $uploadedAt,
                        'updated_at' => $uploadedAt,
                    ]);
                $this->log(
                    trim((string) ($actor->name ?: $actor->username)),
                    "Submitted eligibility proof for assistance application {$applicationId}"
                );
                $this->storeSnapshot();

                return [
                    'status' => 'Submitted',
                    'note' => $proofRequest->note,
                    'requestedAt' => (string) $proofRequest->requested_at,
                    'submittedAt' => $uploadedAt->toDateTimeString(),
                    'fileCount' => count($storedPaths),
                ];
            });
        } catch (Throwable $error) {
            if ($storedPaths !== []) {
                $disk = Storage::disk('local');
                $existingPaths = array_values(array_filter(
                    $storedPaths,
                    fn (string $path): bool => $disk->exists($path)
                ));
                if ($existingPaths !== [] && ! $disk->delete($existingPaths)) {
                    Log::error('Failed to remove staged proof files after an upload error.', [
                        'application_id' => $applicationId,
                    ]);
                }
            }

            throw $error;
        }
    }

    /**
     * @return array{storagePath: string, originalName: string}
     */
    public function applicationProofFile(int $applicationId, int $fileId): array
    {
        $file = DB::table('assistance_application_proof_files as proof_files')
            ->join(
                'assistance_application_proof_requests as proof_requests',
                'proof_requests.id',
                '=',
                'proof_files.proof_request_id'
            )
            ->join('assistance_applications as applications', 'applications.id', '=', 'proof_requests.application_id')
            ->where('applications.id', $applicationId)
            ->where('proof_files.id', $fileId)
            ->whereNotIn('applications.status', ['Rejected', 'Distributed'])
            ->first([
                'proof_files.storage_path as storagePath',
                'proof_files.original_name as originalName',
            ]);
        if (! $file) {
            throw new InvalidArgumentException('Proof document was not found.');
        }

        return [
            'storagePath' => $file->storagePath,
            'originalName' => $file->originalName,
        ];
    }

    public function distributeApplication(object $actor, int $applicationId, string $amount): array
    {
        $proofPaths = [];
        $distribution = DB::transaction(function () use ($actor, $applicationId, $amount, &$proofPaths): array {
            $application = DB::table('assistance_applications')
                ->where('id', $applicationId)
                ->lockForUpdate()
                ->first();
            if (! $application) {
                throw new InvalidArgumentException('Assistance application was not found.');
            }
            if ($application->status !== 'Approved') {
                throw new InvalidArgumentException('Only approved applications can be distributed.');
            }

            $distributedBy = trim((string) ($actor->name ?: $actor->username));
            $distributedAt = now();
            $distributionId = DB::table('distributions')->insertGetId([
                'application_id' => $applicationId,
                'assistance_id' => null,
                'program_id' => $application->program_id,
                'user_id' => $application->user_id,
                'beneficiary' => $application->beneficiary,
                'benefit' => $application->benefit,
                'amount' => trim($amount),
                'distributed_by' => $distributedBy,
                'distributed_at' => $distributedAt,
                'created_at' => $distributedAt,
                'updated_at' => $distributedAt,
            ]);
            DB::table('assistance_applications')->where('id', $applicationId)->update([
                'status' => 'Distributed',
                'updated_at' => $distributedAt,
            ]);
            $proofPaths = $this->applicationProofPaths($applicationId);
            $this->log($distributedBy, "Distributed {$application->benefit} to {$application->beneficiary}");
            $this->storeSnapshot();

            return $this->distributionById($distributionId);
        });

        $this->deleteApplicationProof($applicationId, $proofPaths);

        return $distribution;
    }

    /**
     * @return array<int, string>
     */
    private function applicationProofPaths(int $applicationId): array
    {
        $requestIds = DB::table('assistance_application_proof_requests')
            ->where('application_id', $applicationId)
            ->pluck('id');

        return DB::table('assistance_application_proof_files')
            ->whereIn('proof_request_id', $requestIds)
            ->pluck('storage_path')
            ->all();
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function deleteApplicationProof(int $applicationId, array $paths): void
    {
        $disk = Storage::disk('local');
        $existingPaths = array_values(array_filter(
            $paths,
            fn (string $path): bool => $disk->exists($path)
        ));
        if ($existingPaths !== [] && ! $disk->delete($existingPaths)) {
            Log::error('Failed to delete private proof files after the application was finalized.', [
                'application_id' => $applicationId,
            ]);
            throw new RuntimeException(
                'The application was finalized, but private proof files could not be deleted. Contact the system administrator.'
            );
        }

        DB::table('assistance_application_proof_requests')
            ->where('application_id', $applicationId)
            ->delete();
    }

    public function claim(string $id, object $actor, ?string $programId, string $amount): array
    {
        $role = $actor->normalized_role ?? AccountRole::normalize((string) $actor->role);
        if ($role !== 'distribution_staff') {
            throw new InvalidArgumentException('Only distribution staff can record completed handovers.');
        }

        $programId = $programId !== null ? trim($programId) : null;
        $programId = $programId === '' ? null : $programId;
        $amount = trim($amount);
        if ($amount === '') {
            throw new InvalidArgumentException('Enter the amount or items before recording a completed handover.');
        }

        return DB::transaction(function () use ($id, $actor, $programId, $amount): array {
            $record = DB::table('assistance')->where('id', $id)->lockForUpdate()->first();
            if (! $record) {
                throw new InvalidArgumentException('Assistance record was not found.');
            }

            if ($record->status === 'Claimed') {
                throw new InvalidArgumentException('This assistance was already marked as claimed.');
            }

            $program = null;
            if ($programId !== null) {
                $program = DB::table('programs')->where('id', $programId)->lockForUpdate()->first();
                if (! $program) {
                    throw new InvalidArgumentException('Assistance program was not found.');
                }
                if (! in_array($program->status, ['Scheduled', 'Open'], true)) {
                    throw new InvalidArgumentException('This program is completed and cannot accept new releases.');
                }
            }

            $beneficiary = DB::table('beneficiaries')
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim((string) $record->beneficiary))])
                ->first(['category', 'status']);
            if (! $beneficiary || strtolower(trim((string) $beneficiary->status)) !== 'active') {
                throw new InvalidArgumentException('This beneficiary is not active or is not eligible for the selected program.');
            }
            if ($program && ! BenefitCategory::matches((string) $beneficiary->category, (string) $program->benefit)) {
                throw new InvalidArgumentException('This beneficiary is not active or is not eligible for the selected program.');
            }
            if (! in_array($record->status, ['Pending', 'Completed'], true)) {
                throw new InvalidArgumentException('Only pending or completed assistance records can be released.');
            }

            $claimDate = now()->toDateString();
            $claimActor = (string) $actor->name;
            $residentId = DB::table('users')
                ->whereRaw('LOWER(TRIM(beneficiary_name)) = ?', [mb_strtolower(trim((string) $record->beneficiary))])
                ->get(['id', 'role'])
                ->first(fn (object $user): bool => AccountRole::normalize((string) $user->role) === 'user')
                ?->id;
            DB::table('assistance')->where('id', $id)->update([
                'status' => 'Claimed',
                'claimed_at' => $claimDate,
                'claimed_by' => $claimActor,
                'amount' => $amount,
            ]);
            $distributedAt = now();
            DB::table('distributions')->insert([
                'application_id' => null,
                'assistance_id' => $id,
                'program_id' => $program?->id,
                'user_id' => $residentId,
                'beneficiary' => $record->beneficiary,
                'benefit' => $record->type,
                'amount' => $amount,
                'distributed_by' => $claimActor,
                'distributed_at' => $distributedAt,
                'created_at' => $distributedAt,
                'updated_at' => $distributedAt,
            ]);
            DB::table('beneficiaries')
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim((string) $record->beneficiary))])
                ->update([
                    'last_aid' => $record->type.' (Claimed)',
                    'updated' => $claimDate,
                ]);
            $releaseContext = $program ? " under {$program->name}" : ' on the resident assistance schedule';
            $this->log((string) $actor->name, "Recorded {$record->type} release{$releaseContext} for {$record->beneficiary}");
            $this->storeSnapshot();

            $updated = DB::table('assistance')->where('id', $id)->first();

            return [
                'id' => $updated->id,
                'beneficiary' => $updated->beneficiary,
                'type' => $updated->type,
                'amount' => $updated->amount,
                'date' => $updated->date,
                'status' => $updated->status,
                'staff' => $updated->staff,
                'claimedAt' => $updated->claimed_at,
                'claimedBy' => $updated->claimed_by,
            ];
        });
    }

    private function validatedAssistanceDate(mixed $value): string
    {
        $date = is_string($value) ? trim($value) : '';
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $matches) !== 1
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new InvalidArgumentException('Resident assistance release date must be a valid YYYY-MM-DD date.');
        }

        return $date;
    }

    private function applicationById(int $id): array
    {
        $application = DB::table('assistance_applications')->where('id', $id)->first();
        if (! $application) {
            throw new InvalidArgumentException('Assistance application was not found.');
        }

        return [
            'id' => (int) $application->id,
            'userId' => (int) $application->user_id,
            'beneficiary' => $application->beneficiary,
            'username' => $application->username,
            'programId' => $application->program_id,
            'programName' => $application->program_name,
            'benefit' => $application->benefit,
            'reason' => $application->reason,
            'status' => $application->status,
            'reviewedBy' => $application->reviewed_by,
            'reviewedAt' => $application->reviewed_at,
            'reviewNote' => $application->review_note,
            'submittedAt' => $application->created_at,
        ];
    }

    private function distributionById(int $id): array
    {
        $distribution = DB::table('distributions')->where('id', $id)->first();
        if (! $distribution) {
            throw new InvalidArgumentException('Distribution record was not found.');
        }

        return [
            'id' => (int) $distribution->id,
            'applicationId' => $distribution->application_id === null ? null : (int) $distribution->application_id,
            'assistanceId' => $distribution->assistance_id,
            'programId' => $distribution->program_id,
            'userId' => $distribution->user_id === null ? null : (int) $distribution->user_id,
            'beneficiary' => $distribution->beneficiary,
            'benefit' => $distribution->benefit,
            'amount' => $distribution->amount,
            'distributedBy' => $distribution->distributed_by,
            'distributedAt' => $distribution->distributed_at,
        ];
    }

    // #endregion
    // #region Profiles, activity, messaging, and beneficiary synchronization
    public function updateProfile(object $actor, array $fields): void
    {
        DB::transaction(function () use ($actor, $fields): void {
            DB::table('users')->where('id', $actor->id)->update([
                'name' => trim((string) $fields['name']),
                'contact' => trim((string) ($fields['contact'] ?? '')),
                'email' => strtolower(trim((string) ($fields['email'] ?? ''))),
                'address' => trim((string) ($fields['address'] ?? '')),
                'emergency_contact' => trim((string) ($fields['emergencyContact'] ?? '')),
                'profile_image' => ($fields['profileImage'] ?? '') !== '' ? $fields['profileImage'] : null,
            ]);
            $this->log((string) $actor->name, 'Updated profile for '.$actor->username);
            $this->storeSnapshot();
        });
    }

    public function log(string $actor, string $action): void
    {
        DB::table('logs')->insert([
            'event_time' => now()->toDateTimeString(),
            'actor' => $actor,
            'action' => $action,
        ]);
    }

    public function bumpVersion(): void
    {
        $this->storeSnapshot();
    }

    public function postResidentMessage(object $actor, string $kind, string $body): void
    {
        $role = $actor->normalized_role ?? AccountRole::normalize((string) $actor->role);
        if ($role !== 'user') {
            throw new InvalidArgumentException('Only a resident account can send a clarification or complaint.');
        }

        $staff = $this->staffAccountForResident((int) $actor->id);

        DB::table('resident_messages')->insert([
            'user_id' => $actor->id,
            'resident_name' => trim((string) ($actor->name ?: $actor->username)),
            'username' => (string) $actor->username,
            'kind' => $kind,
            'body' => trim($body),
            'from_role' => 'resident',
            'staff_name' => $staff['name'] ?? null,
            'staff_username' => $staff['username'] ?? null,
            'created_at' => now(),
        ]);
        $staffAccount = $staff['username'] ?? 'staff';
        $this->log((string) ($actor->name ?: $actor->username), "Sent a {$kind} to staff account {$staffAccount}");
        $this->bumpVersion();
    }

    public function replyToResident(object $actor, int $userId, string $body): void
    {
        $role = $actor->normalized_role ?? AccountRole::normalize((string) $actor->role);
        if (! in_array($role, ['secretary', 'assistance_officer', 'distribution_staff'], true)) {
            throw new InvalidArgumentException('Only staff accounts can reply to a resident message.');
        }

        $resident = DB::table('users')->where('id', $userId)->first();
        if (! $resident || AccountRole::normalize((string) $resident->role) !== 'user') {
            throw new InvalidArgumentException('Choose a registered resident account.');
        }

        $latest = DB::table('resident_messages')->where('user_id', $userId)->orderByDesc('id')->first();
        $residentName = trim((string) ($resident->name ?: $resident->username));

        DB::table('resident_messages')->insert([
            'user_id' => $resident->id,
            'resident_name' => $residentName,
            'username' => (string) $resident->username,
            'kind' => $latest->kind ?? 'Notice',
            'body' => trim($body),
            'from_role' => 'staff',
            'staff_name' => trim((string) ($actor->name ?: $actor->username)),
            'staff_username' => (string) $actor->username,
            'created_at' => now(),
        ]);
        $this->log((string) ($actor->name ?: $actor->username), "Sent a message to {$residentName}'s resident account");
        $this->bumpVersion();
    }

    private function staffAccountForResident(int $residentId): array
    {
        $latestStaff = DB::table('resident_messages')
            ->where('user_id', $residentId)
            ->where('from_role', 'staff')
            ->orderByDesc('id')
            ->first();

        $username = trim((string) ($latestStaff->staff_username ?? ''));
        $name = trim((string) ($latestStaff->staff_name ?? ''));
        if ($username !== '') {
            return ['name' => $name !== '' ? $name : $username, 'username' => $username];
        }

        $staff = DB::table('users')
            ->whereRaw("LOWER(role) IN ('barangay', 'staff')")
            ->whereRaw("LOWER(status) = 'active'")
            ->orderBy('id')
            ->first();

        if (! $staff) {
            return [];
        }

        return [
            'name' => trim((string) ($staff->name ?: $staff->username)),
            'username' => (string) $staff->username,
        ];
    }

    private function assertBeneficiariesCanBeRemoved(array $state): void
    {
        $incomingIds = [];
        foreach ($state['beneficiaries'] as $item) {
            $incomingIds[$item['id']] = true;
        }

        $removed = DB::table('beneficiaries')->get()->filter(
            fn (object $row): bool => ! isset($incomingIds[$row->id])
        );

        foreach ($removed as $row) {
            $stillLinked = collect($state['assistance'] ?? [])->contains(
                fn (array $record): bool => strcasecmp(trim((string) ($record['beneficiary'] ?? '')), $row->name) === 0
            ) || collect($state['users'] ?? [])->contains(
                fn (array $user): bool => strcasecmp(trim((string) ($user['beneficiaryName'] ?? '')), $row->name) === 0
            );

            if ($stillLinked) {
                throw new InvalidArgumentException("Cannot delete {$row->name} while assistance records or resident accounts are still linked.");
            }
        }
    }

    private function applyBeneficiaryRenames(array $state): array
    {
        $incomingById = collect($state['beneficiaries'])->keyBy('id');
        $renames = [];

        foreach (DB::table('beneficiaries')->get() as $old) {
            $incoming = $incomingById->get($old->id);
            $newName = trim((string) ($incoming['name'] ?? ''));
            if ($incoming && $newName !== '' && strcasecmp($old->name, $newName) !== 0) {
                $renames[mb_strtolower($old->name)] = $newName;
            }
        }

        if ($renames === []) {
            return $state;
        }

        foreach ($state['assistance'] as &$record) {
            $key = mb_strtolower(trim((string) ($record['beneficiary'] ?? '')));
            if (isset($renames[$key])) {
                $record['beneficiary'] = $renames[$key];
            }
        }
        unset($record);

        foreach ($state['users'] as &$user) {
            $key = mb_strtolower(trim((string) ($user['beneficiaryName'] ?? '')));
            if (isset($renames[$key])) {
                $user['beneficiaryName'] = $renames[$key];
            }
        }
        unset($user);

        foreach ($renames as $old => $new) {
            DB::table('users')->whereRaw('LOWER(TRIM(beneficiary_name)) = ?', [$old])->update(['beneficiary_name' => $new]);
        }

        return $state;
    }

    public function attachCategoryAssistance(string $beneficiaryName, string $category, string $staff = 'Staff'): void
    {
        $name = trim($beneficiaryName);
        if ($name === '') {
            return;
        }

        $currentLastAid = (string) DB::table('beneficiaries')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('last_aid');
        $existing = DB::table('assistance')
            ->whereRaw('LOWER(beneficiary) = ?', [mb_strtolower($name)])
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
        $linked = $this->linkCategoriesToAssistance([
            ['name' => $name, 'category' => $category, 'lastAid' => $currentLastAid],
        ], $existing, (object) ['name' => $staff]);

        foreach ($linked['assistance'] as $record) {
            $alreadyStored = collect($existing)->contains(
                fn (array $item): bool => strcasecmp((string) $item['type'], (string) $record['type']) === 0
            );
            if ($alreadyStored) {
                continue;
            }

            DB::table('assistance')->insert([
                'id' => $record['id'],
                'beneficiary' => $name,
                'type' => $record['type'],
                'amount' => $record['amount'] ?? 'As released',
                'date' => $record['date'] ?? now()->toDateString(),
                'status' => $record['status'] ?? 'Pending',
                'staff' => $record['staff'] ?? $staff,
                'claimed_at' => null,
                'claimed_by' => null,
            ]);
        }

        $lastAid = $linked['beneficiaries'][0]['lastAid'] ?? '';
        if ($lastAid !== '') {
            DB::table('beneficiaries')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->update([
                'last_aid' => $lastAid,
                'updated' => now()->toDateString(),
            ]);
        }
    }

    private function linkCategoriesToAssistance(array $beneficiaries, array $assistance, ?object $actor): array
    {
        $staff = 'Staff';
        if ($actor) {
            $staff = trim((string) ($actor->name ?: $actor->username ?: 'Staff')) ?: 'Staff';
        }

        foreach ($beneficiaries as &$beneficiary) {
            $name = trim((string) ($beneficiary['name'] ?? ''));
            $types = array_values(array_filter(array_map('trim', explode(',', (string) ($beneficiary['category'] ?? '')))));
            if ($name === '' || $types === []) {
                continue;
            }

            foreach ($types as $type) {
                $exists = false;
                foreach ($assistance as $record) {
                    if (strcasecmp(trim((string) ($record['beneficiary'] ?? '')), $name) === 0
                        && strcasecmp(trim((string) ($record['type'] ?? '')), $type) === 0) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) {
                    continue;
                }

                $assistance[] = [
                    'id' => 'A-'.now()->format('ymdHis').'-'.strtolower(Str::random(4)),
                    'beneficiary' => $name,
                    'type' => $type,
                    'amount' => 'As released',
                    'date' => now()->toDateString(),
                    'status' => 'Pending',
                    'staff' => $staff,
                ];
            }

            $beneficiary['lastAid'] = implode(', ', $types);
        }
        unset($beneficiary);

        return compact('beneficiaries', 'assistance');
    }

    public function refreshPossibleDuplicateFlags(): void
    {
        $nameGroups = [];
        $contactGroups = [];

        foreach (DB::table('beneficiaries')->select('id', 'name', 'contact')->get() as $beneficiary) {
            $normalizedName = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $beneficiary->name)) ?? '');
            $normalizedContact = preg_replace('/\D+/', '', (string) $beneficiary->contact) ?? '';
            if ($normalizedName !== '') {
                $nameGroups[$normalizedName][] = $beneficiary->id;
            }
            if ($normalizedContact !== '') {
                $contactGroups[$normalizedContact][] = $beneficiary->id;
            }
        }

        $duplicateIds = [];
        foreach ([$nameGroups, $contactGroups] as $groups) {
            foreach ($groups as $ids) {
                if (count($ids) > 1) {
                    $duplicateIds = array_merge($duplicateIds, $ids);
                }
            }
        }
        $duplicateIds = array_values(array_unique($duplicateIds));

        DB::table('beneficiaries')
            ->where('possible_duplicate', true)
            ->update(['possible_duplicate' => false]);

        if ($duplicateIds !== []) {
            DB::table('beneficiaries')
                ->whereIn('id', $duplicateIds)
                ->update(['possible_duplicate' => true]);
        }
    }

    // #endregion
    // #region Operational table synchronization and state defaults
    private function replaceOperationalTables(array $state, ?array $tables = null, ?object $actor = null): void
    {
        $tables ??= ['beneficiaries', 'programs', 'assistance', 'logs', 'settings'];
        $storedVerification = in_array('beneficiaries', $tables, true)
            ? DB::table('beneficiaries')
                ->select('id', 'verification_status', 'verified_by', 'verified_at')
                ->get()
                ->keyBy('id')
            : collect();

        foreach ($tables as $table) {
            DB::table($table)->delete();
        }

        if (in_array('beneficiaries', $tables, true)) {
            foreach ($state['beneficiaries'] as $item) {
                $stored = $storedVerification->get($item['id']);
                DB::table('beneficiaries')->insert([
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'purok' => $item['purok'] ?? '',
                    'category' => $item['category'] ?? '',
                    'contact' => $item['contact'] ?? '',
                    'status' => $item['status'] ?? 'Active',
                    'last_aid' => $item['lastAid'] ?? '',
                    'updated' => ($item['updated'] ?? null) ?: now()->toDateString(),
                    'verification_status' => $actor
                        ? ($stored->verification_status ?? 'Pending')
                        : ($item['verificationStatus'] ?? $stored->verification_status ?? 'Pending'),
                    'verified_by' => $actor
                        ? ($stored->verified_by ?? null)
                        : ($item['verifiedBy'] ?? $stored->verified_by ?? null),
                    'verified_at' => $actor
                        ? ($stored->verified_at ?? null)
                        : ($item['verifiedAt'] ?? $stored->verified_at ?? null),
                    'possible_duplicate' => false,
                ]);
            }
        }

        if (in_array('programs', $tables, true)) {
            foreach ($state['programs'] ?? [] as $item) {
                $releaseAmount = $item['releaseAmount'] ?? null;
                if ($releaseAmount !== null && ! is_string($releaseAmount)) {
                    throw new InvalidArgumentException('Planned amount or items must be text.');
                }
                $releaseAmount = trim($releaseAmount ?? '');
                if (mb_strlen($releaseAmount) > 120) {
                    throw new InvalidArgumentException('Planned amount or items must be 120 characters or fewer.');
                }

                DB::table('programs')->insert([
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'benefit' => trim((string) ($item['benefit'] ?? '')),
                    'date' => ($item['date'] ?? null) ?: now()->toDateString(),
                    'program_time' => ($item['time'] ?? null) ?: null,
                    'venue' => $item['venue'] ?? '',
                    'slots' => (int) ($item['slots'] ?? 0),
                    'status' => $item['status'] ?? 'Scheduled',
                    'assigned_staff' => $item['assignedStaff'] ?? null,
                    'instructions' => $item['instructions'] ?? null,
                    'release_amount' => $releaseAmount !== '' ? $releaseAmount : null,
                ]);
            }
        }

        if (in_array('assistance', $tables, true)) {
            foreach ($state['assistance'] ?? [] as $item) {
                $amount = $item['amount'] ?? '';
                if (! is_string($amount)) {
                    throw new InvalidArgumentException('Resident assistance amount or items must be text.');
                }
                if (mb_strlen($amount) > 120) {
                    throw new InvalidArgumentException('Resident assistance amount or items must be 120 characters or fewer.');
                }

                DB::table('assistance')->insert([
                    'id' => $item['id'],
                    'beneficiary' => $item['beneficiary'],
                    'type' => $item['type'],
                    'amount' => $amount,
                    'date' => $this->validatedAssistanceDate(($item['date'] ?? null) ?: now()->toDateString()),
                    'status' => $item['status'] ?? 'Pending',
                    'staff' => $item['staff'] ?? '',
                    'claimed_at' => ($item['claimedAt'] ?? null) ?: null,
                    'claimed_by' => ($item['claimedBy'] ?? null) ?: null,
                ]);
            }
        }

        if (in_array('logs', $tables, true)) {
            foreach ($state['logs'] ?? [] as $item) {
                DB::table('logs')->insert([
                    'event_time' => $item['time'] ?? now()->toDateTimeString(),
                    'actor' => $item['actor'] ?? 'System',
                    'action' => $item['action'] ?? '',
                ]);
            }
        }

        if (in_array('settings', $tables, true)) {
            foreach ($state['settings'] ?? [] as $key => $value) {
                DB::table('settings')->insert([
                    'setting_key' => $key,
                    'setting_value' => json_encode($value),
                ]);
            }
        }
    }

    private function syncUsers(array $users, ?object $actor): void
    {
        if ($actor === null) {
            DB::table('users')->delete();
            foreach ($users as $item) {
                $this->insertUser($item, null);
            }
            $this->assertActiveAdminExists();
            $this->pruneTokens();

            return;
        }

        $keepIds = [];

        foreach ($users as $item) {
            $existing = $this->findUser($item);

            if ($existing && (int) $existing->id === (int) $actor->id) {
                $item['role'] = $existing->role;
                $item['status'] = 'Active';
            }

            if ($existing) {
                $this->updateUser($existing, $item);
                $keepIds[] = (int) $existing->id;

                continue;
            }

            $keepIds[] = $this->insertUser($item, null);
        }

        DB::table('users')
            ->whereNotIn('id', $keepIds === [] ? [0] : $keepIds)
            ->where('id', '<>', $actor->id)
            ->delete();

        $this->assertActiveAdminExists();
        $this->pruneTokens();
    }

    private function findUser(array $item): ?object
    {
        if (! empty($item['id'])) {
            $byId = DB::table('users')->where('id', $item['id'])->first();
            if ($byId) {
                return $byId;
            }
        }

        $username = strtolower(trim((string) ($item['username'] ?? '')));
        if ($username === '') {
            return null;
        }

        return DB::table('users')->whereRaw('LOWER(username) = ?', [$username])->first();
    }

    private function insertUser(array $item, ?object $existing): int
    {
        $id = DB::table('users')->insertGetId($this->userPayload($item, $existing));

        return (int) $id;
    }

    private function updateUser(object $existing, array $item): void
    {
        DB::table('users')->where('id', $existing->id)->update($this->userPayload($item, $existing));
    }

    private function userPayload(array $item, ?object $existing): array
    {
        $password = $this->resolvePassword($item, $existing);
        $username = strtolower(trim((string) ($item['username'] ?? '')));
        if ($username === '') {
            throw new InvalidArgumentException('Every account needs a username.');
        }

        $role = (string) ($item['role'] ?? ($existing->role ?? 'User'));
        if (AccountRole::normalize($role) === null) {
            throw new InvalidArgumentException('Choose a supported account role.');
        }

        $duplicate = DB::table('users')
            ->whereRaw('LOWER(username) = ?', [$username])
            ->when($existing, fn ($query) => $query->where('id', '<>', $existing->id))
            ->exists();
        if ($duplicate) {
            throw new InvalidArgumentException("Username {$username} is already taken.");
        }

        return [
            'name' => $item['name'] ?? $username,
            'role' => AccountRole::storedName(AccountRole::normalize($role)),
            'beneficiary_name' => $item['beneficiaryName'] ?? ($existing->beneficiary_name ?? ''),
            'username' => $username,
            'password' => $password,
            'status' => $item['status'] ?? ($existing->status ?? 'Active'),
            'contact' => $item['contact'] ?? ($existing->contact ?? ''),
            'email' => strtolower(trim((string) ($item['email'] ?? ($existing->email ?? '')))),
            'position' => $item['position'] ?? ($existing->position ?? ''),
            'address' => $item['address'] ?? ($existing->address ?? ''),
            'profile_image' => array_key_exists('profileImage', $item)
                ? (($item['profileImage'] ?: null))
                : ($existing->profile_image ?? null),
            'emergency_contact' => $item['emergencyContact'] ?? ($existing->emergency_contact ?? ''),
        ];
    }

    private function resolvePassword(array $item, ?object $existing): string
    {
        $password = (string) ($item['password'] ?? '');
        if ($password === '') {
            $password = (string) ($existing->password ?? '');
        } elseif (password_get_info($password)['algo'] === null) {
            $password = Hash::make($password);
        }

        if ($password === '') {
            throw new InvalidArgumentException('A password is required for new accounts.');
        }

        return $password;
    }

    private function pruneTokens(): void
    {
        DB::table('auth_tokens')
            ->whereNotIn('user_id', DB::table('users')->where('status', 'Active')->select('id'))
            ->delete();
    }

    private function assertActiveAdminExists(): void
    {
        $hasAdmin = DB::table('users')->where('status', 'Active')->get()->contains(
            fn (object $user): bool => AccountRole::normalize((string) $user->role) === 'admin'
        );

        if (! $hasAdmin) {
            throw new InvalidArgumentException('At least one active Admin account is required.');
        }
    }

    private function ensureIds(array $items, string $prefix): array
    {
        $used = [];
        foreach ($items as &$item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '' || isset($used[$id])) {
                do {
                    $id = $prefix.'-'.now()->format('ymdHis').'-'.strtolower(Str::random(4));
                } while (isset($used[$id]));
            }
            $used[$id] = true;
            $item['id'] = $id;
        }
        unset($item);

        return $items;
    }

    private function storeSnapshot(): void
    {
        $state = $this->readState();
        DB::table('app_state')->updateOrInsert(
            ['id' => 1],
            ['payload' => json_encode($state, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]
        );
    }

    public function version(): string
    {
        return (string) (DB::table('app_state')->where('id', 1)->value('updated_at') ?? now());
    }

    private function officeSettings(array $settings = []): array
    {
        return [
            'barangayName' => $settings['barangayName'] ?? 'Barangay Taft',
            'city' => $settings['city'] ?? 'Surigao City',
            'province' => $settings['province'] ?? 'Surigao del Norte',
            'region' => $settings['region'] ?? 'Caraga',
            'address' => $settings['address'] ?? 'Barangay Hall, Taft, Surigao City',
            'contact' => $settings['contact'] ?? '',
            'email' => $settings['email'] ?? '',
            'officeHours' => $settings['officeHours'] ?? 'Monday to Friday, 8:00 AM to 5:00 PM',
            'punongBarangay' => $settings['punongBarangay'] ?? '',
        ];
    }

    public function defaultState(): array
    {
        return [
            'beneficiaries' => [
                ['id' => 'B-2026-001', 'name' => 'Maria Santos', 'purok' => 'Purok 1', 'category' => 'Senior Citizen', 'contact' => '0917 123 4567', 'status' => 'Active', 'lastAid' => 'Food Pack', 'updated' => '2026-06-01'],
                ['id' => 'B-2026-002', 'name' => 'Juan Dela Cruz', 'purok' => 'Purok 3', 'category' => 'PWD', 'contact' => '0928 245 7781', 'status' => 'Active', 'lastAid' => 'Medical Aid', 'updated' => '2026-06-03'],
                ['id' => 'B-2026-003', 'name' => 'Ana Reyes', 'purok' => 'Purok 2', 'category' => 'Solo Parent', 'contact' => '0906 334 8120', 'status' => 'Pending', 'lastAid' => 'Educational Aid', 'updated' => '2026-06-05'],
                ['id' => 'B-2026-004', 'name' => 'Roberto Lim', 'purok' => 'Purok 5', 'category' => 'Indigent', 'contact' => '0915 889 2110', 'status' => 'Active', 'lastAid' => 'Cash Assistance', 'updated' => '2026-05-28'],
            ],
            'programs' => [
                ['id' => 'P-101', 'name' => 'Food Pack Distribution', 'benefit' => 'Senior Citizen', 'date' => '2026-06-10', 'venue' => 'Barangay Hall', 'slots' => 120, 'status' => 'Scheduled'],
                ['id' => 'P-102', 'name' => 'Medical Assistance Validation', 'benefit' => 'PWD', 'date' => '2026-06-14', 'venue' => 'Health Center', 'slots' => 60, 'status' => 'Scheduled'],
                ['id' => 'P-103', 'name' => 'Educational Aid Release', 'benefit' => 'All residents', 'date' => '2026-06-20', 'venue' => 'Covered Court', 'slots' => 80, 'status' => 'Open'],
            ],
            'assistance' => [
                ['id' => 'A-5001', 'beneficiary' => 'Maria Santos', 'type' => 'Food Pack', 'amount' => '1 package', 'date' => '2026-06-01', 'status' => 'Completed', 'staff' => 'B. Navarro'],
                ['id' => 'A-5002', 'beneficiary' => 'Juan Dela Cruz', 'type' => 'Medical Aid', 'amount' => 'PHP 2,500', 'date' => '2026-06-03', 'status' => 'Completed', 'staff' => 'L. Castro'],
                ['id' => 'A-5003', 'beneficiary' => 'Ana Reyes', 'type' => 'Educational Aid', 'amount' => 'PHP 1,500', 'date' => '2026-06-20', 'status' => 'Pending', 'staff' => 'M. Garcia'],
            ],
            'users' => [],
            'logs' => [
                ['time' => '2026-06-06 09:00', 'actor' => 'System', 'action' => 'Initialized BarangayCare monitoring records'],
                ['time' => '2026-06-06 09:25', 'actor' => 'Barangay Staff', 'action' => 'Updated food pack distribution schedule'],
                ['time' => '2026-06-06 10:12', 'actor' => 'Admin', 'action' => 'Reviewed module status and audit logs'],
            ],
            'settings' => $this->officeSettings(),
        ];
    }
    // #endregion
}
