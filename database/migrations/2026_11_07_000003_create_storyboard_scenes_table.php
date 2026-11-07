<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration khởi tạo bảng `storyboard_scenes` quản lý từng phân cảnh trong kịch bản video.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storyboard_scenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_project_id')
                ->constrained('video_projects')
                ->cascadeOnDelete()
                ->comment('Khóa ngoại liên kết tới dự án video');
            $table->unsignedSmallInteger('scene_order')->comment('Thứ tự phân cảnh trong kịch bản (bắt đầu từ 1)');
            $table->text('narration_text')->comment('Câu thoại tiếng Việt dùng để tổng hợp giọng đọc Edge-TTS');
            $table->text('visual_prompt')->comment('Prompt tiếng Anh mô tả chi tiết hình ảnh/chuyển động cho Flux Schnell hoặc LTX-Video');
            $table->string('sfx_prompt', 255)->nullable()->comment('Từ khóa hiệu ứng âm thanh (SFX) để khớp với kho storage/app/sfx_library/');
            $table->string('camera_motion', 64)
                ->default('zoom_in')
                ->comment('Kiểu chuyển động camera 60fps trong Eco Mode: zoom_in, zoom_out, pan_right, pan_left');
            $table->string('transition_type', 64)
                ->default('fade')
                ->comment('Hiệu ứng chuyển cảnh FFmpeg xfade: fade, smoothleft, smoothright, circleopen, dissolve');
            $table->unsignedInteger('transition_duration_ms')
                ->default(500)
                ->comment('Thời lượng chuyển cảnh xfade tính bằng mili-giây (mặc định 500ms)');
            $table->unsignedInteger('duration_ms')
                ->default(0)
                ->comment('Thời lượng thực tế của phân cảnh đo bằng ffprobe từ file giọng đọc (mili-giây)');
            $table->string('voice_file_path', 512)->nullable()->comment('Đường dẫn file âm thanh giọng đọc .mp3 do Edge-TTS xuất ra');
            $table->string('subtitle_file_path', 512)->nullable()->comment('Đường dẫn file phụ đề .vtt/.srt chứa word-level timestamps');
            $table->string('image_file_path', 512)->nullable()->comment('Đường dẫn file ảnh keyframe .png/.jpg tạo từ fal-ai/flux/schnell');
            $table->string('video_file_path', 512)->nullable()->comment('Đường dẫn file video clip .mp4 của cảnh (từ LTX-Video hoặc FFmpeg zoompan 60fps)');
            $table->string('sfx_file_path', 512)->nullable()->comment('Đường dẫn file âm thanh SFX cục bộ được gán cho phân cảnh');
            $table->timestamps();

            $table->unique(['video_project_id', 'scene_order'], 'uq_project_scene_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storyboard_scenes');
    }
};
