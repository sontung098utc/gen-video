<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CostTierMode;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\WorkflowMode;
use App\Models\GenerationLog;
use App\Models\ProductProfile;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use Illuminate\Database\Seeder;

/**
 * Seeder khởi tạo dữ liệu mẫu trực quan cho 4 Cổng kiểm duyệt (CP1 -> CP4) và màn hình Hoàn thành (COMPLETED)
 * để người dùng trải nghiệm ngay toàn bộ tính năng trên Giao diện Web Studio.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (VideoProject::query()->count() > 0) {
            return;
        }

        // ----------------------------------------------------------------------
        // DỰ ÁN 1: ĐANG CHỜ DUYỆT CỔNG 1 (CP1 - Phê duyệt Sản Phẩm & Mẫu 3D .glb)
        // ----------------------------------------------------------------------
        $projectCp1 = VideoProject::query()->create([
            'title' => 'Chiến Dịch Quảng Cáo Nước Hoa Nam Bleu Noir 100ml (3D Showcase)',
            'project_type' => ProjectType::PRODUCT,
            'topic_or_brief' => 'Quảng cáo nước hoa nam cao cấp hương gỗ tuyết tùng và hổ phách, thiết kế chai thủy tinh xanh đen sang trọng.',
            'aspect_ratio' => '9:16',
            'status' => ProjectStatus::WAITING_APPROVAL_PRODUCT,
            'workflow_mode' => WorkflowMode::STEP_BY_STEP,
            'cost_tier_mode' => CostTierMode::FULL_AI_VIDEO_MODE,
            'voice_id' => 'vi-VN-NamMinhNeural',
            'master_style_prompt' => 'Luxury commercial product photography, dramatic studio rim lighting, dark slate podium, 8k photorealistic',
            'total_duration_ms' => 0,
            'total_cost_usd' => 0.0200,
        ]);

        ProductProfile::query()->create([
            'video_project_id' => $projectCp1->id,
            'product_name' => 'Nước Hoa Nam Bleu Noir Eau De Parfum 100ml',
            'sku_code' => 'SKU-BLEU-NOIR-100',
            'product_description' => 'Hương gỗ trầm ấm lưu hương 12 giờ, vỏ chai thủy tinh nguyên khối vát cạnh tinh xảo.',
            'width_mm' => 68.0,
            'height_mm' => 125.0,
            'depth_mm' => 38.0,
            'original_image_paths' => ['products/nobg/demo_perfume.png'],
            'nobg_image_path' => 'products/nobg/demo_perfume.png',
            'raw_glb_path' => 'models_3d/cache/demo_perfume_normalized.glb',
            'normalized_glb_path' => 'models_3d/cache/demo_perfume_normalized.glb',
            'cache_key' => 'demo_sha256_bleu_noir_68x125x38mm_permanent_cache',
            'customer_analysis_json' => [
                'customer_analysis' => [
                    'target_audience' => 'Nam giới 22–40 tuổi tại các đô thị lớn, nhân viên văn phòng, doanh nhân yêu thích phong cách lịch lãm.',
                    'customer_persona' => 'Minh (28 tuổi, Trưởng nhóm Kinh doanh) — thường xuyên gặp gỡ đối tác, muốn tìm mùi hương nam tính lưu hương cả ngày.',
                    'pain_points' => [
                        'Nước hoa thông thường bay mùi nhanh sau 2-3 giờ trong thời tiết nóng ẩm',
                        'Khó hình dung kích thước thật của chai 100ml khi đặt mua online',
                    ],
                    'unique_selling_points' => [
                        'Tinh dầu gỗ Tuyết Tùng & Hổ Phách nhập khẩu Pháp lưu hương 12 giờ',
                        'Thiết kế chai thủy tinh 68×125×38mm cầm đầm tay, vừa vặn túi áo vest',
                        'Tia xịt sương nano tỏa đều không gây ố áo sơ mi',
                    ],
                    'real_world_scale_context' => 'Đặt trên bàn đá cẩm thạch cạnh đồng hồ đeo tay 42mm (tỷ lệ chuẩn 1 unit = 1m)',
                ],
            ],
        ]);

        GenerationLog::query()->create([
            'video_project_id' => $projectCp1->id,
            'provider' => 'fal_ai',
            'model_name' => 'fal-ai/trellis',
            'action_type' => 'generate_3d',
            'status' => 'success',
            'cost_usd' => 0.0200,
            'latency_ms' => 8420,
            'request_payload' => ['mesh_simplify' => 0.95],
            'response_payload' => ['normalized_extents_mm' => [68.0, 125.0, 38.0]],
        ]);

        // ----------------------------------------------------------------------
        // DỰ ÁN 2: ĐANG CHỜ DUYỆT CỔNG 2 (CP2 - Phê duyệt Kịch Bản & Phân Cảnh)
        // ----------------------------------------------------------------------
        $projectCp2 = VideoProject::query()->create([
            'title' => '5 Sự Thật Kỳ Diệu Về Não Bộ Khi Bạn Ngủ Sâu',
            'project_type' => ProjectType::KNOWLEDGE,
            'topic_or_brief' => 'Giải thích cơ chế tự làm sạch độc tố và củng cố trí nhớ của não bộ trong giấc ngủ sâu.',
            'aspect_ratio' => '9:16',
            'status' => ProjectStatus::WAITING_APPROVAL_SCRIPT,
            'workflow_mode' => WorkflowMode::STEP_BY_STEP,
            'cost_tier_mode' => CostTierMode::ECO_MODE,
            'voice_id' => 'vi-VN-NamMinhNeural',
            'master_style_prompt' => 'Cinematic 3D medical visualization, glowing neural pathways, deep blue and gold bioluminescence, 8k Unreal Engine 5',
            'total_duration_ms' => 0,
            'total_cost_usd' => 0.0000,
        ]);

        $scenesCp2 = [
            [
                'scene_order' => 1,
                'narration_text' => 'Bạn có biết ngay khi bạn chìm vào giấc ngủ sâu, não bộ không hề nghỉ ngơi mà bắt đầu một ca trực đêm đặc biệt?',
                'visual_prompt' => 'Macro 3D render of human brain glowing with golden and cyan neural synapses in a calm dark bedroom atmosphere',
                'sfx_prompt' => 'sparkle_chime',
                'camera_motion' => 'zoom_in',
                'transition_type' => 'fade',
            ],
            [
                'scene_order' => 2,
                'narration_text' => 'Hệ thống bạch huyết trong não sẽ mở rộng gấp đôi, cuốn trôi các mảng protein độc hại tích tụ suốt cả ngày dài.',
                'visual_prompt' => 'Bioluminescent cerebrospinal fluid flowing smoothly through brain cells washing away dark particles, 8k macro',
                'sfx_prompt' => 'whoosh_fast',
                'camera_motion' => 'pan_right',
                'transition_type' => 'smoothleft',
            ],
            [
                'scene_order' => 3,
                'narration_text' => 'Đồng thời, vùng hải mã sẽ tua lại toàn bộ ký ức quan trọng và chuyển chúng vào kho lưu trữ dài hạn ở vỏ não.',
                'visual_prompt' => 'Streams of golden light connecting hippocampus to cerebral cortex like fiber optic cables, cinematic lighting',
                'sfx_prompt' => 'typing',
                'camera_motion' => 'zoom_out',
                'transition_type' => 'circleopen',
            ],
            [
                'scene_order' => 4,
                'narration_text' => 'Vì vậy, ngủ đủ bảy đến tám tiếng mỗi đêm chính là khoản đầu tư miễn phí tốt nhất cho trí tuệ của bạn!',
                'visual_prompt' => 'Peaceful person waking up refreshed in warm morning sunlight, vibrant cinematic color grading, 8k',
                'sfx_prompt' => 'sparkle',
                'camera_motion' => 'zoom_in',
                'transition_type' => 'dissolve',
            ],
        ];

        foreach ($scenesCp2 as $sc) {
            StoryboardScene::query()->create(array_merge($sc, [
                'video_project_id' => $projectCp2->id,
                'transition_duration_ms' => 500,
                'duration_ms' => 5400,
            ]));
        }

        GenerationLog::query()->create([
            'video_project_id' => $projectCp2->id,
            'provider' => 'gemini',
            'model_name' => 'gemini-2.5-flash',
            'action_type' => 'analyze_topic',
            'status' => 'success',
            'cost_usd' => 0.0000,
            'latency_ms' => 2150,
            'request_payload' => ['topic' => $projectCp2->topic_or_brief],
            'response_payload' => ['scenes_count' => 4],
        ]);
    }
}
