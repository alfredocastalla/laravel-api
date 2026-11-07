<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetLink;
use App\Mail\ProgramScheduleNotification;
use App\Services\BarangayCareAuth;
use App\Services\BarangayCareStore;
use App\Support\AccountRole;
use App\Support\BenefitCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BarangayCareApiController extends Controller
{
    public function __construct(
        private readonly BarangayCareStore $store,
        private readonly BarangayCareAuth $auth,
    ) {}

    // #region Health, data, and authentication endpoints
    public function health(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return response()->json([
                'ok' => true,
                'service' => 'BarangayCare Laravel API',
                'database' => config('database.connections.mysql.database'),
                'realtime' => 'polling',
            ]);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function summary(): JsonResponse
    {
        try {
            return response()->json([
                'ok' => true,
                'data' => $this->store->publicSummary(),
                'source' => 'laravel-mysql',
            ]);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function data(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'ok' => true,
                'data' => $this->store->readStateFor($this->actor($request)),
                'version' => $this->store->version(),
                'source' => 'laravel-mysql',
            ]);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function saveData(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['secretary'])) {
            return $denied;
        }

        $data = $request->input('data');
        if (! is_array($data)) {
            return response()->json(['ok' => false, 'error' => 'Missing data payload.'], 400);
        }

        try {
            $this->store->writeState($data, $actor);

            return response()->json([
                'ok' => true,
                'data' => $this->store->readStateFor($actor),
                'version' => $this->store->version(),
                'source' => 'laravel-mysql',
            ]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function auth(Request $request): JsonResponse
    {
        try {
            $username = strtolower(trim((string) $request->input('username', '')));
            $password = (string) $request->input('password', '');

            if ($username === '' || $password === '') {
                return response()->json(['ok' => false, 'error' => 'Username and password are required.'], 422);
            }

            $user = DB::table('users')->whereRaw('LOWER(username) = ?', [$username])->first();
            if (! $user || AccountRole::normalize((string) $user->role) === null || ! $this->passwordMatches($password, (string) $user->password)) {
                return response()->json(['ok' => false, 'error' => 'Invalid login. Check the username, password, and account status.'], 401);
            }

            if (! in_array($user->status, ['Active', 'Pending'], true)) {
                return response()->json(['ok' => false, 'error' => 'Invalid login. Check the username, password, and account status.'], 401);
            }

            $updates = [];
            if ($user->status === 'Pending') {
                $updates['status'] = 'Active';
                $user->status = 'Active';
            }
            if (password_get_info((string) $user->password)['algo'] === null) {
                $updates['password'] = Hash::make($password);
            }
            if ($updates !== []) {
                DB::table('users')->where('id', $user->id)->update($updates);
            }

            $role = AccountRole::normalize((string) $user->role);
            $this->store->log((string) ($user->name ?: $user->username), 'Logged in as '.AccountRole::storedName($role));
            $this->store->bumpVersion();

            return response()->json([
                'ok' => true,
                'token' => $this->auth->issue($user),
                'user' => $this->publicUser($user, $role),
                'source' => 'laravel-mysql',
            ]);
        } catch (Throwable $error) {
            Log::error('BarangayCare sign-in failed.', ['exception' => $error]);

            return response()->json([
                'ok' => false,
                'error' => 'Sign-in is temporarily unavailable. Please try again shortly or contact your barangay administrator.',
            ], 503);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->store->log((string) $actor->name, 'Logged out');
        $this->store->bumpVersion();
        $this->auth->revoke($request);

        return response()->json(['ok' => true]);
    }

    // #endregion
    // #region Resident, staff, and profile account endpoints
    public function createResident(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['secretary'])) {
            return $denied;
        }

        $normalizedUsername = strtolower(trim((string) $request->input('username', '')));
        if ($normalizedUsername !== '' && DB::table('users')->whereRaw('LOWER(username) = ?', [$normalizedUsername])->exists()) {
            return response()->json(['ok' => false, 'error' => 'That username is already taken. Please choose another.'], 422);
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $request->merge([
            'email' => $email !== '' ? $email : null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:80', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'contact' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'beneficiaryName' => ['nullable', 'string', 'max:150'],
            'categories' => ['sometimes', 'array', 'min:1'],
            'categories.*' => ['string', 'max:60'],
        ]);

        $requestedName = trim((string) ($validated['beneficiaryName'] ?? ''));
        $beneficiaryName = $requestedName !== '' ? $requestedName : trim($validated['name']);
        $beneficiary = DB::table('beneficiaries')
            ->whereRaw('LOWER(name) = ?', [strtolower($beneficiaryName)])
            ->first();

        if (! $beneficiary && $requestedName !== '') {
            return response()->json(['ok' => false, 'error' => 'Choose an existing beneficiary to link to this resident account.'], 422);
        }

        $categories = collect($this->normalizeBenefitCategories($validated['categories'] ?? []));
        if (array_key_exists('categories', $validated) && $categories->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Enter the specific benefit type.'], 422);
        }
        if (strlen($categories->implode(', ')) > 255) {
            return response()->json(['ok' => false, 'error' => 'The benefit types are too long.'], 422);
        }
        if (! $beneficiary) {
            $beneficiaryId = 'B-'.now()->format('ymdHis').'-'.substr(bin2hex(random_bytes(2)), 0, 4);
            DB::table('beneficiaries')->insert([
                'id' => $beneficiaryId,
                'name' => $beneficiaryName,
                'purok' => mb_substr(trim($validated['address'] ?? ''), 0, 100),
                'category' => $categories->isNotEmpty() ? $categories->implode(', ') : 'Other',
                'contact' => trim($validated['contact'] ?? ''),
                'status' => 'Active',
                'last_aid' => 'None',
                'updated' => now()->toDateString(),
            ]);
            $this->store->refreshPossibleDuplicateFlags();
            $beneficiary = (object) ['id' => $beneficiaryId, 'name' => $beneficiaryName];
        } elseif ($categories->isNotEmpty()) {
            DB::table('beneficiaries')->where('id', $beneficiary->id)->update([
                'category' => $categories->implode(', '),
                'updated' => now()->toDateString(),
            ]);
        }

        if ($categories->isNotEmpty()) {
            $this->store->attachCategoryAssistance(
                $beneficiary->name,
                $categories->implode(', '),
                (string) ($actor->name ?: $actor->username ?: 'Staff')
            );
        }

        $username = strtolower(trim($validated['username']));
        $userId = DB::table('users')->insertGetId([
            'name' => trim($validated['name']),
            'role' => 'User',
            'beneficiary_name' => $beneficiary->name,
            'username' => $username,
            'password' => Hash::make($validated['password']),
            'status' => 'Active',
            'contact' => trim($validated['contact'] ?? ''),
            'email' => strtolower(trim($validated['email'] ?? '')),
            'position' => '',
            'address' => trim($validated['address'] ?? ''),
            'profile_image' => null,
            'emergency_contact' => '',
        ]);

        $this->store->log((string) $actor->username, "Created resident account {$username} for {$beneficiary->name}");
        $this->store->bumpVersion();

        return response()->json([
            'ok' => true,
            'message' => "Resident account for {$beneficiary->name} created. It can now sign in.",
            'user' => [
                'id' => $userId,
                'username' => $username,
                'name' => trim($validated['name']),
                'role' => 'user',
                'status' => 'Active',
                'beneficiaryName' => $beneficiary->name,
            ],
        ], 201);
    }

    public function updateBeneficiaryVerification(Request $request, string $beneficiary): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['secretary'])) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:Pending,Verified'],
        ]);

        return DB::transaction(function () use ($actor, $beneficiary, $validated): JsonResponse {
            $record = DB::table('beneficiaries')
                ->where('id', $beneficiary)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return response()->json(['ok' => false, 'error' => 'Beneficiary record not found.'], 404);
            }

            $isVerified = $validated['status'] === 'Verified';
            $verifiedAt = $isVerified ? now() : null;
            DB::table('beneficiaries')->where('id', $beneficiary)->update([
                'verification_status' => $validated['status'],
                'verified_by' => $isVerified ? $actor->id : null,
                'verified_at' => $verifiedAt,
            ]);

            $this->store->log(
                (string) ($actor->name ?: $actor->username),
                "Marked beneficiary {$beneficiary} as {$validated['status']}"
            );
            $this->store->bumpVersion();

            return response()->json([
                'ok' => true,
                'beneficiary' => [
                    'id' => $beneficiary,
                    'verificationStatus' => $validated['status'],
                    'verifiedAt' => $verifiedAt?->toDateTimeString(),
                ],
                'source' => 'laravel-mysql',
            ]);
        });
    }

    public function updateResident(Request $request, int $resident): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['secretary', 'assistance_officer', 'distribution_staff'])) {
            return $denied;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:80'],
            'password' => ['nullable', 'string', 'min:8'],
            'email' => ['nullable', 'email', 'max:150'],
            'contact' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:Active,Inactive'],
        ]);

        try {
            $user = $this->store->updateResidentAccount($actor, $resident, $validated);

            return response()->json([
                'ok' => true,
                'message' => 'Resident account updated.',
                'user' => $user,
                'source' => 'laravel-mysql',
            ]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function createStaffAccount(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['admin', 'barangay'])) {
            return $denied;
        }

        $allowedRoles = $actor->normalized_role === 'admin'
            ? ['Barangay Captain']
            : ['Secretary', 'Assistance Officer', 'Distribution Staff'];
        $normalizedUsername = strtolower(trim((string) $request->input('username', '')));
        if ($normalizedUsername !== '' && DB::table('users')->whereRaw('LOWER(username) = ?', [$normalizedUsername])->exists()) {
            return response()->json(['ok' => false, 'error' => 'That username is already taken. Please choose another.'], 422);
        }

        $request->merge([
            'username' => $normalizedUsername,
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'role' => ['required', 'string', 'in:'.implode(',', $allowedRoles)],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:80', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
            'email' => ['nullable', 'email', 'max:150'],
            'contact' => ['nullable', 'string', 'max:40'],
            'position' => ['nullable', 'string', 'max:120'],
        ]);

        $email = trim((string) ($validated['email'] ?? ''));
        if ($email !== '' && DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json(['ok' => false, 'error' => 'That email address is already in use.'], 422);
        }

        $username = $validated['username'];
        $role = match ($validated['role']) {
            'Barangay Captain' => 'barangay',
            'Secretary' => 'secretary',
            'Assistance Officer' => 'assistance_officer',
            'Distribution Staff' => 'distribution_staff',
        };
        $userId = DB::transaction(function () use ($actor, $email, $role, $username, $validated): int {
            $userId = DB::table('users')->insertGetId([
                'name' => trim($validated['name']),
                'role' => AccountRole::storedName($role),
                'beneficiary_name' => '',
                'username' => $username,
                'password' => Hash::make($validated['password']),
                'status' => 'Active',
                'contact' => trim((string) ($validated['contact'] ?? '')),
                'email' => $email,
                'position' => trim((string) ($validated['position'] ?? '')),
                'address' => '',
                'profile_image' => null,
                'emergency_contact' => '',
            ]);

            $this->store->log((string) ($actor->name ?: $actor->username), "Created {$validated['role']} staff account {$username}");
            $this->store->bumpVersion();

            return $userId;
        });

        return response()->json([
            'ok' => true,
            'message' => 'Staff account created. It can now sign in.',
            'user' => [
                'id' => $userId,
                'name' => trim($validated['name']),
                'role' => $role,
                'username' => $username,
                'status' => 'Active',
            ],
        ], 201);
    }

    public function updateStaffAccountStatus(Request $request, int $staff): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['barangay'])) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:Active,Inactive'],
        ]);

        try {
            $account = $this->store->updateStaffAccountStatus($actor, $staff, $validated['status']);

            return response()->json([
                'ok' => true,
                'message' => 'Staff account status updated.',
                'user' => $account,
                'source' => 'laravel-mysql',
            ]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function resetStaffAccountPassword(Request $request, int $staff): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['barangay'])) {
            return $denied;
        }

        $validated = $request->validate([
            'newPassword' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        try {
            $account = $this->store->resetStaffAccountPassword($actor, $staff, $validated['newPassword']);

            return response()->json([
                'ok' => true,
                'message' => 'Staff password reset. The staff member must choose a new password at next sign-in.',
                'user' => $account,
            ]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function deleteStaffAccount(Request $request, int $staff): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['barangay'])) {
            return $denied;
        }

        try {
            $account = $this->store->deleteStaffAccount($actor, $staff);

            return response()->json([
                'ok' => true,
                'message' => 'Inactive staff account deleted.',
                'user' => $account,
                'source' => 'laravel-mysql',
            ]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergencyContact' => ['nullable', 'string', 'max:80'],
            'profileImage' => ['nullable', 'string', 'max:2100000'],
        ]);

        $this->store->updateProfile($actor, $validated);
        $user = DB::table('users')->where('id', $actor->id)->first();

        return response()->json([
            'ok' => true,
            'message' => 'Profile information saved.',
            'user' => $this->publicUser($user, $actor->normalized_role),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (strtolower(trim($validated['username'])) !== strtolower((string) $actor->username)) {
            return response()->json(['ok' => false, 'error' => 'You can only change your own password.'], 403);
        }

        if (! $this->passwordMatches($validated['currentPassword'], (string) $actor->password)) {
            return response()->json(['ok' => false, 'error' => 'Current password is incorrect.'], 422);
        }

        DB::table('users')->where('id', $actor->id)->update([
            'password' => Hash::make($validated['newPassword']),
            'must_change_password' => false,
        ]);

        $currentToken = (string) $request->bearerToken();
        DB::table('auth_tokens')
            ->where('user_id', $actor->id)
            ->where('token_hash', '<>', hash('sha256', $currentToken))
            ->delete();

        return response()->json(['ok' => true, 'message' => 'Password updated.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:150'],
        ]);

        $username = strtolower(trim($validated['username']));
        $email = strtolower(trim($validated['email']));
        $user = DB::table('users')->whereRaw('LOWER(username) = ?', [$username])->first();
        $role = $user ? AccountRole::normalize((string) $user->role) : null;
        $savedEmail = strtolower(trim((string) ($user->email ?? '')));
        $allowed = $user
            && in_array($role, ['admin', 'barangay', 'secretary', 'assistance_officer', 'distribution_staff', 'auditor', 'user'], true)
            && $savedEmail !== ''
            && hash_equals($savedEmail, $email)
            && in_array($user->status, ['Active', 'Pending'], true);

        if ($allowed) {
            $token = Str::random(64);
            $expiresInMinutes = 60;

            DB::table('account_password_reset_tokens')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'token' => hash('sha256', $token),
                    'created_at' => now(),
                ]
            );

            try {
                $resetUrl = url('/login').'#'.http_build_query([
                    'password-reset' => $token,
                    'user-id' => (int) $user->id,
                ]);
                Mail::to($savedEmail)->send(new PasswordResetLink(
                    (string) ($user->name ?: $user->username),
                    $resetUrl,
                    $expiresInMinutes,
                ));
            } catch (Throwable $error) {
                DB::table('account_password_reset_tokens')->where('user_id', $user->id)->delete();
                Log::error('BarangayCare password reset email could not be sent.', ['exception' => $error]);
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'If an active account matches those details, a password reset link will be sent to its registered email address. Check your inbox and spam folder.',
        ]);
    }

    public function resetForgottenPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'userId' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $reset = DB::transaction(function () use ($validated): ?object {
            $reset = DB::table('account_password_reset_tokens')
                ->where('user_id', $validated['userId'])
                ->lockForUpdate()
                ->first();

            if (! $reset
                || now()->subMinutes(60)->greaterThan($reset->created_at)
                || ! hash_equals((string) $reset->token, hash('sha256', $validated['token']))) {
                return null;
            }

            $user = DB::table('users')->where('id', $reset->user_id)->first();
            $role = $user ? AccountRole::normalize((string) $user->role) : null;
            if (! $user
                || ! in_array($role, ['admin', 'barangay', 'secretary', 'assistance_officer', 'distribution_staff', 'auditor', 'user'], true)
                || ! in_array($user->status, ['Active', 'Pending'], true)) {
                return null;
            }

            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($validated['password']),
                'must_change_password' => false,
            ]);
            DB::table('auth_tokens')->where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('account_password_reset_tokens')->where('user_id', $user->id)->delete();
            $this->store->log((string) ($user->name ?: $user->username), 'Reset the password from a verified email link');
            $this->store->bumpVersion();

            return $user;
        });

        if (! $reset) {
            return response()->json([
                'ok' => false,
                'error' => 'This password reset link is invalid or has expired. Request a new link.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Password updated. Sign in with your new password.',
        ]);
    }

    // #endregion
    // #region Assistance review, proof, and distribution endpoints
    public function claimAssistance(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['distribution_staff'])) {
            return $denied;
        }

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:40'],
            'programId' => ['sometimes', 'nullable', 'string', 'max:40'],
            'amount' => ['required', 'string', 'max:120'],
        ]);

        try {
            $record = $this->store->claim(
                $validated['id'],
                $actor,
                $validated['programId'] ?? null,
                $validated['amount']
            );

            return response()->json(['ok' => true, 'record' => $record]);
        } catch (InvalidArgumentException $error) {
            $status = str_contains($error->getMessage(), 'not found') ? 404 : 422;

            return response()->json(['ok' => false, 'error' => $error->getMessage()], $status);
        }
    }

    public function submitAssistanceApplication(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'Assistance is assigned by the barangay based on your registered benefit profile. No application is required.',
        ], 403);
    }

    public function requestAssistanceApplicationProof(Request $request, int $application): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['assistance_officer'])) {
            return $denied;
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/'],
        ]);

        try {
            $proofRequest = $this->store->requestApplicationProof($actor, $application, $validated['note']);

            return response()->json(['ok' => true, 'proofRequest' => $proofRequest], 201);
        } catch (InvalidArgumentException $error) {
            $status = str_contains($error->getMessage(), 'not found') ? 404 : 422;

            return response()->json(['ok' => false, 'error' => $error->getMessage()], $status);
        } catch (Throwable $error) {
            Log::error('Assistance proof request failed.', [
                'application_id' => $application,
                'exception' => $error,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'The proof request could not be saved. Please try again.',
            ], 500);
        }
    }

    public function uploadAssistanceApplicationProof(Request $request, int $application): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['user'])) {
            return $denied;
        }

        $validated = $request->validate([
            'proofs' => ['required', 'array', 'min:1', 'max:5'],
            'proofs.*' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'extensions:jpg,jpeg,png,pdf',
                'max:10240',
            ],
        ]);

        try {
            $proofRequest = $this->store->uploadApplicationProofs($actor, $application, $validated['proofs']);

            return response()->json(['ok' => true, 'proofRequest' => $proofRequest]);
        } catch (InvalidArgumentException $error) {
            $status = str_contains($error->getMessage(), 'not found') ? 404 : 422;

            return response()->json(['ok' => false, 'error' => $error->getMessage()], $status);
        } catch (Throwable $error) {
            Log::error('Assistance proof upload failed.', [
                'application_id' => $application,
                'exception' => $error,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Proof documents could not be processed securely. Please try again or contact the Assistance Officer.',
            ], 500);
        }
    }

    public function downloadAssistanceApplicationProof(Request $request, int $application, int $document): JsonResponse|StreamedResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['assistance_officer', 'admin'])) {
            return $denied;
        }

        try {
            $proof = $this->store->applicationProofFile($application, $document);
            $disk = Storage::disk('local');
            if (! $disk->exists($proof['storagePath'])) {
                return response()->json(['ok' => false, 'error' => 'Proof document was not found.'], 404);
            }

            $downloadName = preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                basename(str_replace('\\', '/', $proof['originalName']))
            ) ?: 'proof-document';
            if (in_array($downloadName, ['.', '..'], true)) {
                $downloadName = 'proof-document';
            }

            return $disk->download($proof['storagePath'], $downloadName, [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 404);
        } catch (Throwable $error) {
            Log::error('Assistance proof download failed.', [
                'application_id' => $application,
                'document_id' => $document,
                'exception' => $error,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'The proof document could not be downloaded. Please contact the system administrator.',
            ], 500);
        }
    }

    public function reviewAssistanceApplication(Request $request, int $application): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['assistance_officer'])) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:Under Review,Approved,Rejected'],
            'reviewNote' => ['nullable', 'string', 'max:1000', 'required_if:status,Rejected'],
        ]);

        try {
            $record = $this->store->reviewApplication(
                $actor,
                $application,
                $validated['status'],
                $validated['reviewNote'] ?? null,
            );

            return response()->json(['ok' => true, 'application' => $record]);
        } catch (InvalidArgumentException $error) {
            $status = str_contains($error->getMessage(), 'not found') ? 404 : 422;

            return response()->json(['ok' => false, 'error' => $error->getMessage()], $status);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function distributeAssistanceApplication(Request $request, int $application): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['distribution_staff'])) {
            return $denied;
        }

        $validated = $request->validate([
            'amount' => ['required', 'string', 'max:120'],
        ]);

        try {
            $distribution = $this->store->distributeApplication($actor, $application, $validated['amount']);

            return response()->json(['ok' => true, 'distribution' => $distribution], 201);
        } catch (InvalidArgumentException $error) {
            $status = str_contains($error->getMessage(), 'not found') ? 404 : 422;

            return response()->json(['ok' => false, 'error' => $error->getMessage()], $status);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    // #endregion
    // #region Backup, messaging, and email endpoints
    public function version(): JsonResponse
    {
        try {
            return response()->json([
                'ok' => true,
                'version' => $this->store->version(),
                'source' => 'laravel-mysql',
            ]);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function backup(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'Backup exports are not available to monitoring-only accounts.',
        ], 403);
    }

    public function restore(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'Backup restoration is not available to monitoring-only accounts.',
        ], 403);
    }

    public function chat(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['admin', 'user'])) {
            return $denied;
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $question = mb_strtolower(trim($validated['question']));
        $state = $this->store->readStateFor($actor);
        $programs = collect($state['programs'] ?? [])->sortBy('date')->values();
        $nextProgram = $programs->first(
            fn (array $program): bool => ($program['status'] ?? '') !== 'Completed'
                && ($program['date'] ?? '') >= now()->toDateString()
        ) ?? $programs->first();

        if (str_contains($question, 'requirement') || str_contains($question, 'bring')) {
            $reply = 'Please bring a valid ID, barangay certificate if required, and any document requested by the barangay staff for your assistance category.';
        } elseif (str_contains($question, 'status')) {
            $assistance = collect($state['assistance'] ?? []);
            $pending = $assistance->where('status', 'Pending')->count();
            $reply = "There are currently {$pending} pending assistance records for your account. You may check My Assistance for your listed record.";
        } elseif ($programs->isEmpty()) {
            $reply = 'There is no posted assistance schedule yet. Please check again after the barangay staff creates a program schedule.';
        } elseif (
            str_contains($question, 'schedule')
            || str_contains($question, 'release')
            || str_contains($question, 'when')
            || str_contains($question, 'assistance')
        ) {
            $reply = sprintf(
                '%s is scheduled on %s at %s. The current status is %s. Please bring a valid ID and any required barangay documents.',
                $nextProgram['name'] ?? 'The next program',
                $nextProgram['date'] ?? 'a date to be announced',
                $nextProgram['venue'] ?? 'a venue to be announced',
                $nextProgram['status'] ?? 'Pending'
            );
        } else {
            $reply = 'I can answer questions about release schedules, assistance status, requirements, and program venues. Please ask about a schedule or assistance release.';
        }

        return response()->json(['ok' => true, 'reply' => $reply, 'source' => 'laravel-mysql']);
    }

    public function storeMessage(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $role = $actor->normalized_role;

        try {
            if ($role === 'user') {
                $validated = $request->validate([
                    'kind' => ['required', 'string', 'in:Message,Clarification,Complaint'],
                    'body' => ['required', 'string', 'max:1000'],
                ]);
                $this->store->postResidentMessage($actor, $validated['kind'], $validated['body']);
            } else {
                if ($denied = $this->forbidUnless($actor, ['secretary', 'assistance_officer', 'distribution_staff'])) {
                    return $denied;
                }
                $validated = $request->validate([
                    'userId' => ['required', 'integer'],
                    'body' => ['required', 'string', 'max:1000'],
                ]);
                $this->store->replyToResident($actor, (int) $validated['userId'], $validated['body']);
            }

            return response()->json([
                'ok' => true,
                'data' => $this->store->readStateFor($actor),
                'version' => $this->store->version(),
            ]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function notifyAssistance(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['user', 'distribution_staff'])) {
            return $denied;
        }

        $validated = $request->validate([
            'record' => ['required', 'array'],
            'record.id' => ['nullable', 'string', 'max:40'],
            'record.beneficiary' => ['required', 'string', 'max:150'],
            'record.type' => ['nullable', 'string', 'max:120'],
            'record.amount' => ['nullable', 'string', 'max:120'],
            'record.date' => ['nullable', 'string', 'max:40'],
            'record.status' => ['nullable', 'string', 'max:30'],
            'record.staff' => ['nullable', 'string', 'max:150'],
            'record.claimedAt' => ['nullable', 'string', 'max:40'],
            'record.claimedBy' => ['nullable', 'string', 'max:150'],
            'eventType' => ['nullable', 'string', 'in:created,claimed,today,updated'],
        ]);

        $record = $validated['record'];
        $stored = ! empty($record['id']) ? DB::table('assistance')->where('id', $record['id'])->first() : null;
        $beneficiary = trim((string) ($stored->beneficiary ?? $record['beneficiary']));
        if ($actor->normalized_role === 'user') {
            $linked = mb_strtolower(trim((string) ($actor->beneficiary_name ?: $actor->name)));
            if ($linked === '' || $linked !== mb_strtolower($beneficiary)) {
                return response()->json(['ok' => false, 'error' => 'You can only send updates for your own assistance record.'], 403);
            }
        }

        try {
            $eventType = $validated['eventType'] ?? 'updated';
            $user = $this->residentGmailForAssistance($beneficiary);

            if (! $user) {
                return response()->json([
                    'ok' => false,
                    'configured' => $this->mailIsConfigured(),
                    'error' => 'This resident account has no Gmail address yet. Add it on the resident account, then send again.',
                ], 422);
            }

            if (! $this->mailIsConfigured()) {
                return response()->json([
                    'ok' => false,
                    'configured' => false,
                    'error' => 'The resident Gmail is saved, but the barangay Gmail sender is not set up. In laravel-api/.env set MAIL_MAILER=smtp, MAIL_HOST=smtp.gmail.com, MAIL_PORT=587, MAIL_USERNAME, MAIL_PASSWORD, and MAIL_FROM_ADDRESS.',
                ], 503);
            }

            $type = trim((string) ($stored->type ?? $record['type'] ?? 'Assistance')) ?: 'Assistance';
            $status = trim((string) ($stored->status ?? $record['status'] ?? 'Pending')) ?: 'Pending';
            $amount = trim((string) ($stored->amount ?? $record['amount'] ?? '')) ?: 'As released';
            $date = substr((string) ($stored->date ?? $record['date'] ?? ''), 0, 10) ?: 'Not set';
            $staff = trim((string) ($stored->staff ?? $record['staff'] ?? '')) ?: 'Barangay staff';
            $claimedAt = substr((string) ($stored->claimed_at ?? $record['claimedAt'] ?? ''), 0, 10);
            $claimedBy = trim((string) ($stored->claimed_by ?? $record['claimedBy'] ?? ''));
            $residentName = trim((string) ($user->name ?: $beneficiary));
            [$subject, $opening] = match ($eventType) {
                'claimed' => ["Your {$type} has been claimed", "Your {$type} is now marked as claimed on your resident account."],
                'created' => ["Your {$type} has been added", "Your {$type} has been added to your resident account."],
                'today' => ["Today's update on your {$type}", "This is today's update on your {$type}."],
                default => ["Update on your {$type}", "There is an update on your {$type}."],
            };
            $lines = [
                "Hello {$residentName},",
                '',
                $opening,
                '',
                "Assistance: {$type}",
                "Status: {$status}",
                "Amount: {$amount}",
                "Record date: {$date}",
                "Staff: {$staff}",
            ];
            if ($eventType === 'claimed' || strcasecmp($status, 'Claimed') === 0) {
                $lines[] = 'Claimed on: '.($claimedAt !== '' ? $claimedAt : now()->toDateString());
                if ($claimedBy !== '') {
                    $lines[] = "Claimed by: {$claimedBy}";
                }
            }
            $lines[] = '';
            $lines[] = 'This email was sent to the Gmail address saved on your resident account.';
            $lines[] = 'If this claim or update is not correct, contact the barangay office.';
            $lines[] = '';
            $lines[] = 'BarangayCare';

            Mail::raw(implode("\n", $lines), function ($message) use ($user, $subject): void {
                $message->to($user->email, $user->name ?: null)->subject($subject);
            });

            DB::table('logs')->insert([
                'event_time' => now()->format('Y-m-d H:i'),
                'actor' => $actor->name ?: $actor->username,
                'action' => "Emailed {$user->email} about {$beneficiary}'s {$type} ({$eventType})",
            ]);

            return response()->json([
                'ok' => true,
                'sentTo' => $user->email,
                'eventType' => $eventType,
                'message' => "Sent the {$type} {$eventType} email to {$residentName}'s Gmail.",
                'source' => 'laravel-mail',
            ]);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    public function notifyProgram(Request $request, string $program): JsonResponse
    {
        $actor = $this->actor($request);
        if ($denied = $this->forbidUnless($actor, ['secretary'])) {
            return $denied;
        }

        $schedule = DB::table('programs')->where('id', $program)->first();
        if (! $schedule) {
            return response()->json(['ok' => false, 'error' => 'That program schedule was not found. Save it first, then send the email.'], 404);
        }

        $benefit = trim((string) ($schedule->benefit ?? ''));
        if ($benefit === '') {
            return response()->json(['ok' => false, 'error' => 'Choose a benefit category before sending this schedule.'], 422);
        }

        if (! $this->mailIsConfigured()) {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'error' => 'Email sending is not configured. Set MAIL_MAILER=smtp and the sender SMTP host, port, username, password, and MAIL_FROM_ADDRESS in laravel-api/.env.',
            ], 503);
        }

        $targets = $this->residentsForBenefit($benefit);
        if ($targets === []) {
            return response()->json([
                'ok' => true,
                'sent' => 0,
                'message' => BenefitCategory::matches(null, $benefit)
                    ? 'No active resident account has a valid registered email address.'
                    : "No active {$benefit} resident account has a valid registered email address.",
            ]);
        }

        $sent = 0;
        $failed = 0;

        foreach ($targets as $resident) {
            try {
                Mail::to($resident->email, $resident->name ?: null)->send(new ProgramScheduleNotification(
                    residentName: (string) ($resident->name ?: 'Resident'),
                    programName: (string) $schedule->name,
                    benefit: $benefit,
                    date: substr((string) $schedule->date, 0, 10),
                    time: (string) ($schedule->program_time ?? ''),
                    venue: (string) ($schedule->venue ?? ''),
                    releaseAmount: (string) ($schedule->release_amount ?? ''),
                    assignedStaff: (string) ($schedule->assigned_staff ?? ''),
                    instructions: (string) ($schedule->instructions ?? ''),
                    status: (string) ($schedule->status ?: 'Scheduled'),
                ));
                $sent++;
            } catch (Throwable $error) {
                $failed++;
                Log::warning('A program schedule email could not be delivered.', [
                    'program_id' => $program,
                    'resident_id' => $resident->id,
                    'exception' => $error::class,
                ]);
            }
        }

        if ($sent === 0) {
            return response()->json([
                'ok' => false,
                'error' => 'The schedule email could not be delivered. Check the SMTP sender configuration and try again.',
                'failed' => $failed,
            ], 502);
        }

        DB::table('logs')->insert([
            'event_time' => now()->format('Y-m-d H:i'),
            'actor' => $actor->name ?: $actor->username,
            'action' => "Emailed {$sent} residents the latest {$schedule->name} schedule ({$benefit}, ".substr((string) $schedule->date, 0, 10).')',
        ]);

        $message = "Sent the latest schedule to {$sent} resident ".($sent === 1 ? 'email address.' : 'email addresses.');
        if ($failed > 0) {
            $message .= " {$failed} could not be sent.";
        }

        return response()->json([
            'ok' => true,
            'sent' => $sent,
            'failed' => $failed,
            'benefit' => $benefit,
            'message' => $message,
        ]);
    }

    public function mailStatus(Request $request): JsonResponse
    {
        if ($denied = $this->forbidUnless($this->actor($request), ['admin'])) {
            return $denied;
        }

        $configured = $this->mailIsConfigured();
        $username = (string) config('mail.mailers.smtp.username', '');
        $maskedUsername = '';

        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            [$name, $domain] = explode('@', $username, 2);
            $maskedUsername = substr($name, 0, 2).str_repeat('*', max(1, strlen($name) - 2)).'@'.$domain;
        }

        return response()->json([
            'ok' => true,
            'configured' => $configured,
            'host' => (string) config('mail.mailers.smtp.host', ''),
            'port' => (int) config('mail.mailers.smtp.port', 0),
            'username' => $maskedUsername,
            'message' => $configured
                ? 'Laravel SMTP mail is configured.'
                : 'Laravel SMTP mail is not configured. Set MAIL_MAILER=smtp and the SMTP values in laravel-api/.env.',
        ]);
    }

    // #endregion
    // #region Shared authorization, serialization, and notification helpers
    private function normalizeBenefitCategories(array $categories): array
    {
        $standard = ['Senior Citizen', 'PWD', 'Solo Parent', '4Ps', 'Pregnant Mother', 'Indigent'];
        $resolved = [];

        foreach ($categories as $category) {
            $name = trim((string) $category);
            if ($name === '' || strcasecmp($name, 'Other') === 0 || str_contains($name, ',')) {
                continue;
            }

            $canonical = collect($standard)->first(fn (string $type): bool => strcasecmp($type, $name) === 0);
            $label = $canonical ?? $name;
            if (collect($resolved)->contains(fn (string $item): bool => strcasecmp($item, $label) === 0)) {
                continue;
            }

            $resolved[] = $label;
        }

        return $resolved;
    }

    private function publicUser(object $user, string $role): array
    {
        return [
            'id' => $user->id,
            'uid' => $role.'-'.strtolower((string) $user->username),
            'username' => $user->username,
            'role' => $role,
            'status' => $user->status,
            'name' => $user->name ?: $user->username,
            'isBarangayCaptain' => strtolower(trim((string) $user->role)) === 'barangay',
            'email' => $user->email ?? '',
            'contact' => $user->contact ?? '',
            'address' => $user->address ?? '',
            'beneficiaryName' => $user->beneficiary_name ?? ($user->name ?? ''),
            'profileImage' => $user->profile_image ?? '',
            'emergencyContact' => $user->emergency_contact ?? '',
            'mustChangePassword' => (bool) ($user->must_change_password ?? false),
        ];
    }

    private function passwordMatches(string $password, string $storedPassword): bool
    {
        if ($storedPassword === '') {
            return false;
        }

        return password_get_info($storedPassword)['algo'] !== null
            ? Hash::check($password, $storedPassword)
            : hash_equals($storedPassword, $password);
    }

    private function actor(Request $request): object
    {
        return $request->attributes->get('barangayUser');
    }

    private function forbidUnless(object $actor, array $roles): ?JsonResponse
    {
        if (! in_array($actor->normalized_role, $roles, true)) {
            return response()->json(['ok' => false, 'error' => 'You do not have permission to do that.'], 403);
        }

        return null;
    }

    private function residentsForBenefit(string $benefit): array
    {
        $residents = DB::table('users')
            ->whereRaw('LOWER(role) = ?', ['user'])
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->get();

        $matched = [];
        foreach ($residents as $resident) {
            $email = trim((string) ($resident->email ?? ''));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $resident->email = $email;

            if (BenefitCategory::matches(null, $benefit)) {
                $matched[] = $resident;

                continue;
            }

            $linked = mb_strtolower(trim((string) ($resident->beneficiary_name ?: $resident->name)));
            $beneficiary = $linked === '' ? null : DB::table('beneficiaries')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$linked])
                ->first();
            if (BenefitCategory::matches((string) ($beneficiary->category ?? ''), $benefit)) {
                $matched[] = $resident;
            }
        }

        return $matched;
    }

    private function residentGmailForAssistance(string $beneficiary): ?object
    {
        $name = mb_strtolower(trim($beneficiary));
        if ($name === '') {
            return null;
        }

        $user = DB::table('users')
            ->whereRaw("LOWER(role) IN ('user', 'resident')")
            ->where(function ($query) use ($name): void {
                $query->whereRaw('LOWER(TRIM(beneficiary_name)) = ?', [$name])
                    ->orWhereRaw('LOWER(TRIM(name)) = ?', [$name]);
            })
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->orderByRaw("CASE WHEN LOWER(email) LIKE '%@gmail.com' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $user;
    }

    private function mailIsConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        $smtp = config('mail.mailers.smtp', []);

        return $mailer === 'smtp'
            && ! empty($smtp['host'])
            && ! empty($smtp['port'])
            && ! empty($smtp['username'])
            && ! empty($smtp['password'])
            && ! empty(config('mail.from.address'));
    }
    // #endregion
}
