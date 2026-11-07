<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration khởi tạo bảng `product_profiles` lưu trữ hồ sơ sản phẩm thương mại,
 * kích thước thực tế (mm), kết quả phân tích Gemini Vision và đường dẫn file 3D `.glb` đã chuẩn hóa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_project_id')
                ->constrained('video_projects')
                ->cascadeOnDelete()
                ->comment('Khóa ngoại liên kết tới dự án video');
            $table->string('product_name', 255)->comment('Tên thương mại của sản phẩm');
            $table->string('sku_code', 100)->nullable()->index()->comment('Mã SKU sản phẩm phục vụ tra cứu và tái sử dụng cache 3D');
            $table->text('product_description')->nullable()->comment('Mô tả tính năng, công dụng nổi bật của sản phẩm');
            $table->decimal('width_mm', 10, 2)->comment('Chiều rộng thực tế của sản phẩm (milimét - trục X)');
            $table->decimal('height_mm', 10, 2)->comment('Chiều cao thực tế của sản phẩm (milimét - trục Y)');
            $table->decimal('depth_mm', 10, 2)->comment('Chiều sâu/dày thực tế của sản phẩm (milimét - trục Z)');
            $table->json('original_image_paths')->comment('Danh sách đường dẫn các file ảnh sản phẩm gốc do người dùng tải lên');
            $table->string('nobg_image_path', 512)->nullable()->comment('Đường dẫn ảnh sản phẩm đã tách nền PNG bằng Python rembg trên CPU');
            $table->json('customer_analysis_json')->nullable()->comment('Kết quả phân tích khách hàng mục tiêu, nỗi đau, USP từ Gemini 2.5 Flash Vision');
            $table->string('raw_glb_path', 512)->nullable()->comment('Đường dẫn file .glb nguyên bản tải về từ fal-ai/trellis hoặc tripo3d');
            $table->string('normalized_glb_path', 512)->nullable()->comment('Đường dẫn file .glb đã được Python trimesh chuẩn hóa tỷ lệ thực tế (1 unit = 1m)');
            $table->string('cache_key', 128)->unique()->comment('Mã băm SHA-256 lưu cache vĩnh viễn mô hình 3D theo mã sản phẩm & kích thước');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_profiles');
    }
};
