<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\GeneratedContentController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('projects.index')
        : view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::resource('projects', ProjectController::class);

    Route::get('/projects/{project}/generated-content/create', [GeneratedContentController::class, 'create'])
        ->name('projects.generated-content.create');
    Route::post('/projects/{project}/generated-content', [GeneratedContentController::class, 'store'])
        ->name('projects.generated-content.store');
    Route::get('/projects/{project}/generated-content/{generatedContent}', [GeneratedContentController::class, 'show'])
        ->scopeBindings()
        ->name('projects.generated-content.show');
});
