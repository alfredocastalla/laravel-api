<?php

use App\Http\Controllers\BarangayCareApiController;
use Illuminate\Support\Facades\Route;

Route::name('api.')
    ->controller(BarangayCareApiController::class)
    ->group(function (): void {
        Route::get('/health', 'health')->name('health');
        Route::get('/summary', 'summary')->name('summary');
        Route::post('/auth', 'auth')->middleware('throttle:barangay-login')->name('auth');
        Route::post('/password/forgot', 'forgotPassword')->middleware('throttle:5,1,password-reset-request')->name('password.forgot');
        Route::post('/password/reset', 'resetForgottenPassword')->middleware('throttle:10,1,password-reset-completion')->name('password.reset');
        Route::get('/version', 'version')->name('version');

        Route::middleware('barangay.auth')->group(function (): void {
            Route::get('/data', 'data')->name('data.index');
            Route::post('/data', 'saveData')->name('data.save');
            Route::post('/logout', 'logout')->name('logout');
            Route::post('/profile', 'updateProfile')->name('profile.update');
            Route::post('/residents', 'createResident')->name('residents.store');
            Route::patch('/residents/{resident}', 'updateResident')->whereNumber('resident')->name('residents.update');
            Route::patch('/beneficiaries/{beneficiary}/verification', 'updateBeneficiaryVerification')
                ->where('beneficiary', '[A-Za-z0-9_-]+')
                ->name('beneficiaries.verification.update');
            Route::post('/staff/accounts', 'createStaffAccount')->name('staff.accounts.store');
            Route::patch('/staff/accounts/{staff}/status', 'updateStaffAccountStatus')
                ->whereNumber('staff')
                ->name('staff.accounts.status.update');
            Route::patch('/staff/accounts/{staff}/password', 'resetStaffAccountPassword')
                ->whereNumber('staff')
                ->name('staff.accounts.password.reset');
            Route::delete('/staff/accounts/{staff}', 'deleteStaffAccount')
                ->whereNumber('staff')
                ->name('staff.accounts.destroy');
            Route::post('/password', 'changePassword')->name('password.change');
            Route::post('/assistance/claim', 'claimAssistance')->name('assistance.claim');
            Route::post('/assistance/applications', 'submitAssistanceApplication')->name('assistance.applications.store');
            Route::post('/assistance/applications/{application}/proof-requests', 'requestAssistanceApplicationProof')
                ->whereNumber('application')
                ->name('assistance.applications.proof-requests.store');
            Route::post('/assistance/applications/{application}/proofs', 'uploadAssistanceApplicationProof')
                ->whereNumber('application')
                ->name('assistance.applications.proofs.store');
            Route::get('/assistance/applications/{application}/proofs/{document}/download', 'downloadAssistanceApplicationProof')
                ->whereNumber('application')
                ->whereNumber('document')
                ->name('assistance.applications.proofs.download');
            Route::post('/assistance/applications/{application}/review', 'reviewAssistanceApplication')
                ->whereNumber('application')
                ->name('assistance.applications.review');
            Route::post('/assistance/applications/{application}/distribute', 'distributeAssistanceApplication')
                ->whereNumber('application')
                ->name('assistance.applications.distribute');
            Route::post('/notify-assistance', 'notifyAssistance')->name('assistance.notify');
            Route::post('/programs/{program}/notify', 'notifyProgram')->name('programs.notify');
            Route::get('/mail-status', 'mailStatus')->name('mail.status');
            Route::get('/backup', 'backup')->name('backup');
            Route::post('/backup/restore', 'restore')->name('backup.restore');
            Route::post('/chat', 'chat')->name('chat');
            Route::post('/messages', 'storeMessage')->name('messages.store');
        });

        Route::name('legacy.')->group(function (): void {
            Route::get('/health.php', 'health')->name('health');
            Route::post('/auth.php', 'auth')->middleware('throttle:barangay-login')->name('auth');
            Route::get('/version.php', 'version')->name('version');

            Route::middleware('barangay.auth')->group(function (): void {
                Route::get('/data.php', 'data')->name('data.index');
                Route::post('/data.php', 'saveData')->name('data.save');
                Route::post('/notify-assistance.php', 'notifyAssistance')->name('assistance.notify');
                Route::post('/programs/{program}/notify.php', 'notifyProgram')->name('programs.notify');
                Route::get('/mail-status.php', 'mailStatus')->name('mail.status');
                Route::get('/backup.php', 'backup')->name('backup');
                Route::post('/chat.php', 'chat')->name('chat');
            });
        });
    });
