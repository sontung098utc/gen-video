<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model lưu trữ Hồ sơ Sản phẩm, kích thước thực tế (mm) và mô hình 3D `.glb` (`product_profiles`).
 *
 * @property int $id
 * @property int $video_project_id
 * @property string $product_name
 * @property string|null $sku_code
 * @property string|null $product_description
 * @property float $width_mm
 * @property float $height_mm
 * @property float $depth_mm
 * @property array<int, string> $original_image_paths
 * @property string|null $nobg_image_path
 * @property array<string, mixed>|null $customer_analysis_json
 * @property string|null $raw_glb_path
 * @property string|null $normalized_glb_path
 * @property string $cache_key
 */
class ProductProfile extends Model
{
    use HasFactory;

    protected $table = 'product_profiles';

    protected $fillable = [
        'video_project_id',
        'product_name',
        'sku_code',
        'product_description',
        'width_mm',
        'height_mm',
        'depth_mm',
        'original_image_paths',
        'nobg_image_path',
        'customer_analysis_json',
        'raw_glb_path',
        'normalized_glb_path',
        'cache_key',
    ];

    /**
     * Cấu hình ép kiểu thuộc tính theo chuẩn Laravel 11.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width_mm' => 'float',
            'height_mm' => 'float',
            'depth_mm' => 'float',
            'original_image_paths' => 'array',
            'customer_analysis_json' => 'array',
        ];
    }

    /**
     * Quan hệ N-1: Dự án video sở hữu hồ sơ sản phẩm này.
     */
    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    /**
     * Trả về kích thước thực tế quy đổi sang đơn vị mét (1 unit = 1m cho chuẩn glTF / <model-viewer>).
     *
     * @return array{width_m: float, height_m: float, depth_m: float}
     */
    public function dimensionsInMeters(): array
    {
        return [
            'width_m' => round($this->width_mm / 1000.0, 4),
            'height_m' => round($this->height_mm / 1000.0, 4),
            'depth_m' => round($this->depth_mm / 1000.0, 4),
        ];
    }

    /**
     * Tạo mã băm `cache_key` duy nhất từ mã sản phẩm, ảnh gốc và kích thước thực tế (mm)
     * để tái sử dụng vĩnh viễn file `.glb` đã chuẩn hóa mà không tốn lại phí API Fal.ai.
     */
    public static function generateCacheKey(
        string $skuOrName,
        string $primaryImageHash,
        float $widthMm,
        float $heightMm,
        float $depthMm
    ): string {
        $payload = sprintf(
            '%s|%s|%.2fx%.2fx%.2f',
             mb_strtolower(trim($skuOrName)),
            $primaryImageHash,
            $widthMm,
            $heightMm,
            $depthMm
        );

        return hash('sha256', $payload);
    }
}
