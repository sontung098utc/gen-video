<?php

declare(strict_types=1);

use App\Enums\CostTierMode;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\WorkflowMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration khởi tạo bảng `video_projects` quản lý thông tin tổng thể của Dự án Video.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_projects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->comment('Mã định danh UUID duy nhất của dự án');
            $table->string('title', 255)->comment('Tiêu đề dự án video');
            $table->string('project_type', 32)
                ->default(ProjectType::KNOWLEDGE->value)
                ->index()
                ->comment('Loại dự án: knowledge (Kiến thức) hoặc product (Sản phẩm 3D)');
            $table->text('topic_or_brief')->nullable()->comment('Chủ đề kiến thức hoặc mô tả tóm tắt sản phẩm');
            $table->string('aspect_ratio', 16)
                ->default('9:16')
                ->comment('Tỷ lệ khung hình: 9:16 (TikTok/Shorts/Reels) hoặc 16:9 (YouTube)');
            $table->string('status', 48)
                ->default(ProjectStatus::DRAFT->value)
                ->index()
                ->comment('Trạng thái hiện tại trong State Machine (bao gồm 4 cổng kiểm duyệt)');
            $table->string('workflow_mode', 32)
                ->default(WorkflowMode::STEP_BY_STEP->value)
                ->comment('Chế độ duyệt: step_by_step (4 Checkpoints) hoặc auto (Full-Auto)');
            $table->string('cost_tier_mode', 32)
                ->default(CostTierMode::ECO_MODE->value)
                ->comment('Chế độ chi phí hình ảnh: eco_mode ($0.003/ảnh + 60fps) hoặc full_ai_video_mode ($0.02/clip)');
            $table->string('voice_id', 64)
                ->default('vi-VN-NamMinhNeural')
                ->comment('Giọng đọc Edge-TTS tiếng Việt: vi-VN-NamMinhNeural hoặc vi-VN-HoaiMyNeural');
            $table->text('master_style_prompt')->nullable()->comment('Prompt định hình phong cách nghệ thuật xuyên suốt các phân cảnh');
            $table->string('bgm_file_path', 512)->nullable()->comment('Đường dẫn file nhạc nền (BGM) trong kho storage/app/sfx_library/');
            $table->string('final_video_path', 512)->nullable()->comment('Đường dẫn file video .mp4 hoàn chỉnh sau khi FFmpeg kết xuất');
            $table->unsignedInteger('total_duration_ms')->default(0)->comment('Tổng thời lượng video tính bằng mili-giây');
            $table->decimal('total_cost_usd', 10, 4)->default(0.0000)->comment('Tổng chi phí API thực tế đã tiêu tốn (USD)');
            $table->text('error_message')->nullable()->comment('Thông báo lỗi chi tiết nếu dự án chuyển sang trạng thái failed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_projects');
    }
};
