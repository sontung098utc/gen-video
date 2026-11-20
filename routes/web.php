<?php

declare(strict_types=1);

use App\Http\Controllers\HealthStudioController;
use App\Http\Controllers\ProjectWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Sống Khỏe Mỗi Ngày: 8-Agent AI Health Video Studio
|--------------------------------------------------------------------------
*/

// Trang chủ: Sống Khỏe Mỗi Ngày — 8-Agent Studio Dashboard
Route::get('/', [HealthStudioController::class, 'dashboard'])->name('health.dashboard');
Route::post('/health/quick-produce', [HealthStudioController::class, 'quickProduce'])->name('health.quick-produce');

// Agent 1 & Agent 2: Kế hoạch nội dung Tháng -> Tuần -> Ngày & Tiêu đề SEO 3 nền tảng
Route::get('/plans', [HealthStudioController::class, 'plans'])->name('health.plans');
Route::post('/plans', [HealthStudioController::class, 'storePlan'])->name('health.plans.store');
Route::get('/plans/{id}', [HealthStudioController::class, 'showPlan'])->whereNumber('id')->name('health.plans.show');
Route::post('/plans/{id}/seo-titles', [HealthStudioController::class, 'generateSeoTitles'])->whereNumber('id')->name('health.plans.seo');
Route::post('/plan-items/{itemId}/produce', [HealthStudioController::class, 'produceFromItem'])->whereNumber('itemId')->name('health.plan-items.produce');

// Phòng Sản Xuất & Kiểm Duyệt Agent 3 -> 8
Route::get('/health-studio/{id}', [HealthStudioController::class, 'studio'])->whereNumber('id')->name('health.studio');
Route::post('/health-studio/{id}/config', [HealthStudioController::class, 'updateProjectConfig'])->whereNumber('id')->name('health.studio.config');
Route::post('/health-studio/{id}/rework', [HealthStudioController::class, 'reworkAgent'])->whereNumber('id')->name('health.studio.rework');

// Kho Tài Liệu Kiến Thức Sức Khỏe Chính Thống
Route::get('/knowledge', [HealthStudioController::class, 'knowledge'])->name('health.knowledge');
Route::post('/knowledge', [HealthStudioController::class, 'storeKnowledge'])->name('health.knowledge.store');
Route::delete('/knowledge/{id}', [HealthStudioController::class, 'deleteKnowledge'])->whereNumber('id')->name('health.knowledge.delete');

// Kho Video Châu Á Nội Bộ (Agent 4)
Route::get('/stock-library', [HealthStudioController::class, 'stockLibrary'])->name('health.stock');
Route::post('/stock-library', [HealthStudioController::class, 'storeStockAsset'])->name('health.stock.store');
Route::delete('/stock-library/{id}', [HealthStudioController::class, 'deleteStockAsset'])->whereNumber('id')->name('health.stock.delete');

// Quy Chuẩn Kiểm Duyệt của Trưởng Phòng Agent 8
Route::get('/qa-rules', [HealthStudioController::class, 'qaRules'])->name('health.qa-rules');
Route::post('/qa-rules', [HealthStudioController::class, 'updateQaRules'])->name('health.qa-rules.update');

// Cài Đặt Kênh & API Keys (Google TTS, Vbee, GenMax, Pexels, Pixabay, Gemini, ChatGPT, Muse AI)
Route::get('/settings', [HealthStudioController::class, 'settings'])->name('health.settings');
Route::post('/settings', [HealthStudioController::class, 'updateSettings'])->name('health.settings.update');
Route::post('/settings/voices', [HealthStudioController::class, 'storeCustomVoice'])->name('health.settings.voices.store');
Route::delete('/settings/voices/{id}', [HealthStudioController::class, 'deleteCustomVoice'])->whereNumber('id')->name('health.settings.voices.delete');

// Giữ lại các route cũ tương thích ngược cho các dự án trước đó
Route::get('/projects', [ProjectWebController::class, 'index'])->name('projects.index');
Route::get('/projects/create', [ProjectWebController::class, 'create'])->name('projects.create');
Route::post('/projects', [ProjectWebController::class, 'store'])->name('projects.store');
Route::get('/projects/{id}/studio', [ProjectWebController::class, 'studio'])->whereNumber('id')->name('projects.studio');
Route::post('/projects/{id}/clone-variant', [ProjectWebController::class, 'cloneVariant'])->whereNumber('id')->name('projects.clone-variant');
