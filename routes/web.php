<?php

use App\Http\Controllers\BarangayCareFrontendController;
use Illuminate\Support\Facades\Route;

$app = BarangayCareFrontendController::class;
$roles = ['user', 'resident', 'admin', 'barangay', 'secretary', 'distribution-staff', 'auditor'];
$rolePages = [
    'resident' => [
        'resident-dashboard',
        'my-assistance',
        'upcoming-schedule',
        'ai-message',
        'reports',
        'my-reports-history',
        'profile',
        'settings',
    ],
    'admin' => [
        'dashboard-overview',
        'beneficiary-management',
        'social-assistance',
        'scheduling-and-distribution',
        'registered-accounts',
        'staff-management',
        'reports-and-analytics',
        'activity-and-audit-logs',
        'notifications',
        'overview',
        'account-monitoring',
        'system-settings',
        'modules',
        'audit-logs',
        'backup-and-restore',
        'activity-monitor',
    ],
    'secretary' => [
        'dashboard-overview',
        'beneficiary-management',
        'social-assistance',
        'scheduling-and-distribution',
        'resident-accounts',
        'reports-and-analytics',
        'profile',
    ],
    'distribution-staff' => [
        'dashboard-overview',
        'scheduling-and-distribution',
        'social-assistance',
        'resident-accounts',
        'profile',
    ],
    'auditor' => [
        'dashboard-overview',
        'reports-and-analytics',
        'activity-and-audit-logs',
        'profile',
    ],
    'barangay' => [
        'dashboard',
        'beneficiaries',
        'assistance',
        'beneficiaries-assistance',
        'programs',
        'ai-messages',
        'monitoring',
        'reports',
        'users',
        'staff-and-resident-accounts',
        'settings',
        'profile',
    ],
];

Route::get('/', $app);
Route::get('/assets/app.css', [$app, 'stylesheet'])->name('frontend.app-stylesheet');
Route::get('/assets/app.js', [$app, 'script'])->name('frontend.app-script');
Route::get('/login', $app);
Route::redirect('/login/assistance-officer', '/login');
Route::redirect('/login/staff', '/login/barangay');
Route::get('/login/{role}', $app)->whereIn('role', $roles);
Route::redirect('/signup', '/login');

Route::redirect('/app', '/login');
Route::redirect('/app/assistance-officer', '/login');
Route::get('/app/assistance-officer/{page}', fn () => redirect('/login'))->where('page', '.*');
Route::redirect('/app/admin/resident-management', '/app/admin/registered-accounts');
Route::redirect('/app/resident/ai-chatbot', '/app/resident/ai-message');
Route::redirect('/app/resident/messages-&-notifications', '/app/resident/ai-message');
Route::redirect('/app/resident/upcoming-schedules', '/app/resident/upcoming-schedule');
Route::redirect('/app/user/messages-&-notifications', '/app/resident/ai-message');
Route::redirect('/app/user/ai-chatbot', '/app/resident/ai-message');
Route::redirect('/app/user/upcoming-schedules', '/app/resident/upcoming-schedule');
Route::redirect('/app/user', '/app/resident');
Route::get('/app/user/{page}', fn (string $page) => redirect("/app/resident/{$page}"))
    ->whereIn('page', $rolePages['resident']);
Route::redirect('/app/staff', '/app/barangay');
Route::get('/app/staff/{page}', fn (string $page) => redirect("/app/barangay/{$page}"))
    ->whereIn('page', $rolePages['barangay']);
Route::get('/app/{role}', $app)->whereIn('role', $roles);
foreach ($rolePages as $role => $pages) {
    Route::get("/app/{$role}/{page}", $app)->whereIn('page', $pages);
}
