<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration khởi tạo bảng `generation_logs` theo dõi toàn bộ lịch sử gọi API AI,
 * độ trễ (latency) và chi phí thực tế (USD) phục vụ kiểm toán chiến lược Siêu Tiết Kiệm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_project_id')
                ->nullable()
                ->constrained('video_projects')
                ->nullOnDelete()
                ->comment('Dự án video liên quan');
            $table->foreignId('storyboard_scene_id')
                ->nullable()
                ->constrained('storyboard_scenes')
                ->nullOnDelete()
                ->comment('Phân cảnh kịch bản liên quan (nếu có)');
            $table->string('provider', 64)->index()->comment('Nhà cung cấp dịch vụ: gemini, edge_tts, fal_ai, local_cpu');
            $table->string('model_name', 128)->index()->comment('Tên model/công cụ: gemini-2.5-flash, vi-VN-NamMinhNeural, fal-ai/flux/schnell, fal-ai/trellis...');
            $table->string('action_type', 64)->comment('Loại tác vụ: analyze_topic, analyze_product, synthesize_tts, generate_image, generate_video, generate_3d');
            $table->string('status', 32)->default('success')->index()->comment('Trạng thái cuộc gọi: success, cached, failed');
            $table->decimal('cost_usd', 10, 4)->default(0.0000)->comment('Chi phí thực tế tính bằng USD (0.0000 cho Gemini Free Tier, Edge-TTS và Cache Hit)');
            $table->unsignedInteger('latency_ms')->default(0)->comment('Thời gian phản hồi tính bằng mili-giây');
            $table->json('request_payload')->nullable()->comment('Dữ liệu đầu vào gửi tới API/CLI (đã lược bỏ chuỗi base64 quá lớn)');
            $table->json('response_payload')->nullable()->comment('Dữ liệu phản hồi từ API/CLI');
            $table->text('error_message')->nullable()->comment('Chi tiết lỗi nếu cuộc gọi thất bại');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_logs');
    }
};
