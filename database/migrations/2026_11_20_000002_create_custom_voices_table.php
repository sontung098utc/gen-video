<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạo bảng lưu danh sách Giọng Đọc Tùy Chỉnh theo Voice ID của từng nhà cung cấp (Agent AI 5).
     */
    public function up(): void
    {
        if (! Schema::hasTable('custom_voices')) {
            Schema::create('custom_voices', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                $table->string('voice_id', 160);
                $table->string('provider', 40)->index(); // genmax, vbee, google_tts, edge_tts
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_voices');
    }
};
