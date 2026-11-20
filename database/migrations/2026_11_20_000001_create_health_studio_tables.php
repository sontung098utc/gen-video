<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Khởi tạo các bảng dữ liệu cho hệ thống 8-Agent "Sống Khỏe Mỗi Ngày — AI Health Video Studio".
     */
    public function up(): void
    {
        // 1. Bảng cấu hình hệ thống & API Keys (Google TTS, Vbee, GenMax, Pexels, Pixabay, Gemini...)
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 120)->unique();
                $table->text('value')->nullable();
                $table->string('group', 60)->default('general')->index();
                $table->string('label', 180)->nullable();
                $table->boolean('is_secret')->default(false);
                $table->timestamps();
            });
        }

        // 2. Bảng Kho Tài Liệu Kiến Thức Sức Khỏe Chính Thống (Đông y, Tây y, Khoa học, Y tế)
        if (! Schema::hasTable('health_knowledge_documents')) {
            Schema::create('health_knowledge_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 255);
                $table->string('category', 50)->default('y_te')->index(); // dong_y, tay_y, dinh_duong, khoa_hoc, y_te
                $table->string('source_name', 255)->nullable();
                $table->string('source_url', 500)->nullable();
                $table->string('file_path', 500)->nullable();
                $table->longText('extracted_text');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        // 3. Bảng Kế Hoạch Nội Dung Kênh (Agent AI 1: Tháng -> Tuần -> Ngày)
        if (! Schema::hasTable('health_content_plans')) {
            Schema::create('health_content_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 255);
                $table->string('channel_name', 120)->default('Sống Khỏe Mỗi Ngày');
                $table->unsignedTinyInteger('month');
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('posts_per_day')->default(1);
                $table->unsignedTinyInteger('total_days')->default(30);
                $table->json('topics_json')->nullable();
                $table->text('plan_summary')->nullable();
                $table->string('status', 40)->default('draft')->index(); // draft, planned, titled, active, completed
                $table->timestamps();
            });
        }

        // 4. Bảng Chi Tiết Từng Bài Viết/Video Trong Kế Hoạch (Agent AI 1 & Agent AI 2)
        if (! Schema::hasTable('health_plan_items')) {
            Schema::create('health_plan_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('plan_id')
                    ->constrained('health_content_plans')
                    ->cascadeOnDelete();
                $table->unsignedTinyInteger('week_number')->default(1)->index();
                $table->unsignedTinyInteger('day_number')->default(1)->index();
                $table->date('scheduled_date')->nullable()->index();
                $table->unsignedTinyInteger('slot_index')->default(1);
                $table->string('topic', 255);
                $table->string('angle', 80)->default('ket_hop'); // dong_y, tay_y, khoa_hoc, ket_hop
                $table->text('brief')->nullable();
                $table->string('seo_title_tiktok', 255)->nullable();
                $table->string('seo_title_reels', 255)->nullable();
                $table->string('seo_title_shorts', 255)->nullable();
                $table->string('cover_headline', 160)->nullable();
                $table->text('seo_description')->nullable();
                $table->json('hashtags_json')->nullable();
                $table->foreignId('video_project_id')
                    ->nullable()
                    ->constrained('video_projects')
                    ->nullOnDelete();
                $table->string('status', 40)->default('planned')->index(); // planned, titled, producing, approved, published
                $table->timestamps();
            });
        }

        // 5. Bảng Kho Video Stock Nội Bộ Người Châu Á (Agent AI 4 ưu tiên)
        if (! Schema::hasTable('stock_media_assets')) {
            Schema::create('stock_media_assets', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 255);
                $table->string('tags', 500)->default('');
                $table->string('category', 60)->default('lifestyle')->index(); // dong_y, tay_y, dinh_duong, tap_luyen, lifestyle
                $table->string('orientation', 20)->default('9:16');
                $table->string('file_path', 500);
                $table->unsignedInteger('duration_ms')->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        // 6. Bảng Lịch Sử Kiểm Duyệt Của Trưởng Phòng Agent AI 8
        if (! Schema::hasTable('agent_qa_reviews')) {
            Schema::create('agent_qa_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('video_project_id')
                    ->constrained('video_projects')
                    ->cascadeOnDelete();
                $table->unsignedTinyInteger('attempt_number')->default(1);
                $table->string('status', 30)->default('pending')->index(); // approved, rejected
                $table->unsignedTinyInteger('overall_score')->default(0);
                $table->json('checklist_json')->nullable();
                $table->json('failed_agents_json')->nullable();
                $table->text('feedback_notes')->nullable();
                $table->timestamps();
            });
        }

        // 7. Mở rộng bảng video_projects cho cấu hình Đa Giọng Đọc, Phụ Đề, Ảnh Bìa & QA
        Schema::table('video_projects', function (Blueprint $table): void {
            if (! Schema::hasColumn('video_projects', 'voice_provider')) {
                $table->string('voice_provider', 40)->default('edge_tts')->after('cost_tier_mode');
            }
            if (! Schema::hasColumn('video_projects', 'voice_speed')) {
                $table->decimal('voice_speed', 3, 2)->default(1.00)->after('voice_id');
            }
            if (! Schema::hasColumn('video_projects', 'subtitle_enabled')) {
                $table->boolean('subtitle_enabled')->default(true)->after('voice_speed');
            }
            if (! Schema::hasColumn('video_projects', 'subtitle_style')) {
                $table->string('subtitle_style', 40)->default('capcut_yellow')->after('subtitle_enabled');
            }
            if (! Schema::hasColumn('video_projects', 'thumbnail_path')) {
                $table->string('thumbnail_path', 500)->nullable()->after('final_video_path');
            }
            if (! Schema::hasColumn('video_projects', 'qa_status')) {
                $table->string('qa_status', 30)->default('pending')->after('thumbnail_path');
            }
            if (! Schema::hasColumn('video_projects', 'qa_attempts')) {
                $table->unsignedTinyInteger('qa_attempts')->default(0)->after('qa_status');
            }
            if (! Schema::hasColumn('video_projects', 'health_plan_item_id')) {
                $table->unsignedBigInteger('health_plan_item_id')->nullable()->after('qa_attempts');
            }
            if (! Schema::hasColumn('video_projects', 'seo_meta_json')) {
                $table->json('seo_meta_json')->nullable()->after('health_plan_item_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', function (Blueprint $table): void {
            $cols = [
                'voice_provider',
                'voice_speed',
                'subtitle_enabled',
                'subtitle_style',
                'thumbnail_path',
                'qa_status',
                'qa_attempts',
                'health_plan_item_id',
                'seo_meta_json',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('video_projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('agent_qa_reviews');
        Schema::dropIfExists('stock_media_assets');
        Schema::dropIfExists('health_plan_items');
        Schema::dropIfExists('health_content_plans');
        Schema::dropIfExists('health_knowledge_documents');
        Schema::dropIfExists('app_settings');
    }
};
