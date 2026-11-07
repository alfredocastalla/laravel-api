<?php

namespace Tests\Feature;

use App\Mail\PasswordResetLink;
use App\Mail\ProgramScheduleNotification;
use Database\Seeders\BarangayCareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BarangayCareApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $username, string $password = 'password'): string
    {
        if ($username === 'user') {
            $this->ensureResidentAccount();
        }

        return $this->postJson('/api/auth', [
            'username' => $username,
            'password' => $password,
        ])->assertOk()->json('token');
    }

    private function ensureResidentAccount(): void
    {
        if (DB::table('users')->where('username', 'user')->exists()) {
            return;
        }

        DB::table('users')->insert([
            'name' => 'Resident Account',
            'role' => 'User',
            'beneficiary_name' => 'Maria Santos',
            'username' => 'user',
            'password' => Hash::make('password'),
            'status' => 'Active',
            'email' => '',
        ]);
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function assertFrontendAssets(TestResponse $response): void
    {
        $response->assertSee('laravel-service.js');

        if (file_exists(public_path('build/manifest.json'))) {
            $response->assertSee('/build/assets/app-', false);

            return;
        }

        $response
            ->assertSee('assets/app.css?v=20261103-appcss46')
            ->assertSee('assets/app.js?v=20261105-directscript89');
    }

    private function roleHeaders(string $username, string $role): array
    {
        DB::table('users')->insert([
            'name' => ucwords(str_replace('_', ' ', $username)),
            'role' => $role,
            'beneficiary_name' => '',
            'username' => $username,
            'password' => Hash::make('secure-pass-123'),
            'status' => 'Active',
        ]);

        return $this->authHeaders($this->tokenFor($username, 'secure-pass-123'));
    }

    private function fakeProofPng(): UploadedFile
    {
        $content = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/rckAAAAASUVORK5CYII=',
            true
        );

        return UploadedFile::fake()->createWithContent('resident-id.png', $content ?: '');
    }

    /**
     * @return array{id: int, programName: string, status: string}
     */
    private function createLegacyAssistanceApplication(string $username = 'user'): array
    {
        $user = DB::table('users')->where('username', $username)->first();
        $program = DB::table('programs')->where('id', 'P-101')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($program);
        $recordedAt = now();
        $applicationId = DB::table('assistance_applications')->insertGetId([
            'user_id' => $user->id,
            'beneficiary' => (string) ($user->beneficiary_name ?: $user->name),
            'username' => (string) $user->username,
            'program_id' => (string) $program->id,
            'program_name' => (string) $program->name,
            'benefit' => (string) $program->benefit,
            'reason' => 'Legacy record for staff workflow coverage.',
            'status' => 'Submitted',
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]);

        return [
            'id' => (int) $applicationId,
            'programName' => (string) $program->name,
            'status' => 'Submitted',
        ];
    }

    public function test_homepage_serves_the_barangaycare_system(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('<div id="app"></div>', false)
            ->assertSee('laravel-service.js?v=20261105-service12');

        $this->assertFrontendAssets($response);
    }

    public function test_homepage_uses_https_asset_urls_behind_the_railway_proxy(): void
    {
        $response = $this->get('/', ['X-Forwarded-Proto' => 'https']);

        $response
            ->assertOk()
            ->assertSee('https://127.0.0.1:8000/vendor/react.production.min.js');
    }

    public function test_frontend_stylesheet_is_served_from_the_resources_entry(): void
    {
        $this->get('/assets/app.css')
            ->assertOk()
            ->assertHeader('content-type', 'text/css; charset=UTF-8')
            ->assertSee(':root {', false)
            ->assertSee('.password-visibility-toggle', false)
            ->assertDontSee('.auth-role-button', false)
            ->assertDontSee('.landing-admin-cta', false)
            ->assertSee('.mobile-nav', false)
            ->assertSee('.topbar {', false)
            ->assertSee(".topbar {\n  position: relative;", false)
            ->assertDontSee(".topbar {\n  position: sticky;", false)
            ->assertSee('.resident-update-row', false)
            ->assertSee('.program-monitor-stages', false)
            ->assertSee('.monitoring-activity-item', false)
            ->assertSee('.monitoring-audit-toolbar', false)
            ->assertSee('.admin-audit-monitoring .monitoring-hero', false)
            ->assertSee('.monitoring-audit-panel', false)
            ->assertSee('.staff-workspace-hero', false)
            ->assertSee('.staff-workspace-link-grid', false)
            ->assertSee('.distribution-workload', false)
            ->assertSee('.distribution-workload-grid', false)
            ->assertSee('.secretary-schedule-overview', false)
            ->assertSee('.captain-executive-hero', false)
            ->assertSee('.captain-access-ring', false)
            ->assertSee('.captain-role-cards', false)
            ->assertSee('.captain-executive-actions', false)
            ->assertSee('.captain-accounts-summary', false)
            ->assertSee('.profile-workspace-hero', false)
            ->assertSee('.profile-layout > .profile-summary', false)
            ->assertSee('.beneficiary-directory-toolbar', false)
            ->assertSee('.beneficiary-duplicate-shortcut', false)
            ->assertSee('.beneficiary-row-status', false)
            ->assertSee('.beneficiary-directory-hero', false)
            ->assertSee('.beneficiary-profile-card', false)
            ->assertSee('.assistance-application-queue', false)
            ->assertSee('.proof-request-panel', false)
            ->assertSee('.proof-file-row', false)
            ->assertSee('.assistance-overview .claim-summary', false)
            ->assertSee('.registered-accounts-page', false)
            ->assertSee('.report-insights', false)
            ->assertSee('.report-data-section', false)
            ->assertSee('.report-scope-note', false)
            ->assertSee('.program-resident-roster-table', false)
            ->assertSee('.program-resident-navigation', false)
            ->assertSee('.distribution-claim-desk', false)
            ->assertSee('.distribution-claim-form', false)
            ->assertSee('.assistance-records-panel .table-wrap table', false)
            ->assertSee('.program-roster-records', false)
            ->assertSee('.program-roster-release-action', false)
            ->assertSee('.resident-welcome-actions', false)
            ->assertSee('.resident-account-status', false)
            ->assertSee('.resident-empty-state', false)
            ->assertSee('.resident-stats', false)
            ->assertSee('.resident-message-compose-heading', false)
            ->assertSee('.resident-message-suggestions-wrap', false)
            ->assertSee('.resident-message-suggestion-copy', false)
            ->assertSee('.resident-message-status.is-waiting', false)
            ->assertSee('.resident-message-card', false);
    }

    public function test_frontend_script_is_served_from_the_resources_entry(): void
    {
        $this->get('/assets/app.js')
            ->assertOk()
            ->assertHeader('content-type', 'application/javascript; charset=UTF-8')
            ->assertSee('const React = window.React;', false)
            ->assertSee('Admin Login', false)
            ->assertDontSee('Super Admin Login', false)
            ->assertSee('Barangay Login', false)
            ->assertSee('Your account role securely routes you to the right workspace after sign-in.', false)
            ->assertSee('Resident accounts are created by the Barangay Secretary; self-registration is not available.', false)
            ->assertSee('Welcome back', false)
            ->assertSee('Support for your community, in one place.', false)
            ->assertSee('Program schedules', false)
            ->assertSee('The barangay assigns assistance based on your registered benefits. You do not need to apply.', false)
            ->assertDontSee('Submit Application', false)
            ->assertDontSee('auth-role-button', false)
            ->assertDontSee('Choose account type', false)
            ->assertDontSee('Sign in as', false)
            ->assertDontSee('Barangay Captain Login', false)
            ->assertDontSee('Confidential Records', false)
            ->assertSee('const accountRoleOptions = ["User", "Barangay", "Admin", "Secretary", "Assistance Officer", "Distribution Staff", "Read-only Auditor"]', false)
            ->assertDontSee('name: "Assistance Officer",', false)
            ->assertSee('The Assistance Officer workspace is no longer available.', false)
            ->assertSee('if (["user", "resident", "resident account"].includes(normalized)) return "Resident";', false)
            ->assertSee('return label === "Resident" ? "User" : label;', false)
            ->assertSee('"Upcoming Schedules": "upcoming-schedule"', false)
            ->assertSee('Dashboard Overview', false)
            ->assertDontSee('function ApplicationTrend({ applications })', false)
            ->assertDontSee('function ApplicationStatusChart({ applications })', false)
            ->assertSee('staff-workspace-dashboard staff-workspace-${role}', false)
            ->assertSee('WORKSPACE`', false)
            ->assertSee('Your workspace', false)
            ->assertSee('Pending verification', false)
            ->assertSee('Release tracking', false)
            ->assertSee('const canRecordAssistance = editable && actions.session.role !== "secretary";', false)
            ->assertSee('canRecordAssistance && h("button"', false)
            ->assertSee('Distribution at a glance', false)
            ->assertSee('record.status === "Pending"', false)
            ->assertSee('Schedule overview', false)
            ->assertSee('Residents awaiting release', false)
            ->assertSee('["Secretary", "Distribution Staff"]', false)
            ->assertSee('const staffRoles = ["Secretary", "Distribution Staff"]', false)
            ->assertSee('Create an active account for a Secretary or Distribution Staff member.', false)
            ->assertSee('BARANGAYCARE · OPERATIONS', false)
            ->assertDontSee('Staff and resident account monitoring', false)
            ->assertSee('Staff account coverage', false)
            ->assertSee('Captain profile and security', false)
            ->assertSee('function ProfileHero({ eyebrow, title, description, detail })', false)
            ->assertSee('Your profile changes apply only to your account', false)
            ->assertSee('Resident profile', false)
            ->assertSee('Contact information', false)
            ->assertSee('Official contact details', false)
            ->assertSee('Sign-in security', false)
            ->assertSee('Refresh overview', false)
            ->assertSee('captain-executive-hero', false)
            ->assertSee('ACCOUNT ACCESS', false)
            ->assertSee('Monitored accounts', false)
            ->assertSee('Operational pulse', false)
            ->assertSee('Accounts needing review', false)
            ->assertSee('Awaiting handover', false)
            ->assertSee('Upcoming schedules', false)
            ->assertSee('Aggregate data', false)
            ->assertSee('Recent operations and upcoming schedules', false)
            ->assertSee('LAST 30 DAYS', false)
            ->assertSee('services.recentHandovers', false)
            ->assertSee('counts.upcomingSchedules', false)
            ->assertSee('captain-role-meter', false)
            ->assertSee('captain-monitoring-hero', false)
            ->assertSee('Service monitoring', false)
            ->assertSee('Service activity by team', false)
            ->assertSee('captain-monitoring-stats', false)
            ->assertSee('Active access by office role.', false)
            ->assertSee('Resident service and contact details', false)
            ->assertSee('Staff and Resident Accounts', false)
            ->assertSee('Review status', false)
            ->assertSee('Possible duplicates', false)
            ->assertSee('Filter flagged profiles', false)
            ->assertSee('reviewStatus === "Possible duplicate"', false)
            ->assertDontSee('NEXT COMMUNITY ACTIVITY', false)
            ->assertSee('const canEditResidentAccounts = ["secretary", "assistance_officer", "distribution_staff"].includes(actions.session.role);', false)
            ->assertSee('Activity and Audit Logs', false)
            ->assertSee('Activity Monitor & Audit Logs', false)
            ->assertSee('nav: ["Dashboard Overview", "Beneficiary Management", "Scheduling and Distribution", "Registered Accounts", "Reports and Analytics", "Notifications", "Activity Monitor & Audit Logs", "Logout"]', false)
            ->assertSee('admin: ["Dashboard Overview", "Beneficiary Management", "Scheduling and Distribution"]', false)
            ->assertSee('"activity-monitor": "Activity Monitor & Audit Logs"', false)
            ->assertSee('"activity-and-audit-logs": "Activity Monitor & Audit Logs"', false)
            ->assertSee('includeAuditLogs: true', false)
            ->assertSee('A chronological record of staff, resident, and system actions.', false)
            ->assertSee('Activity monitor & audit logs', false)
            ->assertSee('Recent activity and audit trail', false)
            ->assertSee('monitoring-audit-log', false)
            ->assertSee('nav: ["Dashboard Overview", "Beneficiary Management", "Social Assistance", "Scheduling and Distribution", "Resident Accounts", "Reports and Analytics", "Profile", "Logout"]', false)
            ->assertSee('if (activePage === "Social Assistance") return h(Assistance, { actions, editable: true });', false)
            ->assertSee('if (activePage === "Social Assistance") return h(Assistance, { actions, editable: false });', false)
            ->assertSee('Operations snapshot', false)
            ->assertSee('Beneficiary categories', false)
            ->assertSee('Pending assistance', false)
            ->assertSee('Search actor, action, role, or date', false)
            ->assertSee('Actor role', false)
            ->assertSee('No audit entries match your search and role filters.', false)
            ->assertSee('Registered Accounts', false)
            ->assertSee('staff-management": "Registered Accounts"', false)
            ->assertSee('Search registered accounts', false)
            ->assertSee('Filter by role', false)
            ->assertSee('registered-accounts-summary', false)
            ->assertSee('function ReportSection({ title, description, count, headers, rows, empty })', false)
            ->assertSee('Distributions by benefit', false)
            ->assertSee('matching operational records', false)
            ->assertSee('const secretaryReport = actions.session.role === "secretary";', false)
            ->assertSee('Secretary report for the Barangay Captain', false)
            ->assertSee('recorded distribution handovers available to your account. Audit logs remain restricted.', false)
            ->assertSee('Current profiles; report filters do not apply', false)
            ->assertSee('"secretary", "admin", "assistance_officer", "distribution_staff", "auditor"', false)
            ->assertSee('Secretary Distribution Evidence Report', false)
            ->assertSee('Distribution handover evidence', false)
            ->assertSee('Signed receipts are not stored in this report.', false)
            ->assertSee('Add Resident Account', false)
            ->assertSee('Assistance Applications', false)
            ->assertSee('Application Review', false)
            ->assertSee('Connected service overview', false)
            ->assertSee('RESIDENT SERVICES', false)
            ->assertSee('SECRETARY WORKSPACE', false)
            ->assertSee('DISTRIBUTION WORKSPACE', false)
            ->assertSee('All accounts', false)
            ->assertSee('ACCOUNT LEDGER', false)
            ->assertSee('Account register', false)
            ->assertSee('captain-account-ledger', false)
            ->assertSee('deleteInactiveStaffAccount', false)
            ->assertSee('Deactivate to delete', false)
            ->assertSee('Historical assistance and audit records will be kept.', false)
            ->assertSee('Resident accounts', false)
            ->assertSee('Staff accounts', false)
            ->assertSee('Inactive accounts', false)
            ->assertSee('Shared records', false)
            ->assertSee('Request proof from resident', false)
            ->assertSee('Download securely', false)
            ->assertSee('Open applications', false)
            ->assertSee('Upcoming Schedules', false)
            ->assertSee('Messages & Notifications', false)
            ->assertSee('My Reports / History', false)
            ->assertSee('Programs and Schedule', false)
            ->assertSee('nav: ["Dashboard", "Monitoring", "Staff and Resident Accounts", "Profile", "Logout"]', false)
            ->assertSee('barangay: ["Dashboard", "Monitoring", "Staff and Resident Accounts"]', false)
            ->assertDontSee('["message", "Open resident messages"', false)
            ->assertDontSee('"ai-messages": "Messages and Notifications"', false)
            ->assertDontSee('function StaffMessageDesk(', false)
            ->assertSee('Assigned Staff', false)
            ->assertSee('Program Beneficiary & Release Monitor', false)
            ->assertSee('Program release transactions', false)
            ->assertSee('Assistance release schedule', false)
            ->assertSee('Your scheduled assistance releases', false)
            ->assertSee('Reopened profiles return to the pending review queue.', false)
            ->assertSee('label: "Review status"', false)
            ->assertSee('setReviewStatus("Pending")', false)
            ->assertSee('disabled: verificationUpdatingId === String(beneficiary.id)', false)
            ->assertSee('Benefit Type', false)
            ->assertSee('Choose a benefit type to schedule this assistance for every active resident', false)
            ->assertSee('This schedule will be shown to ${matchingAssistanceBeneficiaries.length} active residents with benefit ${selectedAssistanceBenefit}.', false)
            ->assertSee('{ value: customAssistanceBenefitOption, label: "Others" }', false)
            ->assertSee('Enter the benefit exactly as on the resident account', false)
            ->assertSee('String(values.benefitFilterOther || "").trim()', false)
            ->assertSee('Scheduled ${values.type} for ${matchingBeneficiaries.length} ${selectedBenefit} residents', false)
            ->assertSee('const assistanceRecords = matchingBeneficiaries.map((beneficiary) => {', false)
            ->assertSee('beneficiary: beneficiary.name', false)
            ->assertSee('assistance: [...assistanceRecords, ...actions.data.assistance]', false)
            ->assertDontSee('["beneficiary", "Resident", []]', false)
            ->assertSee('categoryList(beneficiary.category).some((category) => category.toLowerCase() === selectedBenefit.toLowerCase())', false)
            ->assertSee('function DistributionAssistanceDesk({ actions })', false)
            ->assertSee('Assistance claim desk', false)
            ->assertSee('Resident assignment schedule only', false)
            ->assertSee('Confirm handover', false)
            ->assertSee('Only record the handover after the resident has received the cash or items.', false)
            ->assertDontSee('Confirm Received', false)
            ->assertDontSee('Mark Claimed', false)
            ->assertSee('Program link (optional)', false)
            ->assertSee('Only active, eligible residents with pending or completed assistance are ready to claim.', false)
            ->assertSee('const isReadyToClaim = claimableRecords.includes(record);', false)
            ->assertSee('!isReadyToClaim', false)
            ->assertSee('if (isDistributionStaff) {', false)
            ->assertSee('Eligible residents', false)
            ->assertSee('All eligible residents', false)
            ->assertSee('Amount / Items', false)
            ->assertDontSee('Schedule Default', false)
            ->assertDontSee('schedule default', false)
            ->assertSee('Resident allocation: ${assignedAmount}', false)
            ->assertSee('Actual amount or items handed over', false)
            ->assertSee('const defaultAmount = assignedAmount || scheduleAmount;', false)
            ->assertSee('function residentProgramAllocation(program, actions)', false)
            ->assertSee('Planned amount / items for you:', false)
            ->assertSee('Final amount or items will be confirmed when distribution staff records your handover.', false)
            ->assertSee('Edit schedule', false)
            ->assertSee('Planned Release Date', false)
            ->assertSee("Set each resident's planned amount or items here.", false)
            ->assertSee('No assistance record', false)
            ->assertSee('This resident matches the program benefit, but has no assistance record to release.', false)
            ->assertSee('visibleResidents.map(({ beneficiary, matchedBenefits, records }) => [', false)
            ->assertSee('Record handover', false)
            ->assertSee('Program release progress', false)
            ->assertSee('Verify resident', false)
            ->assertSee('Save transaction', false)
            ->assertSee('Select and view residents', false)
            ->assertSee('Previous resident', false)
            ->assertSee('Next resident', false)
            ->assertSee('Resident ${displayedResidentIndex + 1} of ${visibleResidents.length}', false)
            ->assertSee('data-resident-index', false)
            ->assertSee('residentRow?.scrollIntoView({ behavior: "smooth", block: "center" })', false)
            ->assertSee('isDistributionStaff && savedProgramSection', false)
            ->assertSee('Distribution instructions', false)
            ->assertSee('ref: rosterRef', false)
            ->assertSee('programRosterRef.current', false)
            ->assertSee('actions.setQuery("")', false)
            ->assertSee('roster.scrollIntoView({ behavior: "smooth", block: "start" })', false)
            ->assertSee('Eligible resident list', false)
            ->assertDontSee('function Schedules({ programs', false)
            ->assertSee('Saved Program Schedules', false)
            ->assertSee('Schedules created by the Secretary appear here', false)
            ->assertDontSee('Email residents', false)
            ->assertDontSee('emailProgramResidents', false)
            ->assertSee('Sending...', false)
            ->assertSee('Select program', false)
            ->assertSee('Release Status', false)
            ->assertSee('Barangay operations at a glance', false)
            ->assertSee('function MonitoringActivity({ logs, includeAuditLogs = false, id })', false)
            ->assertSee('pending: pendingReleases', false)
            ->assertSee('const beneficiaryCount = (actions.data.beneficiaries || []).length;', false)
            ->assertSee('h("span", { className: "staff-record-count" }, beneficiaryCount)', false)
            ->assertSee('Showing ${rows.length} of ${visibleBeneficiaries.length} resident profiles', false)
            ->assertSee('Clear filters', false)
            ->assertSee('Assistance overview', false)
            ->assertSee('Total records', false)
            ->assertSee('Social assistance overview', false)
            ->assertSee('Monitoring access', false)
            ->assertSee('assistance-application-stages', false)
            ->assertSee('Application decisions are handled by authorized staff.', false)
            ->assertSee('Monitoring view: review assistance records and application progress.', false)
            ->assertSee('aria-pressed', false)
            ->assertSee('Awaiting follow-up', false)
            ->assertSee('No applications have been submitted yet. New requests will appear here for review.', false)
            ->assertSee('Only authorized Assistance Officers and Administrators can view these documents.', false)
            ->assertSee('My Assistance Records', false)
            ->assertSee('Showing ${rows.length} of ${visibleAssistance.length} records', false)
            ->assertSee('No assistance records match your search and status filter.', false)
            ->assertSee('Welcome back, ${firstName}', false)
            ->assertSee('Next eligible schedule', false)
            ->assertSee('Your upcoming program schedules', false)
            ->assertSee('Amount / Items', false)
            ->assertSee('Track assistance, check upcoming schedules, and stay up to date with your barangay.', false)
            ->assertSee('new Date(second.date || 0).getTime() - new Date(first.date || 0).getTime()', false)
            ->assertSee('No new updates', false)
            ->assertSee('Waiting for staff reply', false)
            ->assertSee('COMMON TOPICS', false)
            ->assertSee('How can we help you today?', false)
            ->assertSee('Please do not include passwords or other sensitive account information.', false)
            ->assertSee('messageInputRef.current?.focus()', false)
            ->assertSee('near-limit', false)
            ->assertDontSee('"Settings", "Profile", "Logout"', false)
            ->assertDontSee('openLogin("admin")', false)
            ->assertSee('root.render(h(App));', false);
    }

    public function test_secretary_program_schedules_do_not_offer_email_actions(): void
    {
        $script = $this->get('/assets/app.js')
            ->assertOk()
            ->getContent();
        $programsStart = strpos($script, 'function Programs({ actions, editable = true }) {');
        $programsEnd = strpos($script, "\nfunction ProgramBeneficiaryMonitor", $programsStart);
        $this->assertIsInt($programsStart);
        $this->assertIsInt($programsEnd);

        $programs = substr($script, $programsStart, $programsEnd - $programsStart);
        $this->assertStringContainsString('updateProgramStatus(actions, program)', $programs);
        $this->assertStringNotContainsString('Email residents', $programs);
        $this->assertStringNotContainsString('emailProgramResidents', $programs);
    }

    public function test_secretary_program_schedule_cards_fit_small_screens(): void
    {
        $this->get('/assets/app.css')
            ->assertOk()
            ->assertSee('.secretary-program-grid', false)
            ->assertSee('.secretary-program-details', false)
            ->assertSee('@media (max-width: 640px)', false)
            ->assertSee('.secretary-schedule-heading', false)
            ->assertDontSee('min-width: 1660px', false);
    }

    public function test_distribution_staff_workload_cards_link_to_their_schedule_sections(): void
    {
        $script = $this->get('/assets/app.js')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('const isDistribution = mode === "distribution";', $script);
        $this->assertStringContainsString('id: isDistribution ? "distribution-release-schedule" : undefined', $script);
        $programsStart = strpos($script, 'function Programs({ actions, editable = true }) {');
        $programsEnd = strpos($script, "\nfunction ProgramBeneficiaryMonitor", $programsStart);
        $this->assertIsInt($programsStart);
        $this->assertIsInt($programsEnd);

        $programs = substr($script, $programsStart, $programsEnd - $programsStart);

        $this->assertStringContainsString('scrollToDistributionSection(item.destination)', $programs);
        $this->assertStringContainsString('"distribution-release-schedule"', $programs);
        $this->assertStringContainsString('"distribution-program-schedules"', $programs);
        $this->assertStringContainsString('aria-label: `${item.label}: ${item.value}. ${item.action}.`', $programs);
        $this->assertStringNotContainsString('isDistributionStaff && h(DistributionPageIntro', $programs);
    }

    public function test_reports_and_analytics_excludes_assistance_applications(): void
    {
        $script = $this->get('/assets/app.js')
            ->assertOk()
            ->getContent();
        $reportsStart = strpos($script, 'function Reports({ actions }) {');
        $this->assertIsInt($reportsStart);

        $reportsEnd = strpos($script, "\nfunction ReportSection", $reportsStart);
        $this->assertIsInt($reportsEnd);

        $reports = substr($script, $reportsStart, $reportsEnd - $reportsStart);

        $this->assertStringNotContainsString('reportData.applications', $reports);
        $this->assertStringNotContainsString('application', strtolower($reports));
        $this->assertStringContainsString('reportData.beneficiaries', $reports);
        $this->assertStringContainsString('reportData.assistance', $reports);
        $this->assertStringContainsString('reportData.distributions', $reports);
        $this->assertStringContainsString('reportData.programs', $reports);
        $this->assertStringContainsString('amount: canViewClaimedAmounts ? record.amount : "Confidential"', $reports);
        $this->assertStringContainsString('const canViewClaimedAmounts = ["user", "secretary", "distribution_staff"].includes(actions.session.role)', $reports);
        $this->assertStringContainsString('const assistance = assistanceRecords.filter(', $reports);
        $this->assertStringContainsString('const filteredDistributions = distributions.filter(', $reports);
        $this->assertStringContainsString('record.benefit, record.amount, "Distributed"', $reports);
        $this->assertStringContainsString('record.type, record.amount, record.status', $reports);
        $this->assertStringContainsString('Claimed amounts are confidential and visible only to the resident, Barangay Secretary, and Distribution Staff.', $reports);
        $this->assertStringContainsString('h("h3", null, "Assistance status")', $reports);
        $this->assertStringContainsString('title: isResident ? "My assistance records" : "Assistance assignments"', $reports);
        $this->assertStringContainsString('const canReportDistributions = ["secretary", "admin", "assistance_officer", "distribution_staff", "auditor"]', $reports);
        $this->assertStringContainsString('title: secretaryReport ? "Distribution handover evidence" : "Distribution history"', $reports);
        $this->assertStringContainsString('headers: ["Reference", "Beneficiary", "Benefit", "Amount", "Distributed By", "Date"]', $reports);
        $this->assertStringContainsString('["Distribution", `DIST-${record.id}`', $reports);
        $this->assertStringContainsString('record.distributedBy, displayDate(record.distributedAt)', $reports);
    }

    public function test_resident_history_shows_assistance_claims_without_application_history(): void
    {
        $script = $this->get('/assets/app.js')
            ->assertOk()
            ->getContent();
        $historyStart = strpos($script, 'function ResidentHistory({ actions }) {');
        $this->assertIsInt($historyStart);

        $historyEnd = strpos($script, "\nfunction UserDashboard", $historyStart);
        $this->assertIsInt($historyEnd);

        $history = substr($script, $historyStart, $historyEnd - $historyStart);

        $this->assertStringNotContainsString('Application History', $history);
        $this->assertStringNotContainsString('Previous Assistance Records', $history);
        $this->assertStringNotContainsString('actions.data.applications', $history);
        $this->assertStringContainsString('Assistance and Claim History', $history);
        $this->assertStringContainsString('headers: ["Record", "Assistance", "Date received", "Amount / Items", "Status"]', $history);
        $this->assertStringContainsString('Staff-recorded claims and completed assistance will appear here after distribution.', $history);
    }

    public function test_registered_accounts_route_supports_legacy_urls(): void
    {
        foreach (['/app/admin/registered-accounts', '/app/admin/staff-management', '/app/admin/account-monitoring'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<div id="app"></div>', false);
        }

        $this->get('/app/admin/resident-management')
            ->assertRedirect('/app/admin/registered-accounts');
    }

    public function test_activity_monitor_and_audit_log_urls_open_the_admin_workspace(): void
    {
        foreach (['/app/admin/activity-monitor', '/app/admin/activity-and-audit-logs', '/app/admin/audit-logs'] as $url) {
            $response = $this->get($url);

            $response
                ->assertOk()
                ->assertSee('<div id="app"></div>', false);

            $this->assertFrontendAssets($response);
        }
    }

    public function test_laravel_serves_the_frontend_on_navigation_urls(): void
    {
        foreach (['/login', '/login/barangay', '/login/admin', '/login/secretary', '/login/distribution-staff', '/login/auditor', '/app/barangay/dashboard', '/app/barangay/beneficiaries-assistance', '/app/admin/dashboard-overview', '/app/admin/beneficiary-management', '/app/admin/social-assistance', '/app/admin/scheduling-and-distribution', '/app/admin/registered-accounts', '/app/admin/staff-management', '/app/admin/reports-and-analytics', '/app/admin/activity-and-audit-logs', '/app/admin/activity-monitor', '/app/admin/audit-logs', '/app/admin/overview', '/app/admin/account-monitoring', '/app/secretary/dashboard-overview', '/app/secretary/beneficiary-management', '/app/secretary/social-assistance', '/app/secretary/scheduling-and-distribution', '/app/secretary/resident-accounts', '/app/distribution-staff/scheduling-and-distribution', '/app/distribution-staff/resident-accounts', '/app/auditor/activity-and-audit-logs', '/app/resident/resident-dashboard', '/app/resident/ai-message', '/app/resident/upcoming-schedule', '/app/resident/my-reports-history'] as $url) {
            $response = $this->get($url);

            $response
                ->assertOk()
                ->assertSee('<div id="app"></div>', false);

            $this->assertFrontendAssets($response);
        }
    }

    public function test_assistance_officer_workspace_routes_redirect_to_login(): void
    {
        foreach ([
            '/login/assistance-officer',
            '/app/assistance-officer',
            '/app/assistance-officer/dashboard-overview',
            '/app/assistance-officer/social-assistance',
            '/app/assistance-officer/beneficiary-management',
            '/app/assistance-officer/resident-accounts',
            '/app/assistance-officer/reports-and-analytics',
            '/app/assistance-officer/profile',
        ] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_barangay_account_page_url_serves_the_workspace(): void
    {
        $this->get('/app/barangay/staff-and-resident-accounts')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }

    public function test_captain_and_staff_dashboard_urls_serve_the_workspace(): void
    {
        foreach ([
            '/app/barangay/dashboard',
            '/app/secretary/dashboard-overview',
            '/app/distribution-staff/dashboard-overview',
        ] as $url) {
            $response = $this->get($url);

            $response
                ->assertOk()
                ->assertSee('<div id="app"></div>', false);

            $this->assertFrontendAssets($response);
        }
    }

    public function test_staff_urls_redirect_to_the_original_barangay_workspace(): void
    {
        $this->get('/login/staff')->assertRedirect('/login/barangay');
        $this->get('/app/staff')->assertRedirect('/app/barangay');
        $this->get('/app/staff/dashboard')->assertRedirect('/app/barangay/dashboard');
        $this->get('/app/barangay/beneficiaries-assistance')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }

    public function test_legacy_user_urls_redirect_to_resident_urls(): void
    {
        $this->get('/app/user')->assertRedirect('/app/resident');
        $this->get('/app/user/resident-dashboard')->assertRedirect('/app/resident/resident-dashboard');
        $this->get('/app/user/upcoming-schedule')->assertRedirect('/app/resident/upcoming-schedule');
        $this->get('/app/user/ai-chatbot')->assertRedirect('/app/resident/ai-message');
    }

    public function test_plural_upcoming_schedules_urls_redirect_to_the_supported_resident_page(): void
    {
        $this->get('/app/resident/upcoming-schedules')
            ->assertRedirect('/app/resident/upcoming-schedule');
        $this->get('/app/user/upcoming-schedules')
            ->assertRedirect('/app/resident/upcoming-schedule');

        $this->get('/app/resident/upcoming-schedule')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }

    public function test_resident_messages_aliases_redirect_to_the_supported_messages_page(): void
    {
        $this->get('/app/resident/messages-&-notifications')
            ->assertRedirect('/app/resident/ai-message');

        $this->get('/app/user/messages-&-notifications')
            ->assertRedirect('/app/resident/ai-message');
    }

    public function test_web_routes_match_the_barangaycare_screens(): void
    {
        $this->get('/app')->assertRedirect('/login');

        foreach (['/laravel', '/frontend', '/frontend/', '/missing-page', '/app/staff/not-a-screen', '/app/barangay/residents', '/app/user/not-a-screen', '/login/visitor', '/login/captain', '/app/captain/confidential-records'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_public_account_creation_is_disabled(): void
    {
        $this->get('/signup')->assertRedirect('/login');
        $this->postJson('/api/signup', [
            'name' => 'Public Applicant',
            'username' => 'public_applicant',
            'password' => 'secure-pass-123',
            'password_confirmation' => 'secure-pass-123',
            'role' => 'admin',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['username' => 'public_applicant']);
    }

    public function test_seeding_existing_data_adds_a_super_admin_without_resetting_existing_records(): void
    {
        $this->seed(BarangayCareSeeder::class);
        DB::table('users')->where('username', 'admin')->delete();
        DB::table('users')->where('username', 'barangay')->delete();
        DB::table('beneficiaries')->where('name', 'Maria Santos')->update(['purok' => 'Purok 9']);

        $this->seed(BarangayCareSeeder::class);

        $this->assertDatabaseHas('users', ['username' => 'admin', 'role' => 'Admin', 'status' => 'Active']);
        $this->assertDatabaseHas('users', ['username' => 'barangay', 'role' => 'Barangay', 'status' => 'Active']);
        $this->assertDatabaseHas('beneficiaries', ['name' => 'Maria Santos', 'purok' => 'Purok 9']);
        $this->postJson('/api/auth', [
            'username' => 'admin',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_sign_in_returns_503_with_a_safe_message_when_the_database_fails(): void
    {
        Log::spy();
        DB::shouldReceive('table')
            ->once()
            ->with('users')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused at 127.0.0.1:3306.'));

        $this->postJson('/api/auth', [
            'username' => 'resident',
            'password' => 'password',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error', 'Sign-in is temporarily unavailable. Please try again shortly or contact your barangay administrator.')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('127.0.0.1');

        Log::shouldHaveReceived('error')->once();
    }

    public function test_login_returns_429_after_five_attempts_for_the_same_username_and_ip(): void
    {
        $this->seed(BarangayCareSeeder::class);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/auth', [
                'username' => 'admin',
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth.php', [
            'username' => 'admin',
            'password' => 'incorrect-password',
        ])->assertStatus(429);

        $this->assertDatabaseCount('auth_tokens', 0);
    }

    public function test_fresh_seed_has_no_shared_resident_login_and_secretary_created_resident_can_sign_in(): void
    {
        $this->seed(BarangayCareSeeder::class);

        $this->assertDatabaseHas('users', ['username' => 'admin', 'role' => 'Admin']);
        $this->assertDatabaseHas('users', ['username' => 'barangay', 'role' => 'Barangay']);
        $this->assertDatabaseMissing('users', ['username' => 'user']);
        $this->postJson('/api/auth', ['username' => 'user', 'password' => 'password'])
            ->assertUnauthorized();

        $this->postJson('/api/residents', [
            'name' => 'New Resident',
            'username' => 'new_resident',
            'email' => 'new.resident@example.com',
            'beneficiaryName' => 'Maria Santos',
            'password' => 'secure-pass-123',
        ], $this->roleHeaders('fresh_secretary', 'Secretary'))->assertCreated();

        $this->postJson('/api/auth', [
            'username' => 'new_resident',
            'password' => 'secure-pass-123',
        ])->assertOk()->assertJsonPath('user.role', 'user');
    }

    public function test_seeding_deactivates_the_unchanged_shared_demo_resident_account(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();

        $this->seed(BarangayCareSeeder::class);

        $this->assertDatabaseHas('users', ['username' => 'user', 'status' => 'Inactive']);
        $this->postJson('/api/auth', ['username' => 'user', 'password' => 'password'])
            ->assertUnauthorized();
    }

    public function test_secretary_can_create_a_linked_resident_account(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('resident_secretary', 'Secretary');

        $this->postJson('/api/residents', [
            'name' => 'Alicia Resident',
            'username' => 'Alicia_R',
            'email' => 'alicia@example.com',
            'contact' => '09171234567',
            'address' => 'Purok 1',
            'beneficiaryName' => 'Maria Santos',
            'categories' => ['Senior Citizen', 'PWD'],
            'password' => 'secure-pass-123',
            'role' => 'Admin',
            'status' => 'Inactive',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('user.username', 'alicia_r')
            ->assertJsonPath('user.role', 'user');

        $this->assertDatabaseHas('users', ['username' => 'alicia_r', 'role' => 'User']);
        $this->postJson('/api/auth', [
            'username' => 'alicia_r',
            'password' => 'secure-pass-123',
        ])->assertOk()->assertJsonPath('user.role', 'user');
    }

    public function test_secretary_can_create_a_resident_account_without_email_or_contact(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('optional_contact_secretary', 'Secretary');

        $this->postJson('/api/residents', [
            'name' => 'Resident Without Contact Details',
            'username' => 'resident_no_contact',
            'password' => 'secure-pass-123',
            'beneficiaryName' => 'Maria Santos',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('user.username', 'resident_no_contact')
            ->assertJsonPath('user.role', 'user');

        $this->assertDatabaseHas('users', [
            'username' => 'resident_no_contact',
            'email' => '',
            'contact' => '',
            'status' => 'Active',
        ]);
        $this->assertDatabaseHas('logs', [
            'actor' => 'optional_contact_secretary',
            'action' => 'Created resident account resident_no_contact for Maria Santos',
        ]);

        $this->postJson('/api/residents', [
            'name' => 'Resident With Invalid Email',
            'username' => 'resident_invalid_email',
            'password' => 'secure-pass-123',
            'email' => 'not-an-email',
            'beneficiaryName' => 'Maria Santos',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_returns_401_when_beneficiary_verification_has_no_token(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $beneficiaryId = DB::table('beneficiaries')->where('name', 'Maria Santos')->value('id');

        $this->patchJson("/api/beneficiaries/{$beneficiaryId}/verification", [
            'status' => 'Verified',
        ])->assertUnauthorized();

        $this->assertDatabaseHas('beneficiaries', [
            'id' => $beneficiaryId,
            'verification_status' => 'Pending',
        ]);
    }

    public function test_returns_403_when_barangay_captain_attempts_beneficiary_verification(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $beneficiaryId = DB::table('beneficiaries')->where('name', 'Maria Santos')->value('id');
        $headers = $this->authHeaders($this->tokenFor('barangay'));

        $this->patchJson("/api/beneficiaries/{$beneficiaryId}/verification", [
            'status' => 'Verified',
        ], $headers)->assertForbidden();

        $this->assertDatabaseHas('beneficiaries', [
            'id' => $beneficiaryId,
            'verification_status' => 'Pending',
        ]);
    }

    public function test_secretary_can_verify_a_beneficiary_and_audit_the_change(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('verify_secretary', 'Secretary');
        $beneficiaryId = DB::table('beneficiaries')->where('name', 'Maria Santos')->value('id');
        $secretaryId = DB::table('users')->where('username', 'verify_secretary')->value('id');

        $this->patchJson("/api/beneficiaries/{$beneficiaryId}/verification", [
            'status' => 'Verified',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('beneficiary.verificationStatus', 'Verified');

        $this->assertDatabaseHas('beneficiaries', [
            'id' => $beneficiaryId,
            'verification_status' => 'Verified',
            'verified_by' => $secretaryId,
        ]);
        $this->assertNotNull(DB::table('beneficiaries')->where('id', $beneficiaryId)->value('verified_at'));
        $this->assertDatabaseHas('logs', [
            'actor' => 'Verify Secretary',
            'action' => "Marked beneficiary {$beneficiaryId} as Verified",
        ]);
    }

    public function test_returns_422_when_beneficiary_verification_status_is_invalid(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('invalid_status_secretary', 'Secretary');
        $beneficiaryId = DB::table('beneficiaries')->where('name', 'Maria Santos')->value('id');

        $this->patchJson("/api/beneficiaries/{$beneficiaryId}/verification", [
            'status' => 'Rejected',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('beneficiaries', [
            'id' => $beneficiaryId,
            'verification_status' => 'Pending',
            'verified_by' => null,
            'verified_at' => null,
        ]);
    }

    public function test_reopening_beneficiary_review_clears_verification_metadata(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('reopen_secretary', 'Secretary');
        $secretaryId = DB::table('users')->where('username', 'reopen_secretary')->value('id');
        $beneficiaryId = DB::table('beneficiaries')->where('name', 'Maria Santos')->value('id');
        DB::table('beneficiaries')->where('id', $beneficiaryId)->update([
            'verification_status' => 'Verified',
            'verified_by' => $secretaryId,
            'verified_at' => now(),
        ]);

        $this->patchJson("/api/beneficiaries/{$beneficiaryId}/verification", [
            'status' => 'Pending',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('beneficiary.verificationStatus', 'Pending');

        $this->assertDatabaseHas('beneficiaries', [
            'id' => $beneficiaryId,
            'verification_status' => 'Pending',
            'verified_by' => null,
            'verified_at' => null,
        ]);
    }

    public function test_general_beneficiary_saves_cannot_forge_verification_or_duplicate_flags(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('metadata_secretary', 'Secretary');
        $beneficiaryId = DB::table('beneficiaries')->where('name', 'Maria Santos')->value('id');
        $secretaryId = DB::table('users')->where('username', 'metadata_secretary')->value('id');
        $this->patchJson("/api/beneficiaries/{$beneficiaryId}/verification", [
            'status' => 'Verified',
        ], $headers)->assertOk();

        $state = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        foreach ($state['beneficiaries'] as &$beneficiary) {
            $beneficiary['verificationStatus'] = 'Pending';
            $beneficiary['verifiedAt'] = null;
            $beneficiary['possibleDuplicate'] = true;
        }
        unset($beneficiary);

        $this->postJson('/api/data', ['data' => $state], $headers)->assertOk();

        $this->assertDatabaseHas('beneficiaries', [
            'id' => $beneficiaryId,
            'verification_status' => 'Verified',
            'verified_by' => $secretaryId,
            'possible_duplicate' => false,
        ]);
    }

    public function test_saves_duplicate_beneficiaries_and_flags_name_and_contact_matches(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('duplicate_secretary', 'Secretary');
        $existing = DB::table('beneficiaries')->where('name', 'Maria Santos')->first();
        DB::table('beneficiaries')->where('id', $existing->id)->update(['contact' => '0917-123-4567']);
        $state = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $state['beneficiaries'][] = [
            'id' => 'B-DUPLICATE-NAME',
            'name' => '  maria   santos  ',
            'purok' => 'Purok 1',
            'category' => 'Senior Citizen',
            'contact' => '',
            'status' => 'Active',
            'lastAid' => '',
            'updated' => now()->toDateString(),
        ];
        $state['beneficiaries'][] = [
            'id' => 'B-DUPLICATE-CONTACT',
            'name' => 'Another Resident',
            'purok' => 'Purok 2',
            'category' => 'PWD',
            'contact' => '0917 123 4567',
            'status' => 'Active',
            'lastAid' => '',
            'updated' => now()->toDateString(),
        ];

        $response = $this->postJson('/api/data', ['data' => $state], $headers)->assertOk();
        $savedBeneficiaries = collect($response->json('data.beneficiaries'))->keyBy('id');

        $this->assertTrue($savedBeneficiaries[$existing->id]['possibleDuplicate']);
        $this->assertTrue($savedBeneficiaries['B-DUPLICATE-NAME']['possibleDuplicate']);
        $this->assertTrue($savedBeneficiaries['B-DUPLICATE-CONTACT']['possibleDuplicate']);
        $this->assertDatabaseHas('beneficiaries', ['id' => $existing->id, 'possible_duplicate' => true]);
        $this->assertDatabaseHas('beneficiaries', ['id' => 'B-DUPLICATE-NAME', 'possible_duplicate' => true]);
        $this->assertDatabaseHas('beneficiaries', ['id' => 'B-DUPLICATE-CONTACT', 'possible_duplicate' => true]);

        $updatedState = $response->json('data');
        foreach ($updatedState['beneficiaries'] as &$beneficiary) {
            if ($beneficiary['id'] === 'B-DUPLICATE-CONTACT') {
                $beneficiary['contact'] = '0999 888 7777';
            }
        }
        unset($beneficiary);

        $this->postJson('/api/data', ['data' => $updatedState], $headers)->assertOk();
        $this->assertDatabaseHas('beneficiaries', ['id' => 'B-DUPLICATE-CONTACT', 'possible_duplicate' => false]);
    }

    public function test_secretary_can_create_a_resident_with_a_matching_contact_and_flag_the_possible_duplicate(): void
    {
        $this->seed(BarangayCareSeeder::class);
        DB::table('beneficiaries')->where('name', 'Maria Santos')->update(['contact' => '09171234567']);
        $headers = $this->roleHeaders('contact_duplicate_secretary', 'Secretary');

        $this->postJson('/api/residents', [
            'name' => 'New Contact Match',
            'username' => 'new_contact_match',
            'email' => 'new.contact.match@example.com',
            'contact' => '0917 123 4567',
            'password' => 'secure-pass-123',
        ], $headers)->assertCreated();

        $newBeneficiaryId = DB::table('beneficiaries')->where('name', 'New Contact Match')->value('id');
        $this->assertDatabaseHas('beneficiaries', ['name' => 'Maria Santos', 'possible_duplicate' => true]);
        $this->assertDatabaseHas('beneficiaries', [
            'id' => $newBeneficiaryId,
            'verification_status' => 'Pending',
            'possible_duplicate' => true,
        ]);
    }

    public function test_resident_reads_do_not_expose_internal_verification_or_duplicate_flags(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        DB::table('beneficiaries')->where('name', 'Maria Santos')->update([
            'possible_duplicate' => true,
            'verification_status' => 'Verified',
            'verified_at' => now(),
        ]);
        $headers = $this->authHeaders($this->tokenFor('user'));

        $beneficiary = $this->getJson('/api/data', $headers)
            ->assertOk()
            ->json('data.beneficiaries.0');

        $this->assertArrayNotHasKey('possibleDuplicate', $beneficiary);
        $this->assertArrayNotHasKey('verificationStatus', $beneficiary);
        $this->assertArrayNotHasKey('verifiedAt', $beneficiary);
    }

    public function test_barangay_captain_can_monitor_but_cannot_read_or_update_resident_records(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $headers = $this->authHeaders($this->tokenFor('barangay'));
        $data = $this->getJson('/api/data', $headers)
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data['beneficiaries']);
        $this->assertSame([], $data['assistance']);
        $this->assertSame([], $data['applications']);
        $this->assertSame([], $data['distributions']);
        $this->assertSame([], $data['messages']);
        $this->assertSame([], $data['logs']);
        $this->assertSame(1, $data['monitoring']['residentAccounts']);
        $assistanceCount = DB::table('assistance')->count();

        $this->postJson('/api/data', ['data' => $data], $headers)
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');

        $this->assertDatabaseHas('programs', ['id' => 'P-101', 'name' => 'Food Pack Distribution']);
        $this->assertDatabaseHas('beneficiaries', ['id' => 'B-2026-001', 'name' => 'Maria Santos']);
        $this->assertDatabaseHas('users', ['username' => 'admin', 'role' => 'Admin']);
        $this->assertSame($assistanceCount, DB::table('assistance')->count());
    }

    public function test_barangay_cannot_export_a_full_system_backup(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->authHeaders($this->tokenFor('barangay'));

        $this->getJson('/api/backup', $headers)
            ->assertForbidden()
            ->assertJsonPath('error', 'Backup exports are not available to monitoring-only accounts.');
    }

    public function test_only_the_secretary_can_create_resident_accounts(): void
    {
        $this->seed(BarangayCareSeeder::class);

        $this->postJson('/api/residents', [
            'name' => 'Unauthorized Resident',
            'username' => 'unauthorized_resident',
            'beneficiaryName' => 'Maria Santos',
            'password' => 'secure-pass-123',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Please sign in again.');

        $this->postJson('/api/residents', [
            'name' => 'Unauthorized Resident',
            'username' => 'unauthorized_resident',
            'beneficiaryName' => 'Maria Santos',
            'password' => 'secure-pass-123',
        ], $this->authHeaders($this->tokenFor('user')))
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');

        $this->postJson('/api/residents', [
            'name' => 'Unauthorized Resident',
            'username' => 'admin_resident',
            'beneficiaryName' => 'Maria Santos',
            'password' => 'secure-pass-123',
        ], $this->authHeaders($this->tokenFor('admin')))
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');

        $this->postJson('/api/residents', [
            'name' => 'Legacy Staff Resident',
            'username' => 'legacy_staff_resident',
            'email' => 'legacy.staff@example.com',
            'password' => 'secure-pass-123',
        ], $this->roleHeaders('legacy_resident_staff', 'Staff'))
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');

        $this->postJson('/api/residents', [
            'name' => 'Captain Resident',
            'username' => 'captain_resident',
            'email' => 'captain.resident@example.com',
            'password' => 'secure-pass-123',
        ], $this->authHeaders($this->tokenFor('barangay')))
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');

        $this->postJson('/api/residents', [
            'name' => 'Secretary Resident',
            'username' => 'secretary_can_provision',
            'email' => 'secretary.provision@example.com',
            'password' => 'secure-pass-123',
        ], $this->roleHeaders('resident_provision_secretary', 'Secretary'))
            ->assertCreated();

        $this->assertDatabaseMissing('users', ['username' => 'unauthorized_resident']);
        $this->assertDatabaseMissing('users', ['username' => 'admin_resident']);
        $this->assertDatabaseMissing('users', ['username' => 'legacy_staff_resident']);
        $this->assertDatabaseMissing('users', ['username' => 'captain_resident']);
        $this->assertDatabaseHas('users', ['username' => 'secretary_can_provision']);
    }

    public function test_barangay_captain_can_create_each_allowed_staff_role_and_secretary_cannot(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));

        foreach ([
            ['Secretary', 'created_secretary'],
            ['Assistance Officer', 'created_officer'],
            ['Distribution Staff', 'created_distribution_staff'],
        ] as [$role, $username]) {
            $this->postJson('/api/staff/accounts', [
                'name' => 'Created '.$role,
                'role' => $role,
                'username' => $username,
                'password' => 'secure-pass-123',
                'email' => "{$username}@example.com",
                'contact' => '09171234567',
                'position' => 'Barangay Office',
            ], $captainHeaders)
                ->assertCreated()
                ->assertJsonPath('user.username', $username)
                ->assertJsonPath('user.role', match ($role) {
                    'Secretary' => 'secretary',
                    'Assistance Officer' => 'assistance_officer',
                    'Distribution Staff' => 'distribution_staff',
                })
                ->assertJsonMissingPath('user.password');

            $this->assertDatabaseHas('users', [
                'name' => 'Created '.$role,
                'role' => $role,
                'username' => $username,
                'status' => 'Active',
                'email' => "{$username}@example.com",
                'contact' => '09171234567',
                'position' => 'Barangay Office',
            ]);
            $this->assertTrue(Hash::check('secure-pass-123', (string) DB::table('users')->where('username', $username)->value('password')));
        }

        $this->getJson('/api/data', $captainHeaders)
            ->assertOk()
            ->assertJsonFragment(['name' => 'Created Secretary', 'role' => 'Secretary', 'status' => 'Active'])
            ->assertJsonMissingPath('data.users.0.email');

        $this->assertDatabaseHas('logs', [
            'actor' => 'Barangay Staff',
            'action' => 'Created Secretary staff account created_secretary',
        ]);

        $this->postJson('/api/staff/accounts', [
            'name' => 'Duplicate Email Staff',
            'role' => 'Secretary',
            'username' => 'duplicate_email_staff',
            'password' => 'secure-pass-123',
            'email' => 'created_secretary@example.com',
        ], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'That email address is already in use.');

        $this->postJson('/api/auth', [
            'username' => 'created_secretary',
            'password' => 'secure-pass-123',
        ])->assertOk()->assertJsonPath('user.role', 'secretary');

        $secretaryHeaders = $this->roleHeaders('staff_creation_secretary', 'Secretary');
        $this->postJson('/api/staff/accounts', [
            'name' => 'Unauthorized Staff',
            'role' => 'Secretary',
            'username' => 'unauthorized_staff',
            'password' => 'secure-pass-123',
        ], $secretaryHeaders)
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');

        $this->assertDatabaseMissing('users', ['username' => 'unauthorized_staff']);
    }

    public function test_barangay_captain_cannot_create_privileged_or_resident_accounts_or_duplicate_staff_usernames(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->authHeaders($this->tokenFor('barangay'));
        $payload = [
            'name' => 'Attempted Account',
            'username' => 'attempted_account',
            'password' => 'secure-pass-123',
        ];

        foreach (['Admin', 'Barangay', 'User', 'Read-only Auditor', 'Assistance Officer'] as $role) {
            $this->postJson('/api/staff/accounts', [...$payload, 'role' => $role], $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('role');
        }

        $this->postJson('/api/staff/accounts', [
            ...$payload,
            'role' => 'Secretary',
            'username' => 'created_resident',
            'password' => 'short',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/staff/accounts', [
            ...$payload,
            'role' => 'Secretary',
            'username' => 'admin',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'That username is already taken. Please choose another.');

        $this->assertDatabaseMissing('users', ['username' => 'attempted_account']);
        $this->assertDatabaseMissing('users', ['username' => 'created_resident']);
    }

    public function test_admin_can_create_only_barangay_captain_accounts(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $adminHeaders = $this->authHeaders($this->tokenFor('admin'));
        $payload = [
            'name' => 'New Barangay Captain',
            'username' => 'new_barangay_captain',
            'password' => 'secure-pass-123',
            'email' => 'captain@example.com',
        ];

        $this->postJson('/api/staff/accounts', [...$payload, 'role' => 'Barangay Captain'], $adminHeaders)
            ->assertCreated()
            ->assertJsonPath('user.role', 'barangay')
            ->assertJsonPath('user.name', 'New Barangay Captain')
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', [
            'username' => 'new_barangay_captain',
            'role' => 'Barangay',
            'status' => 'Active',
        ]);
        $this->assertTrue(Hash::check('secure-pass-123', (string) DB::table('users')->where('username', 'new_barangay_captain')->value('password')));

        foreach (['Admin', 'Barangay', 'Secretary', 'Assistance Officer', 'Distribution Staff', 'User', 'Read-only Auditor'] as $role) {
            $username = 'admin_attempt_'.strtolower(str_replace(' ', '_', $role));
            $this->postJson('/api/staff/accounts', [
                ...$payload,
                'username' => $username,
                'role' => $role,
            ], $adminHeaders)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('role');

            $this->assertDatabaseMissing('users', ['username' => $username]);
        }
    }

    public function test_barangay_captain_can_deactivate_and_reactivate_staff_accounts_and_old_tokens_stay_revoked(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));
        $staff = $this->postJson('/api/staff/accounts', [
            'name' => 'Status Test Officer',
            'role' => 'Assistance Officer',
            'username' => 'status_test_officer',
            'password' => 'secure-pass-123',
        ], $captainHeaders)
            ->assertCreated()
            ->json('user');
        $staffId = $staff['id'];
        $staffHeaders = $this->authHeaders($this->tokenFor('status_test_officer', 'secure-pass-123'));

        $this->patchJson("/api/staff/accounts/{$staffId}/status", ['status' => 'Inactive'], $captainHeaders)
            ->assertOk()
            ->assertJsonPath('user.id', $staffId)
            ->assertJsonPath('user.status', 'Inactive')
            ->assertJsonPath('message', 'Staff account status updated.');

        $this->assertDatabaseHas('users', ['id' => $staffId, 'status' => 'Inactive']);
        $this->assertDatabaseMissing('auth_tokens', ['user_id' => $staffId]);
        $this->getJson('/api/data', $staffHeaders)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Please sign in again.');

        $this->patchJson("/api/staff/accounts/{$staffId}/status", ['status' => 'Active'], $captainHeaders)
            ->assertOk()
            ->assertJsonPath('user.status', 'Active');

        $this->assertDatabaseHas('users', ['id' => $staffId, 'status' => 'Active']);
        $this->getJson('/api/data', $staffHeaders)->assertUnauthorized();
        $this->postJson('/api/auth', [
            'username' => 'status_test_officer',
            'password' => 'secure-pass-123',
        ])->assertOk()->assertJsonPath('user.role', 'assistance_officer');
        $this->assertDatabaseHas('logs', [
            'actor' => 'Barangay Staff',
            'action' => 'Inactive Assistance Officer account status_test_officer',
        ]);
    }

    public function test_captain_can_reset_a_staff_password_and_staff_must_change_it_before_continuing(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));
        $staff = $this->postJson('/api/staff/accounts', [
            'name' => 'Resettable Secretary',
            'role' => 'Secretary',
            'username' => 'resettable_secretary',
            'password' => 'old-staff-password',
        ], $captainHeaders)
            ->assertCreated()
            ->json('user');
        $staffId = $staff['id'];
        $oldStaffHeaders = $this->authHeaders($this->tokenFor('resettable_secretary', 'old-staff-password'));

        $this->patchJson("/api/staff/accounts/{$staffId}/password", [
            'newPassword' => 'TemporaryStaffPass2026',
            'newPassword_confirmation' => 'TemporaryStaffPass2026',
        ], $captainHeaders)
            ->assertOk()
            ->assertJsonPath('message', 'Staff password reset. The staff member must choose a new password at next sign-in.')
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', [
            'id' => $staffId,
            'must_change_password' => true,
        ]);
        $this->assertTrue(Hash::check('TemporaryStaffPass2026', DB::table('users')->where('id', $staffId)->value('password')));
        $this->assertDatabaseMissing('auth_tokens', ['user_id' => $staffId]);
        $this->assertDatabaseHas('logs', [
            'actor' => 'Barangay Staff',
            'action' => 'Reset the password for Secretary account resettable_secretary',
        ]);
        $this->assertDatabaseMissing('logs', ['action' => 'TemporaryStaffPass2026']);
        $this->getJson('/api/data', $oldStaffHeaders)->assertUnauthorized();

        $temporaryLogin = $this->postJson('/api/auth', [
            'username' => 'resettable_secretary',
            'password' => 'TemporaryStaffPass2026',
        ])
            ->assertOk()
            ->assertJsonPath('user.mustChangePassword', true);
        $temporaryHeaders = $this->authHeaders($temporaryLogin->json('token'));
        $this->getJson('/api/data', $temporaryHeaders)
            ->assertForbidden()
            ->assertJsonPath('password_change_required', true);

        $this->postJson('/api/password', [
            'username' => 'resettable_secretary',
            'currentPassword' => 'TemporaryStaffPass2026',
            'newPassword' => 'PermanentStaffPass2026',
            'newPassword_confirmation' => 'PermanentStaffPass2026',
        ], $temporaryHeaders)
            ->assertOk()
            ->assertJsonPath('message', 'Password updated.');

        $this->assertDatabaseHas('users', [
            'id' => $staffId,
            'must_change_password' => false,
        ]);
        $this->getJson('/api/data', $temporaryHeaders)->assertOk();
        $this->postJson('/api/auth', [
            'username' => 'resettable_secretary',
            'password' => 'PermanentStaffPass2026',
        ])->assertOk()->assertJsonPath('user.mustChangePassword', false);
    }

    public function test_only_the_captain_can_reset_a_staff_password(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));
        $staff = $this->postJson('/api/staff/accounts', [
            'name' => 'Password Reset Target',
            'role' => 'Distribution Staff',
            'username' => 'password_reset_target',
            'password' => 'old-staff-password',
        ], $captainHeaders)
            ->assertCreated()
            ->json('user');
        $staffId = $staff['id'];
        $staffHeaders = $this->authHeaders($this->tokenFor('password_reset_target', 'old-staff-password'));

        $this->patchJson("/api/staff/accounts/{$staffId}/password", [
            'newPassword' => 'TemporaryStaffPass2026',
            'newPassword_confirmation' => 'TemporaryStaffPass2026',
        ])->assertUnauthorized();
        $this->patchJson("/api/staff/accounts/{$staffId}/password", [
            'newPassword' => 'TemporaryStaffPass2026',
            'newPassword_confirmation' => 'TemporaryStaffPass2026',
        ], $staffHeaders)
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');
        $adminId = DB::table('users')->where('username', 'admin')->value('id');
        $this->patchJson("/api/staff/accounts/{$adminId}/password", [
            'newPassword' => 'TemporaryStaffPass2026',
            'newPassword_confirmation' => 'TemporaryStaffPass2026',
        ], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Choose a Secretary, Assistance Officer, or Distribution Staff account.');

        $this->assertDatabaseHas('users', [
            'id' => $staffId,
            'must_change_password' => false,
        ]);
        $this->assertTrue(Hash::check('old-staff-password', DB::table('users')->where('id', $staffId)->value('password')));
        $this->assertTrue(Hash::check('password', DB::table('users')->where('username', 'admin')->value('password')));
    }

    public function test_captain_password_reset_rejects_short_or_unconfirmed_passwords_without_changes(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));
        $staff = $this->postJson('/api/staff/accounts', [
            'name' => 'Password Validation Target',
            'role' => 'Secretary',
            'username' => 'password_validation_target',
            'password' => 'old-staff-password',
        ], $captainHeaders)
            ->assertCreated()
            ->json('user');
        $staffId = $staff['id'];
        $staffHeaders = $this->authHeaders($this->tokenFor('password_validation_target', 'old-staff-password'));

        $this->patchJson("/api/staff/accounts/{$staffId}/password", [
            'newPassword' => 'short',
            'newPassword_confirmation' => 'short',
        ], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('newPassword');
        $this->patchJson("/api/staff/accounts/{$staffId}/password", [
            'newPassword' => 'TemporaryStaffPass2026',
            'newPassword_confirmation' => 'DifferentTemporaryPass2026',
        ], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('newPassword');

        $this->assertDatabaseHas('users', [
            'id' => $staffId,
            'must_change_password' => false,
        ]);
        $this->assertTrue(Hash::check('old-staff-password', DB::table('users')->where('id', $staffId)->value('password')));
        $this->getJson('/api/data', $staffHeaders)->assertOk();
    }

    public function test_barangay_captain_can_delete_inactive_staff_account_without_removing_historical_records(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));
        $staff = $this->postJson('/api/staff/accounts', [
            'name' => 'Unavailable Distribution Staff',
            'role' => 'Distribution Staff',
            'username' => 'unavailable_staff',
            'password' => 'secure-pass-123',
        ], $captainHeaders)
            ->assertCreated()
            ->json('user');
        $staffId = $staff['id'];
        $this->patchJson("/api/staff/accounts/{$staffId}/status", ['status' => 'Inactive'], $captainHeaders)
            ->assertOk();
        DB::table('assistance')->insert([
            'id' => 'historical-staff-record',
            'beneficiary' => 'Maria Santos',
            'type' => 'Cash Assistance',
            'amount' => 'PHP 1,000',
            'date' => '2026-10-01',
            'status' => 'Claimed',
            'staff' => 'Unavailable Distribution Staff',
            'claimed_at' => '2026-10-01',
            'claimed_by' => 'Unavailable Distribution Staff',
        ]);
        DB::table('logs')->insert([
            'event_time' => '2026-10-01 10:00:00',
            'actor' => 'Unavailable Distribution Staff',
            'action' => 'Recorded an assistance handover',
        ]);
        DB::table('auth_tokens')->insert([
            'user_id' => $staffId,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => null,
            'created_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'unavailable-staff-session',
            'user_id' => $staffId,
            'payload' => 'test-session',
            'last_activity' => now()->timestamp,
        ]);
        $historicalRecord = DB::table('assistance')->where('id', 'historical-staff-record')->first();

        $this->deleteJson("/api/staff/accounts/{$staffId}", [], $captainHeaders)
            ->assertOk()
            ->assertJsonPath('message', 'Inactive staff account deleted.')
            ->assertJsonPath('user.id', $staffId)
            ->assertJsonPath('user.username', 'unavailable_staff');

        $this->assertDatabaseMissing('users', ['id' => $staffId]);
        $this->assertDatabaseMissing('auth_tokens', ['user_id' => $staffId]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $staffId]);
        $this->assertSame(
            (array) $historicalRecord,
            (array) DB::table('assistance')->where('id', 'historical-staff-record')->first()
        );
        $this->assertDatabaseHas('logs', [
            'actor' => 'Barangay Staff',
            'action' => 'Deleted inactive Distribution Staff account unavailable_staff',
        ]);
        $this->assertDatabaseHas('logs', [
            'actor' => 'Unavailable Distribution Staff',
            'action' => 'Recorded an assistance handover',
        ]);
    }

    public function test_only_captain_can_delete_inactive_staff_accounts_and_active_or_nonstaff_accounts_are_rejected(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));
        $staffHeaders = $this->roleHeaders('delete_guard_staff', 'Distribution Staff');
        $staffId = (int) DB::table('users')->where('username', 'delete_guard_staff')->value('id');
        $residentId = (int) DB::table('users')->where('username', 'user')->value('id');
        $adminId = (int) DB::table('users')->where('username', 'admin')->value('id');

        $this->deleteJson("/api/staff/accounts/{$staffId}", [], $staffHeaders)
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');
        $this->deleteJson("/api/staff/accounts/{$staffId}")
            ->assertUnauthorized();
        $this->deleteJson("/api/staff/accounts/{$staffId}", [], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Only inactive staff accounts can be deleted.');

        $this->patchJson("/api/staff/accounts/{$staffId}/status", ['status' => 'Inactive'], $captainHeaders)
            ->assertOk();
        $this->deleteJson("/api/staff/accounts/{$residentId}", [], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Choose a Secretary, Assistance Officer, or Distribution Staff account.');
        $this->deleteJson("/api/staff/accounts/{$adminId}", [], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Choose a Secretary, Assistance Officer, or Distribution Staff account.');

        $this->assertDatabaseHas('users', ['id' => $staffId, 'status' => 'Inactive']);
        $this->assertDatabaseHas('users', ['id' => $residentId, 'role' => 'User']);
        $this->assertDatabaseHas('users', ['id' => $adminId, 'role' => 'Admin']);
    }

    public function test_only_captain_can_change_status_of_staff_accounts_and_resident_or_privileged_accounts_are_rejected(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $staffHeaders = $this->roleHeaders('status_guard_officer', 'Assistance Officer');
        $staffId = (int) DB::table('users')->where('username', 'status_guard_officer')->value('id');
        $residentId = (int) DB::table('users')->where('username', 'user')->value('id');
        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));

        $this->patchJson("/api/staff/accounts/{$staffId}/status", ['status' => 'Inactive'], $staffHeaders)
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have permission to do that.');
        $this->patchJson("/api/staff/accounts/{$residentId}/status", ['status' => 'Inactive'], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Choose a Secretary, Assistance Officer, or Distribution Staff account.');
        $adminId = (int) DB::table('users')->where('username', 'admin')->value('id');
        $this->patchJson("/api/staff/accounts/{$adminId}/status", ['status' => 'Inactive'], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Choose a Secretary, Assistance Officer, or Distribution Staff account.');
        $this->patchJson("/api/staff/accounts/{$staffId}/status", ['status' => 'Pending'], $captainHeaders)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->patchJson("/api/staff/accounts/{$staffId}/status", ['status' => 'Inactive'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('users', ['id' => $staffId, 'status' => 'Active']);
        $this->assertDatabaseHas('users', ['id' => $residentId, 'role' => 'User', 'status' => 'Active']);
    }

    public function test_all_staff_roles_can_edit_resident_accounts_but_captain_and_resident_cannot(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $residentId = (int) DB::table('users')->where('username', 'user')->value('id');
        $payload = [
            'name' => 'Updated Resident',
            'username' => 'updated_resident',
            'email' => 'updated.resident@example.com',
            'contact' => '09170000000',
            'address' => 'Purok 4',
            'status' => 'Inactive',
            'password' => 'updated-password-123',
            'role' => 'Admin',
        ];

        $this->patchJson("/api/residents/{$residentId}", $payload, $this->authHeaders($this->tokenFor('barangay')))
            ->assertForbidden();
        $this->patchJson("/api/residents/{$residentId}", $payload, $this->authHeaders($this->tokenFor('user')))
            ->assertForbidden();
        $this->patchJson("/api/residents/{$residentId}", $payload, $this->roleHeaders('readonly_auditor', 'Read-only Auditor'))
            ->assertForbidden();

        foreach ([
            ['resident_edit_secretary', 'Secretary'],
            ['resident_edit_officer', 'Assistance Officer'],
            ['resident_edit_distribution', 'Distribution Staff'],
        ] as [$username, $role]) {
            $payload['username'] = "{$username}_resident";
            $payload['email'] = "{$username}@example.com";
            $headers = $this->roleHeaders($username, $role);
            $this->patchJson("/api/residents/{$residentId}", $payload, $headers)
                ->assertOk()
                ->assertJsonPath('user.role', 'user')
                ->assertJsonPath('user.username', $payload['username']);
            $this->getJson('/api/data', $headers)
                ->assertOk()
                ->assertJsonPath('data.users.0.id', $residentId)
                ->assertJsonFragment(['username' => $username]);
        }

        $this->assertDatabaseHas('users', [
            'id' => $residentId,
            'role' => 'User',
            'name' => 'Updated Resident',
            'username' => 'resident_edit_distribution_resident',
            'contact' => '09170000000',
            'address' => 'Purok 4',
            'status' => 'Inactive',
        ]);
        $this->assertDatabaseMissing('users', ['id' => $residentId, 'role' => 'Admin']);
        $this->assertTrue(Hash::check('updated-password-123', (string) DB::table('users')->where('id', $residentId)->value('password')));

        $staffId = (int) DB::table('users')->where('username', 'admin')->value('id');
        $this->patchJson("/api/residents/{$staffId}", $payload, $this->roleHeaders('resident_edit_guard', 'Secretary'))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Choose a registered resident account.');

        $duplicatePayload = [...$payload, 'username' => 'admin'];
        $this->patchJson("/api/residents/{$residentId}", $duplicatePayload, $this->roleHeaders('resident_duplicate_guard', 'Secretary'))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'That username is already taken. Please choose another.');
    }

    public function test_admin_can_monitor_but_cannot_write_system_records_except_creating_barangay_captains(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->authHeaders($this->tokenFor('admin'));
        $data = $this->getJson('/api/data', $headers)
            ->assertOk()
            ->json('data');
        $data['beneficiaries'][0]['contact'] = '09990000000';

        $this->postJson('/api/data', ['data' => $data], $headers)->assertForbidden();
        $this->postJson('/api/residents', [], $headers)->assertForbidden();
        $this->postJson('/api/staff/accounts', [
            'name' => 'Not a Captain',
            'role' => 'Secretary',
            'username' => 'admin_cannot_create_secretary',
            'password' => 'secure-pass-123',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/assistance/claim', ['id' => 'A-5001'], $headers)->assertForbidden();
        $this->postJson('/api/assistance/applications/1/review', ['status' => 'Approved'], $headers)->assertForbidden();
        $this->postJson('/api/assistance/applications/1/distribute', ['amount' => '1 pack'], $headers)->assertForbidden();
        $this->postJson('/api/assistance/applications', ['programId' => 'P-101', 'reason' => 'Request'], $headers)->assertForbidden();
        $this->postJson('/api/messages', ['userId' => 1, 'body' => 'Update'], $headers)->assertForbidden();
        $this->postJson('/api/notify-assistance', [], $headers)->assertForbidden();
        $this->postJson('/api/programs/P-101/notify', [], $headers)->assertForbidden();
        $this->getJson('/api/backup', $headers)->assertForbidden();
        $this->postJson('/api/backup/restore', ['data' => []], $headers)->assertForbidden();

        $this->assertDatabaseMissing('beneficiaries', ['contact' => '09990000000']);
        $this->assertDatabaseHas('users', ['username' => 'admin', 'role' => 'Admin']);
    }

    public function test_login_routes_by_stored_account_role_not_selected_role(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();

        foreach ([
            ['username' => 'admin', 'role' => 'Admin', 'expectedRole' => 'admin', 'isBarangayCaptain' => false],
            ['username' => 'barangay', 'role' => 'Admin', 'expectedRole' => 'barangay', 'isBarangayCaptain' => true],
            ['username' => 'user', 'role' => 'Staff', 'expectedRole' => 'user', 'isBarangayCaptain' => false],
        ] as $account) {
            $this->postJson('/api/auth', [
                'username' => $account['username'],
                'password' => 'password',
                'role' => $account['role'],
            ])
                ->assertOk()
                ->assertJsonPath('user.role', $account['expectedRole'])
                ->assertJsonPath('user.isBarangayCaptain', $account['isBarangayCaptain']);
        }
    }

    public function test_barangay_captain_directory_only_shows_nonconfidential_account_metadata(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $this->roleHeaders('directory_officer', 'Assistance Officer');

        $data = $this->getJson('/api/data', $this->authHeaders($this->tokenFor('barangay')))
            ->assertOk()
            ->json('data');

        $this->assertSame(['Resident Account', 'Directory Officer'], array_column($data['users'], 'name'));
        $this->assertSame(['User', 'Assistance Officer'], array_column($data['users'], 'role'));
        $this->assertArrayNotHasKey('password', $data['users'][0]);
        $this->assertArrayNotHasKey('username', $data['users'][0]);
        $this->assertArrayNotHasKey('email', $data['users'][0]);
        $this->assertArrayNotHasKey('beneficiaryName', $data['users'][0]);
        $this->assertArrayNotHasKey('contact', $data['users'][0]);
        $this->assertArrayNotHasKey('address', $data['users'][0]);
        $this->assertArrayNotHasKey('contact', $data['users'][1]);
        $this->assertArrayNotHasKey('email', $data['users'][1]);
        $this->assertSame([], $data['beneficiaries']);
        $this->assertSame([], $data['assistance']);
        $this->assertSame([], $data['programs']);
    }

    public function test_assistance_officer_can_view_review_flags_but_cannot_change_them(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $beneficiary = DB::table('beneficiaries')->where('name', 'Maria Santos')->first();
        DB::table('beneficiaries')->where('id', $beneficiary->id)->update([
            'verification_status' => 'Verified',
            'possible_duplicate' => true,
        ]);
        $headers = $this->roleHeaders('directory_assistance_officer', 'Assistance Officer');

        $directory = $this->getJson('/api/data', $headers)
            ->assertOk()
            ->json('data.beneficiaries');
        $resident = collect($directory)->firstWhere('id', $beneficiary->id);

        $this->assertSame('Verified', $resident['verificationStatus']);
        $this->assertTrue($resident['possibleDuplicate']);
        $this->assertSame('0917 123 4567', $resident['contact']);

        $this->patchJson("/api/beneficiaries/{$beneficiary->id}/verification", [
            'status' => 'Pending',
        ], $headers)->assertForbidden();

        $this->assertDatabaseHas('beneficiaries', [
            'id' => $beneficiary->id,
            'verification_status' => 'Verified',
            'possible_duplicate' => true,
        ]);
    }

    public function test_secretary_and_captain_account_creation_actions_are_role_specific(): void
    {
        $this->get('/assets/app.js')
            ->assertOk()
            ->assertSee('["users", "Add Resident Account", "Create a secure sign-in for a resident who needs account assistance.", "create-resident-account"]', false)
            ->assertSee('page === "create-resident-account" ? actions.setModal("user") : actions.navigate(page)', false)
            ->assertSee('const canCreateResidentAccount = actions.session.role === "secretary";', false)
            ->assertSee('["email", "Email address (optional)", "email"]', false)
            ->assertSee('["contact", "Contact Number (optional)"]', false)
            ->assertDontSee('Enter an email address.', false)
            ->assertSee('const canCreateStaffAccount = isCaptain || isAdmin;', false)
            ->assertSee('onClick: () => actions.setModal("staff")', false)
            ->assertSee('Add Staff Account', false)
            ->assertSee('Add Barangay Captain Account', false)
            ->assertSee('Admin can create Barangay Captain accounts only.', false)
            ->assertSee('Resident entries show account name and access status only.', false)
            ->assertSee('async function changeStaffAccountStatus(user)', false)
            ->assertSee('updateStaffAccountStatus(user.id, status)', false)
            ->assertSee('Monitoring only', false)
            ->assertSee('Are you sure you want to ${action} ${user.name}', false)
            ->assertSee('window.BarangayCareService.createStaffAccount({', false)
            ->assertSee('adminCaptainAccountForm ? ["Barangay Captain"] : ["Secretary", "Assistance Officer", "Distribution Staff"]', false);
    }

    public function test_secretary_can_update_beneficiaries_and_schedules_without_changing_protected_data(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('secretary_one', 'Secretary');
        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $assistanceStatus = DB::table('assistance')->where('id', 'A-5001')->value('status');
        $initialLogCount = DB::table('logs')->count();
        $data['beneficiaries'][0]['purok'] = 'Purok 9';
        $data['programs'][0]['venue'] = 'Barangay Hall';
        $data['assistance'][0]['status'] = 'Claimed';
        $data['assistance'][0]['amount'] = 'PHP 2500 for this resident';
        $data['users'] = [];
        $data['logs'] = [['actor' => 'Forged', 'action' => 'Forged audit entry']];
        $data['settings']['barangayName'] = 'Tampered Barangay';
        $data['assistance'][] = [
            'id' => 'A-9001',
            'beneficiary' => 'Maria Santos',
            'type' => 'Food Pack',
            'amount' => '1 food pack',
            'date' => now()->toDateString(),
            'status' => 'Pending',
            'staff' => 'Secretary One',
        ];

        $this->postJson('/api/data', ['data' => $data], $headers)->assertOk();

        $this->assertDatabaseHas('beneficiaries', ['id' => 'B-2026-001', 'purok' => 'Purok 9']);
        $this->assertDatabaseHas('programs', ['id' => 'P-101', 'venue' => 'Barangay Hall']);
        $this->assertDatabaseHas('assistance', ['id' => 'A-5001', 'status' => $assistanceStatus]);
        $this->assertDatabaseHas('assistance', ['id' => $data['assistance'][0]['id'], 'amount' => 'PHP 2500 for this resident']);
        $this->assertDatabaseHas('assistance', [
            'id' => 'A-9001',
            'beneficiary' => 'Maria Santos',
            'type' => 'Food Pack',
            'amount' => '1 food pack',
            'status' => 'Pending',
            'staff' => 'Secretary One',
        ]);
        $this->assertDatabaseHas('users', ['username' => 'admin']);
        $this->assertSame($initialLogCount + 1, DB::table('logs')->count());
        $this->assertDatabaseMissing('logs', ['actor' => 'Forged']);
        $this->assertDatabaseMissing('settings', ['setting_key' => 'barangayName', 'setting_value' => '"Tampered Barangay"']);
        $residentHeaders = $this->authHeaders($this->tokenFor('user'));
        $residentAssistance = collect(
            $this->getJson('/api/data', $residentHeaders)->assertOk()->json('data.assistance')
        )->firstWhere('id', 'A-9001');
        $this->assertNotNull($residentAssistance);
        $this->assertSame('Pending', $residentAssistance['status']);
    }

    public function test_assistance_officer_receives_403_when_trying_to_assign_assistance(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('aid_officer', 'Assistance Officer');
        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $originalAmount = $data['assistance'][0]['amount'];
        $data['assistance'][0]['amount'] = 'PHP 2500';
        $data['assistance'][] = [
            'id' => 'A-9999',
            'beneficiary' => 'New Resident',
            'type' => 'Food pack',
            'amount' => '1 pack',
            'date' => now()->toDateString(),
            'status' => 'Pending',
        ];
        $data['beneficiaries'][0]['name'] = 'Tampered Name';
        $data['programs'][0]['name'] = 'Tampered Program';
        $data['users'] = [];
        $data['settings']['barangayName'] = 'Tampered Barangay';

        $this->postJson('/api/data', ['data' => $data], $headers)->assertForbidden();

        $this->assertDatabaseHas('assistance', ['id' => $data['assistance'][0]['id'], 'amount' => $originalAmount]);
        $this->assertDatabaseMissing('assistance', ['id' => 'A-9999']);
        $this->assertDatabaseHas('beneficiaries', ['id' => 'B-2026-001', 'name' => 'Maria Santos']);
        $this->assertDatabaseHas('programs', ['id' => 'P-101', 'name' => 'Food Pack Distribution']);
        $this->assertDatabaseHas('users', ['username' => 'admin']);
        $this->assertDatabaseMissing('settings', ['setting_key' => 'barangayName', 'setting_value' => '"Tampered Barangay"']);
    }

    public function test_secretary_cannot_assign_assistance_as_already_claimed(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('secretary_one', 'Secretary');
        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $data['assistance'][] = [
            'id' => 'A-9002',
            'beneficiary' => 'Maria Santos',
            'type' => 'Food Pack',
            'amount' => '1 food pack',
            'date' => now()->toDateString(),
            'status' => 'Claimed',
            'staff' => 'Secretary One',
        ];

        $this->postJson('/api/data', ['data' => $data], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Only Distribution Staff can confirm a completed handover.');

        $this->assertDatabaseMissing('assistance', ['id' => 'A-9002']);
        $this->assertDatabaseCount('distributions', 0);
    }

    public function test_distribution_staff_receives_422_when_handover_amount_is_missing(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('distribution_one', 'Distribution Staff');
        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $this->assertArrayNotHasKey('contact', $data['beneficiaries'][0]);
        $pendingId = DB::table('assistance')->where('status', 'Pending')->value('id');

        $this->postJson('/api/assistance/claim', ['id' => $pendingId], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/data', ['data' => []], $headers)->assertForbidden();

        $this->assertDatabaseHas('assistance', ['id' => $pendingId, 'status' => 'Pending']);
        $this->assertDatabaseCount('distributions', 0);
        $this->assertDatabaseHas('users', ['username' => 'admin']);
    }

    public function test_distribution_staff_can_record_a_program_linked_release_for_an_eligible_resident(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $secretaryHeaders = $this->roleHeaders('secretary_one', 'Secretary');
        $scheduleData = $this->getJson('/api/data', $secretaryHeaders)->assertOk()->json('data');
        $programIndex = array_search('P-101', array_column($scheduleData['programs'], 'id'), true);
        $this->assertNotFalse($programIndex);
        $scheduleData['programs'][$programIndex]['date'] = now()->addDay()->toDateString();
        $scheduleData['programs'][$programIndex]['releaseAmount'] = 'Schedule fallback: 1 food package';
        $assistanceIndex = array_search('A-5001', array_column($scheduleData['assistance'], 'id'), true);
        $this->assertNotFalse($assistanceIndex);
        $scheduleData['assistance'][$assistanceIndex]['amount'] = '2 food packages assigned to Maria';

        $this->postJson('/api/data', ['data' => $scheduleData], $secretaryHeaders)->assertOk();

        $this->assertDatabaseHas('programs', [
            'id' => 'P-101',
            'release_amount' => 'Schedule fallback: 1 food package',
        ]);
        $this->assertDatabaseHas('assistance', [
            'id' => 'A-5001',
            'amount' => '2 food packages assigned to Maria',
        ]);

        $headers = $this->roleHeaders('distribution_one', 'Distribution Staff');
        $distributionData = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $program = collect($distributionData['programs'])->firstWhere('id', 'P-101');
        $this->assertNotNull($program);
        $this->assertSame('Schedule fallback: 1 food package', $program['releaseAmount']);
        $this->assertSame(
            '2 food packages assigned to Maria',
            collect($distributionData['assistance'])->firstWhere('id', 'A-5001')['amount']
        );
        $residentHeaders = $this->authHeaders($this->tokenFor('user'));
        $residentPlannedData = $this->getJson('/api/data', $residentHeaders)->assertOk()->json('data');
        $residentPlannedProgram = collect($residentPlannedData['programs'])->firstWhere('id', 'P-101');
        $residentPlannedAssistance = collect($residentPlannedData['assistance'])->firstWhere('id', 'A-5001');
        $this->assertSame('Schedule fallback: 1 food package', $residentPlannedProgram['releaseAmount']);
        $this->assertSame('2 food packages assigned to Maria', $residentPlannedAssistance['amount']);

        $this->postJson('/api/assistance/claim', [
            'id' => 'A-5001',
            'programId' => 'P-101',
            'amount' => '2 food packages assigned to Maria',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('record.status', 'Claimed')
            ->assertJsonPath('record.amount', '2 food packages assigned to Maria');

        $this->postJson('/api/assistance/claim', [
            'id' => 'A-5001',
            'programId' => 'P-101',
            'amount' => '2 food packages assigned to Maria',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'This assistance was already marked as claimed.');

        $this->assertDatabaseHas('assistance', [
            'id' => 'A-5001',
            'status' => 'Claimed',
            'amount' => '2 food packages assigned to Maria',
            'claimed_by' => 'Distribution One',
        ]);
        $this->assertDatabaseHas('distributions', [
            'assistance_id' => 'A-5001',
            'program_id' => 'P-101',
            'beneficiary' => 'Maria Santos',
            'amount' => '2 food packages assigned to Maria',
        ]);

        $this->assertSame(
            'P-101',
            $this->getJson('/api/data', $headers)->assertOk()->json('data.distributions.0.programId')
        );
        $residentData = $this->getJson('/api/data', $residentHeaders)->assertOk()->json('data');
        $residentProgram = collect($residentData['programs'])->firstWhere('id', 'P-101');
        $residentAssistance = collect($residentData['assistance'])->firstWhere('id', 'A-5001');
        $residentDistribution = collect($residentData['distributions'])->firstWhere('assistanceId', 'A-5001');
        $this->assertNotNull($residentProgram);
        $this->assertSame('Schedule fallback: 1 food package', $residentProgram['releaseAmount']);
        $this->assertNotNull($residentAssistance);
        $this->assertNotNull($residentDistribution);
        $this->assertSame('2 food packages assigned to Maria', $residentAssistance['amount']);
        $this->assertSame('2 food packages assigned to Maria', $residentDistribution['amount']);
        $this->assertDatabaseCount('distributions', 1);
    }

    public function test_distribution_staff_can_record_a_resident_schedule_release_without_a_program(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('distribution_one', 'Distribution Staff');

        $this->postJson('/api/assistance/claim', [
            'id' => 'A-5001',
            'amount' => '1 package',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('record.status', 'Claimed');

        $this->assertDatabaseHas('assistance', [
            'id' => 'A-5001',
            'status' => 'Claimed',
            'claimed_by' => 'Distribution One',
        ]);
        $this->assertDatabaseHas('distributions', [
            'assistance_id' => 'A-5001',
            'program_id' => null,
            'beneficiary' => 'Maria Santos',
            'amount' => '1 package',
        ]);
        $this->assertDatabaseCount('distributions', 1);
    }

    public function test_secretary_can_schedule_benefit_assignments_visible_to_distribution_staff(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $secretaryHeaders = $this->roleHeaders('secretary_one', 'Secretary');
        $data = $this->getJson('/api/data', $secretaryHeaders)->assertOk()->json('data');
        $juanIndex = array_search('Juan Dela Cruz', array_column($data['beneficiaries'], 'name'), true);
        $this->assertNotFalse($juanIndex);
        $data['beneficiaries'][$juanIndex]['category'] = 'Senior Citizen';
        $data['assistance'] = array_merge($data['assistance'], [
            [
                'id' => 'A-9301',
                'beneficiary' => 'Maria Santos',
                'type' => 'Food Pack',
                'amount' => '1 package',
                'date' => '2030-01-15',
                'status' => 'Pending',
                'staff' => 'Secretary One',
            ],
            [
                'id' => 'A-9302',
                'beneficiary' => 'Juan Dela Cruz',
                'type' => 'Food Pack',
                'amount' => '1 package',
                'date' => '2030-01-15',
                'status' => 'Pending',
                'staff' => 'Secretary One',
            ],
        ]);

        $this->postJson('/api/data', ['data' => $data], $secretaryHeaders)->assertOk();

        $this->assertDatabaseHas('assistance', [
            'id' => 'A-9301',
            'beneficiary' => 'Maria Santos',
            'date' => '2030-01-15',
            'status' => 'Pending',
        ]);
        $this->assertDatabaseHas('assistance', [
            'id' => 'A-9302',
            'beneficiary' => 'Juan Dela Cruz',
            'date' => '2030-01-15',
            'status' => 'Pending',
        ]);
        $distributionData = $this->getJson(
            '/api/data',
            $this->roleHeaders('distribution_one', 'Distribution Staff')
        )->assertOk()->json('data.assistance');
        $scheduledAssignments = collect($distributionData)
            ->whereIn('id', ['A-9301', 'A-9302'])
            ->map(fn (array $record): array => [
                'id' => $record['id'],
                'beneficiary' => $record['beneficiary'],
                'type' => $record['type'],
                'date' => $record['date'],
                'status' => $record['status'],
            ])
            ->sortBy('id')
            ->values()
            ->all();

        $this->assertSame([
            [
                'id' => 'A-9301',
                'beneficiary' => 'Maria Santos',
                'type' => 'Food Pack',
                'date' => '2030-01-15',
                'status' => 'Pending',
            ],
            [
                'id' => 'A-9302',
                'beneficiary' => 'Juan Dela Cruz',
                'type' => 'Food Pack',
                'date' => '2030-01-15',
                'status' => 'Pending',
            ],
        ], $scheduledAssignments);
    }

    public function test_secretary_can_update_release_dates_visible_to_distribution_staff_and_the_resident(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $secretaryHeaders = $this->roleHeaders('secretary_one', 'Secretary');
        $data = $this->getJson('/api/data', $secretaryHeaders)->assertOk()->json('data');
        $assistanceIndex = array_search('A-5001', array_column($data['assistance'], 'id'), true);
        $this->assertNotFalse($assistanceIndex);
        $scheduledDate = now()->addDays(3)->toDateString();
        $data['assistance'][$assistanceIndex]['date'] = $scheduledDate;

        $this->postJson('/api/data', ['data' => $data], $secretaryHeaders)->assertOk();

        $this->assertDatabaseHas('assistance', [
            'id' => 'A-5001',
            'date' => $scheduledDate,
        ]);
        $distributionData = $this->getJson('/api/data', $this->roleHeaders('distribution_one', 'Distribution Staff'))
            ->assertOk()
            ->json('data.assistance');
        $this->assertSame($scheduledDate, collect($distributionData)->firstWhere('id', 'A-5001')['date']);

        $residentData = $this->getJson('/api/data', $this->authHeaders($this->tokenFor('user')))
            ->assertOk()
            ->json('data.assistance');
        $residentAssignment = collect($residentData)->firstWhere('id', 'A-5001');
        $this->assertNotNull($residentAssignment);
        $this->assertSame($scheduledDate, $residentAssignment['date']);
        $this->assertSame(['Maria Santos'], array_values(array_unique(array_column($residentData, 'beneficiary'))));
    }

    public function test_secretary_cannot_save_an_invalid_resident_assistance_release_date(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('secretary_one', 'Secretary');
        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $assistanceIndex = array_search('A-5001', array_column($data['assistance'], 'id'), true);
        $this->assertNotFalse($assistanceIndex);
        $originalDate = $data['assistance'][$assistanceIndex]['date'];
        $data['assistance'][$assistanceIndex]['date'] = '2026-02-30';

        $this->postJson('/api/data', ['data' => $data], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Resident assistance release date must be a valid YYYY-MM-DD date.');

        $this->assertDatabaseHas('assistance', [
            'id' => 'A-5001',
            'date' => $originalDate,
        ]);
    }

    public function test_secretary_cannot_save_planned_release_amounts_over_120_characters(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('secretary_one', 'Secretary');
        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $programIndex = array_search('P-101', array_column($data['programs'], 'id'), true);
        $this->assertNotFalse($programIndex);
        $data['programs'][$programIndex]['releaseAmount'] = str_repeat('x', 121);

        $this->postJson('/api/data', ['data' => $data], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Planned amount or items must be 120 characters or fewer.');

        $this->assertDatabaseHas('programs', [
            'id' => 'P-101',
            'release_amount' => null,
        ]);
    }

    public function test_secretary_cannot_save_resident_assistance_amounts_over_120_characters(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('secretary_one', 'Secretary');
        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $assistanceIndex = array_search('A-5001', array_column($data['assistance'], 'id'), true);
        $this->assertNotFalse($assistanceIndex);
        $originalAmount = $data['assistance'][$assistanceIndex]['amount'];
        $data['assistance'][$assistanceIndex]['amount'] = str_repeat('x', 121);

        $this->postJson('/api/data', ['data' => $data], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Resident assistance amount or items must be 120 characters or fewer.');

        $this->assertDatabaseHas('assistance', [
            'id' => 'A-5001',
            'amount' => $originalAmount,
        ]);
    }

    public function test_resident_receives_403_when_attempting_to_confirm_assistance(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->authHeaders($this->tokenFor('user'));

        $this->postJson('/api/assistance/claim', [
            'id' => 'A-5001',
            'programId' => 'P-101',
            'amount' => '2 food packages',
        ], $headers)
            ->assertForbidden();

        $this->assertDatabaseHas('assistance', ['id' => 'A-5001', 'status' => 'Completed']);
        $this->assertDatabaseCount('distributions', 0);
    }

    public function test_secretary_receives_403_when_attempting_to_confirm_a_handover(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('secretary_one', 'Secretary');

        $this->postJson('/api/assistance/claim', [
            'id' => 'A-5001',
            'programId' => 'P-101',
            'amount' => '2 food packages',
        ], $headers)->assertForbidden();

        $this->assertDatabaseHas('assistance', ['id' => 'A-5001', 'status' => 'Completed']);
        $this->assertDatabaseCount('distributions', 0);
    }

    public function test_distribution_staff_cannot_release_an_ineligible_beneficiary_under_a_program(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('distribution_one', 'Distribution Staff');
        $distributionCount = DB::table('distributions')->count();

        $this->postJson('/api/assistance/claim', [
            'id' => 'A-5002',
            'programId' => 'P-101',
            'amount' => 'PHP 2,500',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'This beneficiary is not active or is not eligible for the selected program.');

        $this->assertDatabaseHas('assistance', ['id' => 'A-5002', 'status' => 'Completed']);
        $this->assertSame($distributionCount, DB::table('distributions')->count());
    }

    public function test_distribution_staff_cannot_release_again_for_a_completed_program(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('distribution_one', 'Distribution Staff');
        DB::table('programs')->where('id', 'P-101')->update(['status' => 'Completed']);
        $distributionCount = DB::table('distributions')->count();

        $this->postJson('/api/assistance/claim', [
            'id' => 'A-5001',
            'programId' => 'P-101',
            'amount' => '1 food package',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'This program is completed and cannot accept new releases.');

        $this->assertDatabaseHas('assistance', ['id' => 'A-5001', 'status' => 'Completed']);
        $this->assertSame($distributionCount, DB::table('distributions')->count());
    }

    public function test_resident_cannot_submit_but_can_view_their_own_previous_assistance_record(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $residentHeaders = $this->authHeaders($this->tokenFor('user'));
        $application = $this->createLegacyAssistanceApplication();

        $this->postJson('/api/assistance/applications', [
            'programId' => 'P-101',
            'reason' => 'Please assess my household for barangay assistance.',
        ])->assertUnauthorized();

        $this->postJson('/api/assistance/applications', [
            'programId' => 'P-101',
            'reason' => 'Please assess my household for barangay assistance.',
        ], $residentHeaders)
            ->assertForbidden()
            ->assertJsonPath('error', 'Assistance is assigned by the barangay based on your registered benefit profile. No application is required.');

        $this->assertDatabaseHas('assistance_applications', [
            'id' => $application['id'],
            'username' => 'user',
            'status' => 'Submitted',
        ]);
        $this->assertDatabaseCount('assistance_applications', 1);
        $this->getJson('/api/data', $residentHeaders)
            ->assertOk()
            ->assertJsonPath('data.applications.0.id', $application['id']);

        $otherResidentHeaders = $this->roleHeaders('other_resident', 'User');
        $this->getJson('/api/data', $otherResidentHeaders)
            ->assertOk()
            ->assertJsonPath('data.applications', []);
    }

    public function test_resident_application_endpoint_rejects_empty_requests_before_validation(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->authHeaders($this->tokenFor('user'));

        $this->postJson('/api/assistance/applications', [], $headers)
            ->assertForbidden()
            ->assertJsonPath('error', 'Assistance is assigned by the barangay based on your registered benefit profile. No application is required.');

        $this->assertDatabaseCount('assistance_applications', 0);
    }

    public function test_residents_only_receive_schedules_matching_their_category_or_all_residents(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $futureDate = now()->addDays(10)->toDateString();
        DB::table('programs')->update([
            'benefit' => 'Indigent',
            'date' => $futureDate,
            'status' => 'Scheduled',
        ]);
        DB::table('programs')->where('id', 'P-101')->update(['benefit' => 'PWD']);
        DB::table('programs')->where('id', 'P-102')->update(['benefit' => 'All residents']);
        DB::table('programs')->where('id', 'P-103')->update(['benefit' => 'Senior Citizen']);
        DB::table('programs')->insert([
            [
                'id' => 'P-104',
                'name' => 'Solo Parent Distribution',
                'benefit' => 'Solo Parent',
                'date' => $futureDate,
                'venue' => 'Barangay Hall',
                'slots' => 25,
                'status' => 'Scheduled',
            ],
            [
                'id' => 'P-105',
                'name' => 'Unassigned Distribution',
                'benefit' => '',
                'date' => $futureDate,
                'venue' => 'Barangay Hall',
                'slots' => 10,
                'status' => 'Scheduled',
            ],
        ]);

        $pwdHeaders = $this->roleHeaders('pwd_resident', 'User');
        DB::table('users')->where('username', 'pwd_resident')->update(['beneficiary_name' => 'PWD Resident']);
        DB::table('beneficiaries')->insert([
            'id' => 'B-PWD-RESIDENT',
            'name' => 'PWD Resident',
            'purok' => 'Purok 1',
            'category' => 'Senior Citizen, pwd',
            'contact' => '09170000001',
            'status' => 'Active',
            'last_aid' => '',
            'updated' => now()->toDateString(),
        ]);

        $seniorHeaders = $this->roleHeaders('senior_resident', 'User');
        DB::table('users')->where('username', 'senior_resident')->update(['beneficiary_name' => 'Senior Resident']);
        DB::table('beneficiaries')->insert([
            'id' => 'B-SENIOR-RESIDENT',
            'name' => 'Senior Resident',
            'purok' => 'Purok 2',
            'category' => 'Senior Citizen',
            'contact' => '09170000002',
            'status' => 'Active',
            'last_aid' => '',
            'updated' => now()->toDateString(),
        ]);

        $pwdPrograms = $this->getJson('/api/data', $pwdHeaders)
            ->assertOk()
            ->json('data.programs');
        $seniorPrograms = $this->getJson('/api/data', $seniorHeaders)
            ->assertOk()
            ->json('data.programs');

        $pwdProgramIds = array_column($pwdPrograms, 'id');
        $seniorProgramIds = array_column($seniorPrograms, 'id');
        $publicProgramIds = array_column(
            $this->getJson('/api/summary')->assertOk()->json('data.programs'),
            'id'
        );
        sort($pwdProgramIds);
        sort($seniorProgramIds);
        sort($publicProgramIds);

        $this->assertSame(['P-101', 'P-102', 'P-103'], $pwdProgramIds);
        $this->assertSame(['P-102', 'P-103'], $seniorProgramIds);
        $this->assertSame(['P-102'], $publicProgramIds);
    }

    public function test_schedule_status_is_saved_resident_visibility_is_updated_and_submissions_are_blocked(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $futureDate = now()->addDays(10)->toDateString();
        DB::table('programs')->where('id', 'P-101')->update(['date' => $futureDate]);
        $secretaryHeaders = $this->roleHeaders('schedule_status_secretary', 'Secretary');
        $residentHeaders = $this->roleHeaders('schedule_status_resident', 'User');
        DB::table('users')->where('username', 'schedule_status_resident')->update(['beneficiary_name' => 'Maria Santos']);
        $data = $this->getJson('/api/data', $secretaryHeaders)
            ->assertOk()
            ->json('data');
        $data['programs'] = array_map(
            fn (array $program): array => $program['id'] === 'P-101'
                ? [...$program, 'status' => 'Scheduled']
                : $program,
            $data['programs']
        );

        $this->postJson('/api/data', ['data' => $data], $secretaryHeaders)
            ->assertOk()
            ->assertJsonFragment(['id' => 'P-101', 'status' => 'Scheduled']);
        $this->assertDatabaseHas('programs', ['id' => 'P-101', 'status' => 'Scheduled']);
        $residentProgramIds = array_column($this->getJson('/api/data', $residentHeaders)
            ->assertOk()
            ->json('data.programs'), 'id');
        $this->assertContains('P-101', $residentProgramIds);

        $data = $this->getJson('/api/data', $secretaryHeaders)
            ->assertOk()
            ->json('data');
        $data['programs'] = array_map(
            fn (array $program): array => $program['id'] === 'P-101'
                ? [...$program, 'status' => 'Open']
                : $program,
            $data['programs']
        );

        $this->postJson('/api/data', ['data' => $data], $secretaryHeaders)
            ->assertOk()
            ->assertJsonFragment(['id' => 'P-101', 'status' => 'Open']);

        $this->assertDatabaseHas('programs', ['id' => 'P-101', 'status' => 'Open']);
        $residentProgramIds = array_column($this->getJson('/api/data', $residentHeaders)
            ->assertOk()
            ->json('data.programs'), 'id');
        $this->assertContains('P-101', $residentProgramIds);
        $this->postJson('/api/assistance/applications', [
            'programId' => 'P-101',
            'reason' => 'I need help covering household food expenses.',
        ], $residentHeaders)->assertForbidden();
        $this->assertDatabaseCount('assistance_applications', 0);

        $data = $this->getJson('/api/data', $secretaryHeaders)
            ->assertOk()
            ->json('data');
        $data['programs'] = array_map(
            fn (array $program): array => $program['id'] === 'P-101'
                ? [...$program, 'status' => 'Completed']
                : $program,
            $data['programs']
        );

        $this->postJson('/api/data', ['data' => $data], $secretaryHeaders)
            ->assertOk()
            ->assertJsonFragment(['id' => 'P-101', 'status' => 'Completed']);

        $this->assertDatabaseHas('programs', ['id' => 'P-101', 'status' => 'Completed']);
        $residentProgramIds = array_column($this->getJson('/api/data', $residentHeaders)
            ->assertOk()
            ->json('data.programs'), 'id');
        $this->assertNotContains('P-101', $residentProgramIds);
        $this->postJson('/api/assistance/applications', [
            'programId' => 'P-101',
            'reason' => 'This completed schedule should no longer accept applications.',
        ], $residentHeaders)->assertForbidden();
        $this->assertDatabaseCount('assistance_applications', 0);

        $data = $this->getJson('/api/data', $secretaryHeaders)
            ->assertOk()
            ->json('data');
        $data['programs'] = array_map(
            fn (array $program): array => $program['id'] === 'P-101'
                ? [...$program, 'status' => 'Cancelled']
                : $program,
            $data['programs']
        );

        $this->postJson('/api/data', ['data' => $data], $secretaryHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Choose a supported program status.');
        $this->assertDatabaseHas('programs', ['id' => 'P-101', 'status' => 'Completed']);
    }

    public function test_assistance_proof_is_private_and_retained_until_distribution(): void
    {
        Storage::fake('local');
        $this->seed(BarangayCareSeeder::class);
        DB::table('programs')->where('id', 'P-101')->update([
            'date' => now()->addDays(10)->toDateString(),
        ]);
        $residentHeaders = $this->authHeaders($this->tokenFor('user'));
        $application = $this->createLegacyAssistanceApplication();
        $applicationId = $application['id'];
        $officerHeaders = $this->roleHeaders('proof_reviewer', 'Assistance Officer');
        $captainHeaders = $this->roleHeaders('proof_captain', 'Barangay');
        $adminHeaders = $this->authHeaders($this->tokenFor('admin'));
        $distributionHeaders = $this->roleHeaders('proof_distributor', 'Distribution Staff');

        $this->postJson("/api/assistance/applications/{$applicationId}/proof-requests", [
            'note' => 'Please provide a valid photo ID.',
        ])->assertUnauthorized();
        $this->postJson("/api/assistance/applications/{$applicationId}/proof-requests", [
            'note' => 'Please provide a valid photo ID.',
        ], $captainHeaders)->assertForbidden();
        $this->postJson("/api/assistance/applications/{$applicationId}/proof-requests", [
            'note' => 'Please provide a valid photo ID.',
        ], $officerHeaders)->assertUnprocessable();
        $this->postJson("/api/assistance/applications/{$applicationId}/review", [
            'status' => 'Under Review',
        ], $officerHeaders)->assertOk();
        $this->postJson("/api/assistance/applications/{$applicationId}/proof-requests", [
            'note' => 'Please provide a valid photo ID.',
        ], $officerHeaders)
            ->assertCreated()
            ->assertJsonPath('proofRequest.status', 'Requested');
        $this->postJson("/api/assistance/applications/{$applicationId}/proof-requests", [
            'note' => 'Please provide another document.',
        ], $officerHeaders)->assertUnprocessable();
        $this->postJson("/api/assistance/applications/{$applicationId}/review", [
            'status' => 'Approved',
        ], $officerHeaders)->assertUnprocessable();

        $residentApplication = $this->getJson('/api/data', $residentHeaders)
            ->assertOk()
            ->json('data.applications.0');
        $this->assertSame('Please provide a valid photo ID.', $residentApplication['proofRequest']['note']);
        $this->assertSame('Requested', $residentApplication['proofRequest']['status']);
        $this->assertArrayNotHasKey('files', $residentApplication['proofRequest']);

        $adminApplication = $this->getJson('/api/data', $adminHeaders)
            ->assertOk()
            ->json('data.applications.0');
        $this->assertSame('Requested', $adminApplication['proofRequest']['status']);
        $this->assertSame([], $adminApplication['proofRequest']['files']);
        $this->postJson("/api/assistance/applications/{$applicationId}/review", [
            'status' => 'Approved',
        ], $adminHeaders)->assertForbidden();
        $this->assertDatabaseHas('assistance_applications', [
            'id' => $applicationId,
            'status' => 'Under Review',
        ]);
        $this->getJson('/api/data', $captainHeaders)
            ->assertOk()
            ->assertJsonPath('data.applications', []);
        $distributionApplication = $this->getJson('/api/data', $distributionHeaders)
            ->assertOk()
            ->json('data.applications.0');
        $this->assertArrayNotHasKey('proofRequest', $distributionApplication);

        $otherResidentHeaders = $this->roleHeaders('proof_other_resident', 'User');
        $this->post("/api/assistance/applications/{$applicationId}/proofs", [
            'proofs' => [$this->fakeProofPng()],
        ], array_merge($otherResidentHeaders, ['Accept' => 'application/json']))->assertNotFound();

        $this->post("/api/assistance/applications/{$applicationId}/proofs", [
            'proofs' => [UploadedFile::fake()->create('invalid.txt', 1, 'text/plain')],
        ], array_merge($residentHeaders, ['Accept' => 'application/json']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proofs.0');
        $oversizedPdf = UploadedFile::fake()->createWithContent(
            'oversized.pdf',
            "%PDF-1.4\n".str_repeat('x', (10 * 1024 * 1024) + 1)
        );
        $this->post("/api/assistance/applications/{$applicationId}/proofs", [
            'proofs' => [$oversizedPdf],
        ], array_merge($residentHeaders, ['Accept' => 'application/json']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proofs.0');
        $this->assertDatabaseCount('assistance_application_proof_files', 0);

        $pngContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/rckAAAAASUVORK5CYII=',
            true
        );
        $this->post("/api/assistance/applications/{$applicationId}/proofs", [
            'proofs' => [$this->fakeProofPng()],
        ], array_merge($residentHeaders, ['Accept' => 'application/json']))
            ->assertOk()
            ->assertJsonPath('proofRequest.status', 'Submitted')
            ->assertJsonPath('proofRequest.fileCount', 1);
        $proofPath = DB::table('assistance_application_proof_files')->value('storage_path');
        $this->assertIsString($proofPath);
        Storage::disk('local')->assertExists($proofPath);
        $this->assertSame($pngContent, Storage::disk('local')->get($proofPath));

        $officerApplication = $this->getJson('/api/data', $officerHeaders)
            ->assertOk()
            ->json('data.applications.0');
        $proofFile = $officerApplication['proofRequest']['files'][0];
        $this->assertSame('resident-id.png', $proofFile['name']);
        $this->assertArrayNotHasKey('storagePath', $proofFile);
        $adminApplication = $this->getJson('/api/data', $adminHeaders)
            ->assertOk()
            ->json('data.applications.0');
        $adminProofFile = $adminApplication['proofRequest']['files'][0];
        $this->assertSame('resident-id.png', $adminProofFile['name']);
        $this->assertArrayNotHasKey('storagePath', $adminProofFile);

        $this->get("/api/assistance/applications/{$applicationId}/proofs/{$proofFile['id']}/download", $residentHeaders)
            ->assertForbidden();
        $this->get("/api/assistance/applications/{$applicationId}/proofs/{$proofFile['id']}/download", $adminHeaders)
            ->assertOk()
            ->assertDownload('resident-id.png')
            ->assertHeader('content-type', 'application/octet-stream')
            ->assertHeader('cache-control', 'max-age=0, no-store, private');
        $this->get("/api/assistance/applications/{$applicationId}/proofs/{$proofFile['id']}/download", $distributionHeaders)
            ->assertForbidden();
        $this->get('/api/assistance/applications/'.($applicationId + 1)."/proofs/{$proofFile['id']}/download", $officerHeaders)
            ->assertNotFound();
        $this->get("/api/assistance/applications/{$applicationId}/proofs/{$proofFile['id']}/download", $officerHeaders)
            ->assertOk()
            ->assertDownload('resident-id.png')
            ->assertHeader('content-type', 'application/octet-stream')
            ->assertHeader('cache-control', 'max-age=0, no-store, private');

        $this->postJson("/api/assistance/applications/{$applicationId}/review", [
            'status' => 'Approved',
        ], $officerHeaders)->assertOk();
        Storage::disk('local')->assertExists($proofPath);

        $this->postJson("/api/assistance/applications/{$applicationId}/distribute", [
            'amount' => '1 food pack',
        ], $distributionHeaders)->assertCreated();
        Storage::disk('local')->assertMissing($proofPath);
        $this->assertDatabaseCount('assistance_application_proof_requests', 0);
        $this->get("/api/assistance/applications/{$applicationId}/proofs/{$proofFile['id']}/download", $officerHeaders)
            ->assertNotFound();
    }

    public function test_rejected_assistance_application_deletes_uploaded_proof(): void
    {
        Storage::fake('local');
        $this->seed(BarangayCareSeeder::class);
        DB::table('programs')->where('id', 'P-101')->update([
            'date' => now()->addDays(10)->toDateString(),
        ]);
        $residentHeaders = $this->authHeaders($this->tokenFor('user'));
        $application = $this->createLegacyAssistanceApplication();
        $applicationId = $application['id'];
        $officerHeaders = $this->roleHeaders('reject_reviewer', 'Assistance Officer');
        $this->postJson("/api/assistance/applications/{$applicationId}/review", [
            'status' => 'Under Review',
        ], $officerHeaders)->assertOk();
        $this->postJson("/api/assistance/applications/{$applicationId}/proof-requests", [
            'note' => 'Please provide a valid photo ID.',
        ], $officerHeaders)->assertCreated();
        $this->post("/api/assistance/applications/{$applicationId}/proofs", [
            'proofs' => [$this->fakeProofPng()],
        ], array_merge($residentHeaders, ['Accept' => 'application/json']))->assertOk();
        $proofPath = DB::table('assistance_application_proof_files')->value('storage_path');
        Storage::disk('local')->assertExists($proofPath);

        $this->postJson("/api/assistance/applications/{$applicationId}/review", [
            'status' => 'Rejected',
            'reviewNote' => 'The submitted documents do not meet the eligibility requirements.',
        ], $officerHeaders)->assertOk();

        Storage::disk('local')->assertMissing($proofPath);
        $this->assertDatabaseCount('assistance_application_proof_requests', 0);
        $this->assertDatabaseCount('assistance_application_proof_files', 0);
    }

    public function test_assistance_officer_reviews_and_distribution_staff_records_a_separate_distribution(): void
    {
        $this->seed(BarangayCareSeeder::class);
        DB::table('programs')->where('id', 'P-101')->update([
            'date' => now()->addDays(10)->toDateString(),
        ]);
        $this->tokenFor('user');
        $application = $this->createLegacyAssistanceApplication();

        $captainHeaders = $this->roleHeaders('captain_monitor', 'Barangay');
        $captainData = $this->getJson('/api/data', $captainHeaders)
            ->assertOk()
            ->assertJsonPath('data.applications', [])
            ->assertJsonPath('data.beneficiaries', [])
            ->json('data');
        $this->assertSame([], $captainData['assistance']);

        $distributionHeaders = $this->roleHeaders('distribution_one', 'Distribution Staff');
        $distributionData = $this->getJson('/api/data', $distributionHeaders)
            ->assertOk()
            ->assertJsonPath('data.applications.0.status', 'Submitted')
            ->json('data');
        $distributionApplication = $distributionData['applications'][0];
        $this->assertContains('Maria Santos', array_column($distributionData['beneficiaries'], 'name'));
        $this->assertArrayNotHasKey('contact', $distributionData['beneficiaries'][0]);
        $this->assertSame($application['id'], $distributionApplication['id']);
        $this->assertArrayNotHasKey('reason', $distributionApplication);
        $this->assertArrayNotHasKey('username', $distributionApplication);
        $this->assertArrayNotHasKey('userId', $distributionApplication);

        $officerHeaders = $this->roleHeaders('aid_reviewer', 'Assistance Officer');
        $this->postJson("/api/assistance/applications/{$application['id']}/review", [
            'status' => 'Approved',
        ], $officerHeaders)->assertUnprocessable();
        $secretaryHeaders = $this->roleHeaders('review_secretary', 'Secretary');
        $this->postJson("/api/assistance/applications/{$application['id']}/review", [
            'status' => 'Under Review',
        ], $secretaryHeaders)->assertForbidden();

        $this->postJson("/api/assistance/applications/{$application['id']}/review", [
            'status' => 'Under Review',
        ], $officerHeaders)
            ->assertOk()
            ->assertJsonPath('application.status', 'Under Review');
        $this->postJson("/api/assistance/applications/{$application['id']}/review", [
            'status' => 'Rejected',
        ], $officerHeaders)->assertUnprocessable();
        $this->postJson("/api/assistance/applications/{$application['id']}/review", [
            'status' => 'Approved',
            'reviewNote' => 'Eligible for the scheduled program.',
        ], $officerHeaders)
            ->assertOk()
            ->assertJsonPath('application.status', 'Approved');
        $this->postJson("/api/assistance/applications/{$application['id']}/distribute", [
            'amount' => '1 food pack',
        ], $officerHeaders)->assertForbidden();

        $distributionData = $this->getJson('/api/data', $distributionHeaders)
            ->assertOk()
            ->assertJsonPath('data.applications.0.status', 'Approved')
            ->json('data.applications.0');
        $this->assertArrayNotHasKey('reason', $distributionData);
        $distribution = $this->postJson("/api/assistance/applications/{$application['id']}/distribute", [
            'amount' => '1 food pack',
        ], $distributionHeaders)
            ->assertCreated()
            ->assertJsonPath('distribution.amount', '1 food pack')
            ->json('distribution');

        $this->assertDatabaseHas('assistance_applications', [
            'id' => $application['id'],
            'status' => 'Distributed',
        ]);
        $this->assertDatabaseHas('distributions', [
            'id' => $distribution['id'],
            'application_id' => $application['id'],
            'assistance_id' => null,
            'amount' => '1 food pack',
        ]);

        $adminHeaders = $this->authHeaders($this->tokenFor('admin'));
        $monitoringData = $this->getJson('/api/data', $adminHeaders)
            ->assertOk()
            ->json('data');
        $this->assertSame('Distributed', $monitoringData['applications'][0]['status']);
        $this->assertSame($application['id'], $monitoringData['distributions'][0]['applicationId']);

        $captainMonitoringData = $this->getJson('/api/data', $captainHeaders)
            ->assertOk()
            ->json('data');
        $this->assertSame([], $captainMonitoringData['applications']);
        $this->assertSame([], $captainMonitoringData['distributions']);

        $this->assertDatabaseHas('assistance_applications', [
            'id' => $application['id'],
            'status' => 'Distributed',
        ]);
        $this->assertDatabaseHas('distributions', [
            'id' => $distribution['id'],
            'application_id' => $application['id'],
            'amount' => '1 food pack',
        ]);

        $this->postJson("/api/assistance/applications/{$application['id']}/distribute", [
            'amount' => '1 food pack',
        ], $distributionHeaders)->assertUnprocessable();
    }

    public function test_read_only_auditor_receives_403_for_data_changes_and_claims(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $headers = $this->roleHeaders('auditor_one', 'Read-only Auditor');

        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $this->assertNotEmpty($data['logs']);
        $this->assertCount(1, $data['users']);
        $this->assertArrayNotHasKey('contact', $data['beneficiaries'][0]);
        $this->postJson('/api/data', ['data' => $data], $headers)->assertForbidden();
        $this->postJson('/api/assistance/claim', ['id' => 'A-5001'], $headers)->assertForbidden();
    }

    public function test_pending_accounts_sign_in_without_approval(): void
    {
        $this->seed(BarangayCareSeeder::class);
        DB::table('users')->insert([
            'name' => 'Existing Applicant',
            'role' => 'Barangay',
            'beneficiary_name' => '',
            'username' => 'pending_staff',
            'password' => Hash::make('secure-pass-123'),
            'status' => 'Pending',
        ]);

        $this->postJson('/api/auth', [
            'username' => 'pending_staff',
            'password' => 'incorrect-password',
        ])->assertUnauthorized();

        $this->assertDatabaseHas('users', [
            'username' => 'pending_staff',
            'status' => 'Pending',
        ]);

        $this->postJson('/api/auth', [
            'username' => 'pending_staff',
            'password' => 'secure-pass-123',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'barangay')
            ->assertJsonPath('user.status', 'Active');

        $this->assertDatabaseHas('users', [
            'username' => 'pending_staff',
            'status' => 'Active',
        ]);
    }

    public function test_residents_can_change_password_without_exposing_credentials_in_application_data(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->postJson('/api/residents', [
            'name' => 'Password Resident',
            'username' => 'password_resident',
            'email' => 'password.resident@example.com',
            'beneficiaryName' => 'Maria Santos',
            'password' => 'secure-pass-123',
        ], $this->roleHeaders('password_resident_secretary', 'Secretary'))->assertCreated();

        $this->postJson('/api/password', [
            'username' => 'password_resident',
            'currentPassword' => 'secure-pass-123',
            'newPassword' => 'a-different-pass-456',
            'newPassword_confirmation' => 'a-different-pass-456',
        ], $this->authHeaders($this->tokenFor('password_resident', 'secure-pass-123')))->assertOk();

        $this->postJson('/api/auth', [
            'username' => 'password_resident',
            'password' => 'secure-pass-123',
            'role' => 'User',
        ])->assertUnauthorized();

        $this->postJson('/api/auth', [
            'username' => 'password_resident',
            'password' => 'a-different-pass-456',
            'role' => 'User',
        ])->assertOk();
    }

    public function test_registered_roles_can_reset_passwords_using_a_single_use_email_link(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        DB::table('users')->where('username', 'barangay')->update(['email' => 'staff@example.com']);
        DB::table('users')->where('username', 'user')->update(['email' => 'resident@example.com']);
        foreach ([
            ['username' => 'admin', 'role' => 'Admin', 'email' => 'admin@example.com'],
            ['username' => 'barangay', 'role' => 'Barangay', 'email' => 'staff@example.com'],
            ['username' => 'secretary', 'role' => 'Secretary', 'email' => 'secretary@example.com'],
            ['username' => 'distribution', 'role' => 'Distribution Staff', 'email' => 'distribution@example.com'],
            ['username' => 'user', 'role' => 'User', 'email' => 'resident@example.com'],
        ] as $account) {
            if (! DB::table('users')->where('username', $account['username'])->exists()) {
                DB::table('users')->insert([
                    'name' => ucfirst($account['username']),
                    'role' => $account['role'],
                    'beneficiary_name' => '',
                    'username' => $account['username'],
                    'password' => Hash::make('password'),
                    'status' => 'Active',
                    'email' => $account['email'],
                ]);
            } else {
                DB::table('users')->where('username', $account['username'])->update(['email' => $account['email']]);
            }

            Mail::fake();
            $this->postJson('/api/password/forgot', [
                'username' => $account['username'],
                'email' => $account['email'],
            ])->assertOk()
                ->assertJsonPath('ok', true);

            $resetLink = null;
            Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$resetLink): bool {
                parse_str((string) parse_url($mail->resetUrl, PHP_URL_FRAGMENT), $resetLink);
                $this->assertStringContainsString($mail->resetUrl, $mail->render());

                return true;
            });
            $this->assertSame((string) DB::table('users')->where('username', $account['username'])->value('id'), $resetLink['user-id']);

            $this->postJson('/api/password/reset', [
                'userId' => $resetLink['user-id'],
                'token' => $resetLink['password-reset'],
                'password' => 'changed-password',
                'password_confirmation' => 'changed-password',
            ])->assertOk()
                ->assertJsonPath('ok', true);

            $this->assertTrue(Hash::check('changed-password', (string) DB::table('users')->where('username', $account['username'])->value('password')));
            $this->assertDatabaseMissing('account_password_reset_tokens', [
                'user_id' => DB::table('users')->where('username', $account['username'])->value('id'),
            ]);
        }
    }

    public function test_password_reset_request_is_generic_and_reset_links_expire_and_cannot_be_reused(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(BarangayCareSeeder::class);
        DB::table('users')->where('username', 'barangay')->update(['email' => 'staff@example.com']);
        Mail::fake();

        $expectedResponse = $this->postJson('/api/password/forgot', [
            'username' => 'missing-account',
            'email' => 'missing@example.com',
        ])->assertOk()->json();
        $mismatchedResponse = $this->postJson('/api/password/forgot', [
            'username' => 'barangay',
            'email' => 'wrong@example.com',
        ])->assertOk()->json();
        $this->assertSame($expectedResponse, $mismatchedResponse);
        Mail::assertNothingSent();

        $this->postJson('/api/password/forgot', [
            'username' => 'barangay',
            'email' => 'staff@example.com',
        ])->assertOk();
        $resetLink = null;
        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$resetLink): bool {
            parse_str((string) parse_url($mail->resetUrl, PHP_URL_FRAGMENT), $resetLink);

            return true;
        });

        $this->travel(61)->minutes();
        $this->postJson('/api/password/reset', [
            'userId' => $resetLink['user-id'],
            'token' => $resetLink['password-reset'],
            'password' => 'changed-password',
            'password_confirmation' => 'changed-password',
        ])->assertUnprocessable();
        $this->travelBack();
    }

    public function test_successful_password_reset_revokes_existing_auth_tokens_and_sessions(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(BarangayCareSeeder::class);
        DB::table('users')->where('username', 'barangay')->update(['email' => 'staff@example.com']);
        $userId = (int) DB::table('users')->where('username', 'barangay')->value('id');
        $this->postJson('/api/auth', ['username' => 'barangay', 'password' => 'password'])->assertOk();
        DB::table('sessions')->insert([
            'id' => 'password-reset-session',
            'user_id' => $userId,
            'payload' => 'test-session',
            'last_activity' => now()->timestamp,
        ]);
        Mail::fake();
        $this->postJson('/api/password/forgot', [
            'username' => 'barangay',
            'email' => 'staff@example.com',
        ])->assertOk();

        $resetLink = null;
        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$resetLink): bool {
            parse_str((string) parse_url($mail->resetUrl, PHP_URL_FRAGMENT), $resetLink);

            return true;
        });
        $this->postJson('/api/password/reset', [
            'userId' => $resetLink['user-id'],
            'token' => $resetLink['password-reset'],
            'password' => 'changed-password',
            'password_confirmation' => 'changed-password',
        ])->assertOk();
        $this->assertDatabaseMissing('auth_tokens', ['user_id' => $userId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'password-reset-session']);
        $this->postJson('/api/password/reset', [
            'userId' => $resetLink['user-id'],
            'token' => $resetLink['password-reset'],
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertUnprocessable();
    }

    public function test_laravel_api_reports_health_and_serves_seeded_application_data(): void
    {
        $this->seed(BarangayCareSeeder::class);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('service', 'BarangayCare Laravel API');

        $this->getJson('/api/data', $this->authHeaders($this->tokenFor('admin')))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.beneficiaries.0.name', 'Maria Santos')
            ->assertJsonPath('data.users.0.username', 'admin')
            ->assertJsonStructure([
                'data' => [
                    'beneficiaries',
                    'programs',
                    'assistance',
                    'users',
                    'logs',
                    'settings',
                    'messages',
                    'applications',
                    'distributions',
                ],
            ]);
    }

    public function test_laravel_api_authenticates_a_seeded_role_account(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->get('/login/admin')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);

        $this->postJson('/api/auth', [
            'username' => 'admin',
            'password' => 'password',
            'role' => 'Admin',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.username', 'admin')
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_laravel_api_persists_updates_from_an_authorized_staff_role(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('persistence_secretary', 'Secretary');
        $data = $this->getJson('/api/data', $headers)->json('data');
        $data['programs'][0]['name'] = 'Barangay Test Schedule';

        $this->postJson('/api/data', ['data' => $data], $headers)
            ->assertOk()
            ->assertJsonPath('data.programs.0.name', 'Barangay Test Schedule');

        $this->getJson('/api/data', $headers)
            ->assertOk()
            ->assertJsonPath('data.programs.0.name', 'Barangay Test Schedule');

        $this->postJson('/api/auth', [
            'username' => 'admin',
            'password' => 'password',
            'role' => 'Admin',
        ])->assertOk();
    }

    public function test_chat_assistant_answers_from_laravel_and_validates_requests(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->authHeaders($this->tokenFor('user'));

        $this->postJson('/api/chat', [
            'question' => 'What requirements should I bring?',
            'role' => 'user',
            'beneficiaryName' => 'Maria Santos',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('source', 'laravel-mysql')
            ->assertJsonPath('reply', 'Please bring a valid ID, barangay certificate if required, and any document requested by the barangay staff for your assistance category.');

        $this->postJson('/api/chat', ['question' => ''], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('question');
    }

    public function test_staff_can_reply_to_resident_messages_but_captain_cannot(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $staffHeaders = $this->roleHeaders('message_secretary', 'Secretary');
        $resident = DB::table('users')->where('username', 'user')->first();

        $this->postJson('/api/messages', [
            'userId' => $resident->id,
            'body' => 'Please check the barangay schedule for your assistance.',
        ], $this->authHeaders($this->tokenFor('barangay')))->assertForbidden();

        $this->postJson('/api/messages', [
            'userId' => $resident->id,
            'body' => 'Please check the barangay schedule for your assistance.',
        ], $staffHeaders)->assertOk();

        $staffData = $this->getJson('/api/data', $staffHeaders)->assertOk()->json('data');
        $this->assertSame([], $staffData['messages']);
    }

    public function test_resident_can_send_a_complaint_and_staff_can_reply(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $residentHeaders = $this->authHeaders($this->tokenFor('user'));
        $resident = DB::table('users')->where('username', 'user')->first();

        $this->postJson('/api/messages', [
            'kind' => 'Complaint',
            'body' => 'The rice assistance was not released to my household.',
        ], $residentHeaders)->assertOk();

        $this->postJson('/api/messages', [
            'kind' => 'Complaint',
            'body' => '',
        ], $residentHeaders)->assertUnprocessable();

        $residentData = $this->getJson('/api/data', $residentHeaders)->json('data.messages');
        $this->assertCount(1, $residentData);
        $this->assertSame('Complaint', $residentData[0]['kind']);
        $this->assertSame('resident', $residentData[0]['from']);
        $this->assertSame('barangay', $residentData[0]['staffUsername']);

        $captainHeaders = $this->authHeaders($this->tokenFor('barangay'));
        $this->getJson('/api/data', $captainHeaders)->assertJsonPath('data.messages', []);
        $this->postJson('/api/messages', [
            'userId' => $resident->id,
            'body' => 'Captain should not reply through the monitoring-only account.',
        ], $captainHeaders)->assertForbidden();

        $staffHeaders = $this->roleHeaders('complaint_reply_secretary', 'Secretary');

        $this->postJson('/api/messages', [
            'userId' => $resident->id,
            'body' => 'Please come to the barangay hall on October 16 and bring a valid ID.',
        ], $staffHeaders)->assertOk();

        $this->postJson('/api/messages', [
            'userId' => $resident->id,
            'body' => 'Please come to the barangay hall on October 16 and bring a valid ID.',
        ], $this->authHeaders($this->tokenFor('admin')))->assertForbidden();

        $updated = $this->getJson('/api/data', $residentHeaders)->json('data.messages');
        $this->assertCount(2, $updated);
        $this->assertSame('staff', $updated[1]['from']);
        $this->assertSame('Please come to the barangay hall on October 16 and bring a valid ID.', $updated[1]['body']);
    }

    public function test_laravel_api_exposes_mail_status_without_revealing_credentials(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.username' => 'resident@example.com',
            'mail.mailers.smtp.password' => 'not-returned',
            'mail.from.address' => 'resident@example.com',
        ]);

        $this->seed(BarangayCareSeeder::class);
        $this->getJson('/api/mail-status', $this->authHeaders($this->tokenFor('admin')))
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('host', 'smtp.gmail.com')
            ->assertJsonPath('username', 're******@example.com')
            ->assertJsonMissing(['password' => 'not-returned']);
    }

    public function test_assistance_claim_email_goes_to_the_resident_gmail(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'barangay@gmail.com',
            'mail.mailers.smtp.password' => 'app-password',
            'mail.from.address' => 'barangay@gmail.com',
        ]);

        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        DB::table('users')->where('username', 'user')->update(['email' => 'maria.santos@gmail.com']);
        DB::table('assistance')->where('id', 'A-5001')->update([
            'status' => 'Claimed',
            'claimed_at' => '2026-10-09',
            'claimed_by' => 'user',
        ]);

        $this->postJson('/api/notify-assistance', [
            'record' => [
                'id' => 'A-5001',
                'beneficiary' => 'Maria Santos',
                'type' => 'Food Pack',
            ],
            'eventType' => 'claimed',
        ], $this->authHeaders($this->tokenFor('user')))
            ->assertOk()
            ->assertJsonPath('sentTo', 'maria.santos@gmail.com')
            ->assertJsonPath('eventType', 'claimed')
            ->assertJsonPath('message', "Sent the Food Pack claimed email to Resident Account's Gmail.");

        $this->assertDatabaseHas('logs', [
            'action' => "Emailed maria.santos@gmail.com about Maria Santos's Food Pack (claimed)",
        ]);
    }

    public function test_assistance_mail_requires_laravel_smtp_configuration(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        DB::table('users')->where('username', 'user')->update(['email' => 'resident@example.com']);
        config(['mail.default' => 'log']);

        $this->postJson('/api/notify-assistance', [
            'record' => ['beneficiary' => 'Maria Santos', 'type' => 'Food Pack'],
            'eventType' => 'created',
        ], $this->authHeaders($this->tokenFor('user')))
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('configured', false);
    }

    public function test_secretary_can_email_the_saved_program_schedule_but_captain_cannot(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'barangay@gmail.com',
            'mail.mailers.smtp.password' => 'app-password',
            'mail.from.address' => 'barangay@gmail.com',
        ]);

        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        DB::table('users')->where('username', 'user')->update(['email' => 'maria.santos@example.org']);
        DB::table('programs')->where('id', 'P-101')->update([
            'benefit' => 'Senior Citizen',
            'date' => '2026-10-16',
            'venue' => 'Barangay Hall, Taft',
            'program_time' => '09:00',
            'release_amount' => '5,000',
            'assigned_staff' => 'Ana Secretary',
            'instructions' => 'Bring a valid ID.',
        ]);

        $this->postJson('/api/programs/P-101/notify', [], $this->authHeaders($this->tokenFor('barangay')))
            ->assertForbidden();

        $headers = $this->roleHeaders('schedule_notification_secretary', 'Secretary');
        DB::table('programs')->where('id', 'P-101')->update(['benefit' => '']);

        $this->postJson('/api/programs/P-101/notify', [], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Choose a benefit category before sending this schedule.');

        DB::table('programs')->where('id', 'P-101')->update(['benefit' => 'Senior Citizen']);

        $this->postJson('/api/programs/P-101/notify', [], $headers)
            ->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('benefit', 'Senior Citizen');

        Mail::assertSent(ProgramScheduleNotification::class, function (ProgramScheduleNotification $mail): bool {
            $mail->assertSeeInText('Program: Food Pack Distribution');
            $mail->assertSeeInText('Benefit: Senior Citizen');
            $mail->assertSeeInText('Date: 2026-10-16');
            $mail->assertSeeInText('Time: 09:00');
            $mail->assertSeeInText('Venue: Barangay Hall, Taft');
            $mail->assertSeeInText('Amount / Items: 5,000');
            $mail->assertSeeInText('Assigned Staff: Ana Secretary');
            $mail->assertSeeInText('Instructions: Bring a valid ID.');

            return $mail->hasTo('maria.santos@example.org')
                && $mail->hasSubject('Upcoming benefit release: Food Pack Distribution');
        });

        $this->assertDatabaseHas('logs', [
            'action' => 'Emailed 1 residents the latest Food Pack Distribution schedule (Senior Citizen, 2026-10-16)',
        ]);

        DB::table('programs')->where('id', 'P-101')->update(['benefit' => 'PWD', 'date' => '2026-11-02']);

        $this->postJson('/api/programs/P-101/notify', [], $headers)
            ->assertOk()
            ->assertJsonPath('sent', 0)
            ->assertJsonPath('message', 'No active PWD resident account has a valid registered email address.');

        Mail::assertSentCount(1);
    }

    public function test_schedule_email_reports_when_smtp_is_not_configured(): void
    {
        Mail::fake();
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->roleHeaders('schedule_email_without_smtp', 'Secretary');
        config(['mail.default' => 'log']);

        $this->postJson('/api/programs/P-101/notify', [], $headers)
            ->assertServiceUnavailable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('configured', false)
            ->assertJsonPath('error', 'Email sending is not configured. Set MAIL_MAILER=smtp and the sender SMTP host, port, username, password, and MAIL_FROM_ADDRESS in laravel-api/.env.');

        Mail::assertNothingSent();
    }

    public function test_legacy_php_api_urls_remain_available_as_compatibility_aliases(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->authHeaders($this->tokenFor('admin'));

        $this->getJson('/api/health.php')
            ->assertOk()
            ->assertJsonPath('service', 'BarangayCare Laravel API');

        $this->getJson('/api/mail-status.php', $headers)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson('/api/backup.php', $headers)
            ->assertForbidden();
    }

    public function test_private_records_require_sign_in_and_residents_only_see_their_own(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        $residentUser = DB::table('users')->where('username', 'user')->first();
        $this->roleHeaders('other_resident', 'User');
        $otherResident = DB::table('users')->where('username', 'other_resident')->first();

        $messageId = DB::table('resident_messages')->insertGetId([
            'user_id' => $residentUser->id,
            'resident_name' => 'Maria Santos',
            'username' => 'user',
            'kind' => 'Question',
            'body' => 'My schedule details.',
            'from_role' => 'staff',
        ]);
        DB::table('resident_messages')->insert([
            'user_id' => $otherResident->id,
            'resident_name' => $otherResident->name,
            'username' => 'other_resident',
            'kind' => 'Question',
            'body' => 'Another resident message.',
            'from_role' => 'staff',
        ]);
        $distributionId = DB::table('distributions')->insertGetId([
            'user_id' => $residentUser->id,
            'beneficiary' => 'Maria Santos',
            'benefit' => 'Food Pack',
            'amount' => '1 pack',
            'distributed_by' => 'Barangay Staff',
            'distributed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('distributions')->insert([
            'user_id' => $otherResident->id,
            'beneficiary' => $otherResident->name,
            'benefit' => 'Food Pack',
            'amount' => '1 pack',
            'distributed_by' => 'Barangay Staff',
            'distributed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/data')->assertUnauthorized();
        $this->getJson('/api/summary')
            ->assertOk()
            ->assertJsonPath('data.meta.beneficiaries', 4)
            ->assertJsonPath('data.users', []);

        $resident = $this->getJson('/api/data', $this->authHeaders($this->tokenFor('user')))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $resident['beneficiaries']);
        $this->assertSame('Maria Santos', $resident['beneficiaries'][0]['name']);
        $this->assertCount(1, $resident['users']);
        $this->assertSame('user', $resident['users'][0]['username']);
        $this->assertTrue(collect($resident['assistance'])->every(
            fn (array $record): bool => $record['beneficiary'] === 'Maria Santos'
        ));
        $this->assertSame([$messageId], array_column($resident['messages'], 'id'));
        $this->assertSame([$distributionId], array_column($resident['distributions'], 'id'));
    }

    public function test_secretary_can_access_completed_distribution_records_for_reporting(): void
    {
        $headers = $this->roleHeaders('secretary_release_report', 'Secretary');
        $distributedAt = '2026-11-04 10:15:00';
        $distributionId = DB::table('distributions')->insertGetId([
            'program_id' => 'P-101',
            'assistance_id' => 'A-101',
            'beneficiary' => 'Maria Santos',
            'benefit' => 'Senior Citizen',
            'amount' => '2 food packages',
            'distributed_by' => 'Distribution Staff',
            'distributed_at' => $distributedAt,
            'created_at' => $distributedAt,
            'updated_at' => $distributedAt,
        ]);

        $this->getJson('/api/data', $headers)
            ->assertOk()
            ->assertJsonPath('data.distributions.0.id', $distributionId)
            ->assertJsonPath('data.distributions.0.programId', 'P-101')
            ->assertJsonPath('data.distributions.0.assistanceId', 'A-101')
            ->assertJsonPath('data.distributions.0.beneficiary', 'Maria Santos')
            ->assertJsonPath('data.distributions.0.amount', '2 food packages')
            ->assertJsonPath('data.distributions.0.distributedBy', 'Distribution Staff')
            ->assertJsonPath('data.distributions.0.distributedAt', $distributedAt);
    }

    public function test_barangay_data_exposes_account_monitoring_aggregates_only(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->travelTo('2026-07-01 10:00:00');
        $this->ensureResidentAccount();
        $secretaryHeaders = $this->roleHeaders('captain_summary_secretary', 'Secretary');
        $secretaryData = $this->getJson('/api/data', $secretaryHeaders)->assertOk()->json('data');
        $programIndex = array_search('P-101', array_column($secretaryData['programs'], 'id'), true);
        $this->assertNotFalse($programIndex);
        $secretaryData['programs'][$programIndex]['date'] = now()->addDay()->toDateString();
        $secretaryData['programs'][$programIndex]['time'] = '09:00';
        $this->postJson('/api/data', ['data' => $secretaryData], $secretaryHeaders)->assertOk();
        $this->assertDatabaseHas('programs', [
            'id' => 'P-101',
            'date' => now()->addDay()->toDateString(),
        ]);
        $distributionHeaders = $this->roleHeaders('captain_summary_distributor', 'Distribution Staff');
        $this->postJson('/api/assistance/claim', [
            'id' => 'A-5001',
            'programId' => 'P-101',
            'amount' => '2 food packages',
        ], $distributionHeaders)->assertOk();
        DB::table('distributions')->insert([
            'assistance_id' => 'A-5002',
            'beneficiary' => 'Historical Resident',
            'benefit' => 'PWD',
            'amount' => 'Sensitive historical amount',
            'distributed_by' => 'Historical Staff',
            'distributed_at' => now()->subDays(31),
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        $data = $this->getJson('/api/data', $this->authHeaders($this->tokenFor('barangay')))
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data['beneficiaries']);
        $this->assertSame([], $data['assistance']);
        $this->assertSame([], $data['logs']);
        $this->assertSame([], $data['messages']);
        $this->assertSame([], $data['programs']);
        $this->assertCount(3, $data['users']);
        $this->assertContains('User', array_column($data['users'], 'role'));
        $this->assertSame(1, $data['monitoring']['residentAccounts']);
        $accountNames = array_column($data['users'], 'name');
        $this->assertContains('Resident Account', $accountNames);
        $this->assertContains('Captain Summary Secretary', $accountNames);
        $this->assertContains('Captain Summary Distributor', $accountNames);
        $this->assertSame(3, $data['monitoring']['accounts']);
        $this->assertSame(3, $data['monitoring']['activeAccounts']);
        $this->assertSame(1, $data['monitoring']['residentAccounts']);
        $this->assertSame(2, $data['monitoring']['staffAccounts']);
        foreach ($data['users'] as $account) {
            $this->assertSame(['id', 'name', 'role', 'status'], array_keys($account));
        }
        $this->assertSame([
            'residentProfiles' => 4,
            'activeResidentProfiles' => 3,
            'assistanceAssignments' => 3,
            'awaitingHandover' => 2,
            'completedHandovers' => 2,
            'recentHandovers' => 1,
            'upcomingPrograms' => 1,
        ], $data['monitoring']['services']);
        $this->assertSame([
            [
                'name' => 'Food Pack Distribution',
                'date' => now()->addDay()->toDateString(),
                'time' => '09:00',
                'venue' => 'Barangay Hall',
            ],
        ], $data['monitoring']['upcomingSchedules']);
        $this->assertArrayNotHasKey('amount', $data['monitoring']['upcomingSchedules'][0]);
        $this->assertArrayNotHasKey('beneficiary', $data['monitoring']['upcomingSchedules'][0]);
        $this->assertArrayNotHasKey('username', $data['users'][0]);
        $this->assertArrayNotHasKey('email', $data['users'][0]);
        $this->assertEmpty($data['applications']);
        $this->assertEmpty($data['distributions']);
        $this->assertArrayNotHasKey('beneficiary', $data['monitoring']['services']);
    }

    public function test_captain_dashboard_shows_recent_handovers_and_upcoming_schedules(): void
    {
        $this->get('/assets/app.js')
            ->assertOk()
            ->assertSee('Recent operations and upcoming schedules', false)
            ->assertSee('services.recentHandovers ?? 0', false)
            ->assertSee('counts.upcomingSchedules?.length', false)
            ->assertSee('Resident names and claimed amounts are not included.', false);
    }

    public function test_barangay_captain_cannot_update_program_schedules(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $assistanceCount = DB::table('assistance')->count();
        $headers = $this->authHeaders($this->tokenFor('barangay'));
        $data = $this->getJson('/api/data', $headers)->assertOk()->json('data');
        $this->postJson('/api/data', ['data' => $data], $headers)
            ->assertForbidden();

        $this->assertDatabaseHas('programs', ['id' => 'P-101', 'name' => 'Food Pack Distribution']);
        $this->assertDatabaseHas('beneficiaries', ['id' => 'B-2026-001', 'name' => 'Maria Santos']);
        $this->assertSame($assistanceCount, DB::table('assistance')->count());
        $this->assertDatabaseHas('users', ['username' => 'barangay']);
        $this->assertDatabaseHas('users', ['username' => 'admin']);
    }

    public function test_public_summary_does_not_expose_resident_assistance_details(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $assistanceCount = DB::table('assistance')->count();

        $this->getJson('/api/summary')
            ->assertOk()
            ->assertJsonPath('data.assistance', [])
            ->assertJsonPath('data.meta.assistance', $assistanceCount)
            ->assertJsonMissing(['beneficiary' => 'Maria Santos']);
    }

    public function test_barangay_monitoring_does_not_expose_audit_logs(): void
    {
        $this->seed(BarangayCareSeeder::class);
        DB::table('logs')->insert([
            ['event_time' => '2026-10-26 10:00:00', 'actor' => 'admin', 'action' => 'Admin-only activity'],
            ['event_time' => '2026-10-26 10:01:00', 'actor' => 'barangay', 'action' => 'Barangay activity'],
            ['event_time' => '2026-10-26 10:02:00', 'actor' => 'user', 'action' => 'Resident activity'],
        ]);

        $logs = $this->getJson('/api/data', $this->authHeaders($this->tokenFor('barangay')))
            ->assertOk()
            ->json('data.logs');
        $this->assertSame([], $logs);
    }

    public function test_super_admin_can_read_profiles_but_cannot_update_operational_records(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $this->ensureResidentAccount();
        DB::table('users')->where('username', 'admin')->update(['profile_image' => 'data:image/png;base64,abc']);

        $this->postJson('/api/auth', [
            'username' => 'admin',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.profileImage', 'data:image/png;base64,abc');

        $headers = $this->authHeaders($this->tokenFor('admin'));
        $data = $this->getJson('/api/data', $headers)->json('data');
        $data['beneficiaries'][0]['name'] = 'Maria S. Santos';
        $this->postJson('/api/data', ['data' => $data], $headers)->assertForbidden();

        $this->assertDatabaseHas('assistance', [
            'id' => 'A-5001',
            'beneficiary' => 'Maria Santos',
        ]);
        $this->assertDatabaseHas('users', [
            'username' => 'user',
            'beneficiary_name' => 'Maria Santos',
        ]);
    }

    public function test_profile_saves_emergency_contact_without_replacing_the_database(): void
    {
        $this->seed(BarangayCareSeeder::class);
        $headers = $this->authHeaders($this->tokenFor('user'));

        $this->postJson('/api/profile', [
            'name' => 'Maria Santos',
            'contact' => '09170001111',
            'email' => 'maria@example.com',
            'address' => 'Purok 1',
            'emergencyContact' => '09180002222',
            'profileImage' => '',
        ], $headers)->assertOk();

        $this->assertDatabaseHas('users', [
            'username' => 'user',
            'emergency_contact' => '09180002222',
            'email' => 'maria@example.com',
        ]);
        $this->assertDatabaseHas('beneficiaries', ['name' => 'Juan Dela Cruz']);
    }
}
