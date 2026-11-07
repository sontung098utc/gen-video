<?php

declare(strict_types=1);

use App\Http\Controllers\ProjectWebController;
use App\Http\Controllers\WorkflowApprovalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Hệ Thống Tạo Video Ngắn Tự Động Bằng AI (Sprint 2 & Sprint 3)
|--------------------------------------------------------------------------
| Quản lý Polling trạng thái thời gian thực, 4 Cổng kiểm duyệt (CP1 -> CP4),
| tái tạo từng phân cảnh lẻ và chuyển đổi chế độ Step-by-Step <-> Full-Auto.
*/

Route::prefix('projects')->group(function (): void {
    // Endpoint Polling trạng thái dự án thời gian thực (mỗi 2-3 giây)
    Route::get('{id}/status', [ProjectWebController::class, 'status'])
        ->whereNumber('id')
        ->name('api.projects.status');

    // Cổng kiểm duyệt số 1 (CP1): Duyệt thông tin sản phẩm & mẫu 3D .glb
    Route::post('{id}/approve-product', [WorkflowApprovalController::class, 'approveProduct'])
        ->whereNumber('id')
        ->name('api.projects.approve-product');

    // Cổng kiểm duyệt số 2 (CP2): Duyệt/Cập nhật kịch bản tiếng Việt & visual prompt
    Route::post('{id}/approve-script', [WorkflowApprovalController::class, 'approveScript'])
        ->whereNumber('id')
        ->name('api.projects.approve-script');

    // Cổng kiểm duyệt số 3 (CP3): Duyệt giọng đọc Edge-TTS & âm thanh SFX/BGM
    Route::post('{id}/approve-audio', [WorkflowApprovalController::class, 'approveAudio'])
        ->whereNumber('id')
        ->name('api.projects.approve-audio');

    // Cổng kiểm duyệt số 4 (CP4): Duyệt toàn bộ các đoạn video cảnh & hiệu ứng chuyển cảnh
    Route::post('{id}/approve-scenes', [WorkflowApprovalController::class, 'approveScenes'])
        ->whereNumber('id')
        ->name('api.projects.approve-scenes');

    // Công tắc chuyển đổi chế độ dự án giữa STEP_BY_STEP và AUTO
    Route::post('{id}/toggle-mode', [WorkflowApprovalController::class, 'toggleMode'])
        ->whereNumber('id')
        ->name('api.projects.toggle-mode');
});

// Tạo lại (re-generate) riêng một bối cảnh bất kỳ mà không phải tạo lại cả dự án
Route::post('scenes/{sceneId}/regenerate', [WorkflowApprovalController::class, 'regenerateScene'])
    ->whereNumber('sceneId')
    ->name('api.scenes.regenerate');
