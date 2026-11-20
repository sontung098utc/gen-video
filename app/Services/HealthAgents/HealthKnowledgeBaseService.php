<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\HealthKnowledgeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Dịch vụ quản lý Kho Tài Liệu Kiến Thức Sức Khỏe Chính Thống (RAG-lite).
 * Hỗ trợ đọc nội dung từ file `.txt`, `.md`, `.docx`, `.pdf` và tìm đoạn trích phù hợp nhất cho Agent 1 & Agent 3.
 */
class HealthKnowledgeBaseService
{
    /**
     * Trích xuất văn bản từ file tài liệu người dùng tải lên.
     */
    public function extractTextFromUploadedFile(UploadedFile $file): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            return '';
        }

        if (in_array($ext, ['txt', 'md', 'csv'], true)) {
            return trim((string) file_get_contents($path));
        }

        if ($ext === 'docx') {
            return $this->extractDocxText($path);
        }

        if ($ext === 'pdf') {
            return $this->extractPdfText($path);
        }

        return '';
    }

    /**
     * Tìm các đoạn tài liệu sức khỏe chính thống liên quan nhất tới chủ đề cần làm nội dung.
     */
    public function retrieveRelevantContext(string $topicOrQuery, int $maxChars = 3500): string
    {
        $docs = HealthKnowledgeDocument::query()
            ->where('is_active', true)
            ->latest()
            ->limit(30)
            ->get();

        if ($docs->isEmpty()) {
            return 'Chưa có tài liệu riêng tải lên. Hãy sử dụng kiến thức y khoa chính thống từ Bộ Y tế, WHO, tài liệu Đông y & Tây y chuẩn xác.';
        }

        $keywords = $this->tokenize($topicOrQuery);
        $scored = [];

        foreach ($docs as $doc) {
            $haystack = mb_strtolower($doc->title . ' ' . $doc->source_name . ' ' . mb_substr($doc->extracted_text, 0, 2500));
            $score = 1;
            foreach ($keywords as $kw) {
                if (mb_strlen($kw) >= 2 && str_contains($haystack, $kw)) {
                    $score += 3;
                }
            }
            $scored[] = ['doc' => $doc, 'score' => $score];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $chunks = [];
        $usedChars = 0;

        foreach (array_slice($scored, 0, 4) as $item) {
            /** @var HealthKnowledgeDocument $doc */
            $doc = $item['doc'];
            $snippet = Str::limit(trim($doc->extracted_text), 900);
            $source = $doc->source_name ?: 'Tài liệu nội bộ kênh Sống Khỏe Mỗi Ngày';
            $block = "[{$doc->categoryLabel()} — {$doc->title} | Nguồn: {$source}]\n{$snippet}";

            if ($usedChars + mb_strlen($block) > $maxChars && $chunks !== []) {
                break;
            }

            $chunks[] = $block;
            $usedChars += mb_strlen($block);
        }

        return implode("\n\n", $chunks);
    }

    private function extractDocxText(string $filePath): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            return '';
        }

        $xml = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);
        $text = strip_tags($xml);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8')));
    }

    private function extractPdfText(string $filePath): string
    {
        // Thử dùng Python pypdf/fitz nếu máy chủ có cài sẵn
        $python = (string) config('services.python.binary', 'python');
        $script = 'import sys; path=sys.argv[1]; text=""'
            . "\ntry:\n import pypdf\n r=pypdf.PdfReader(path)\n text='\\n'.join((p.extract_text() or '') for p in r.pages[:30])"
            . "\nexcept Exception:\n pass\nprint(text)";

        $process = new Process([$python, '-c', $script, $filePath]);
        $process->setTimeout(25);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $clean = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text));
        $parts = preg_split('/\s+/u', trim($clean)) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $w): bool => mb_strlen($w) >= 2)));
    }
}
