<?php

declare(strict_types=1);

use App\Http\Controllers\ProjectWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Hệ Thống Tạo Video Ngắn Tự Động Bằng AI (Sprint 3 Studio UI)
|--------------------------------------------------------------------------
*/

Route::get('/', static fn () => redirect()->route('projects.index'));

Route::get('/projects', [ProjectWebController::class, 'index'])
    ->name('projects.index');

Route::get('/projects/create', [ProjectWebController::class, 'create'])
    ->name('projects.create');

Route::post('/projects', [ProjectWebController::class, 'store'])
    ->name('projects.store');

Route::get('/projects/{id}/studio', [ProjectWebController::class, 'studio'])
    ->whereNumber('id')
    ->name('projects.studio');
