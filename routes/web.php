<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\GeneratedContentContinuationController;
use App\Http\Controllers\GeneratedContentController;
use App\Http\Controllers\GeneratedContentExportController;
use App\Http\Controllers\GeneratedContentManagementController;
use App\Http\Controllers\ProjectContextController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('projects.index')
        : view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.update');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/account/settings', [AccountSettingsController::class, 'show'])->name('account.settings');
    Route::patch('/account/settings/profile', [AccountSettingsController::class, 'updateProfile'])
        ->name('account.settings.profile.update');
    Route::put('/account/settings/password', [AccountSettingsController::class, 'updatePassword'])
        ->name('account.settings.password.update');
    Route::delete('/account/settings', [AccountSettingsController::class, 'destroy'])
        ->name('account.settings.destroy');
    Route::put('/account/settings/openai-credential', [AccountSettingsController::class, 'store'])
        ->name('account.settings.openai-credential.store');
    Route::delete('/account/settings/openai-credential', [AccountSettingsController::class, 'removeOpenAiCredential'])
        ->name('account.settings.openai-credential.destroy');
    Route::resource('projects', ProjectController::class);
    Route::get('/projects/{project}/export', ProjectExportController::class)
        ->name('projects.export');
    Route::get('/projects/{project}/context/edit', [ProjectContextController::class, 'edit'])
        ->name('projects.context.edit');
    Route::put('/projects/{project}/context', [ProjectContextController::class, 'update'])
        ->name('projects.context.update');

    Route::get('/projects/{project}/generated-content/create', [GeneratedContentController::class, 'create'])
        ->name('projects.generated-content.create');
    Route::post('/projects/{project}/generated-content', [GeneratedContentController::class, 'store'])
        ->name('projects.generated-content.store');
    Route::patch('/projects/{project}/generated-content/{generatedContent}', [GeneratedContentManagementController::class, 'update'])
        ->scopeBindings()
        ->name('projects.generated-content.update');
    Route::delete('/projects/{project}/generated-content/{generatedContent}', [GeneratedContentManagementController::class, 'destroy'])
        ->scopeBindings()
        ->name('projects.generated-content.destroy');
    Route::get('/projects/{project}/generated-content/{generatedContent}', [GeneratedContentController::class, 'show'])
        ->scopeBindings()
        ->name('projects.generated-content.show');
    Route::get('/projects/{project}/generated-content/{generatedContent}/versions/{versionNumber}', [GeneratedContentController::class, 'showVersion'])
        ->where('versionNumber', '[1-9][0-9]*')
        ->scopeBindings()
        ->name('projects.generated-content.versions.show');
    Route::get('/projects/{project}/generated-content/{generatedContent}/export/{format}', [GeneratedContentExportController::class, 'latest'])
        ->where('format', 'html|pdf|png')
        ->scopeBindings()
        ->name('projects.generated-content.export');
    Route::get('/projects/{project}/generated-content/{generatedContent}/versions/{versionNumber}/export/{format}', [GeneratedContentExportController::class, 'version'])
        ->where(['versionNumber' => '[1-9][0-9]*', 'format' => 'html|pdf|png'])
        ->scopeBindings()
        ->name('projects.generated-content.versions.export');
    Route::get('/projects/{project}/generated-content/{generatedContent}/versions/{versionNumber}/edit', [GeneratedContentController::class, 'editVersion'])
        ->where('versionNumber', '[1-9][0-9]*')
        ->scopeBindings()
        ->name('projects.generated-content.versions.edit');
    Route::post('/projects/{project}/generated-content/{generatedContent}/versions/{versionNumber}/edits', [GeneratedContentController::class, 'storeVersionEdit'])
        ->where('versionNumber', '[1-9][0-9]*')
        ->scopeBindings()
        ->name('projects.generated-content.versions.edits.store');
    Route::get('/projects/{project}/generated-content/{generatedContent}/versions/{versionNumber}/continuation', [GeneratedContentContinuationController::class, 'create'])
        ->where('versionNumber', '[1-9][0-9]*')
        ->scopeBindings()
        ->name('projects.generated-content.versions.continuations.create');
    Route::post('/projects/{project}/generated-content/{generatedContent}/versions/{versionNumber}/continuation', [GeneratedContentContinuationController::class, 'store'])
        ->where('versionNumber', '[1-9][0-9]*')
        ->scopeBindings()
        ->name('projects.generated-content.versions.continuations.store');
});
