<?php

namespace App\Services;

use App\Models\CustomAgent;
use App\Models\CustomAgentChunk;
use App\Models\CustomAgentDocument;
use Illuminate\Support\Facades\Log;

/**
 * KnowledgeChunker — splits documents into overlapping chunks and embeds them for RAG.
 */
class KnowledgeChunker
{
    private EmbeddingService $embedder;
    private int $chunkSize;
    private int $overlap;

    public function __construct(int $chunkSize = 500, int $overlap = 50)
    {
        $this->embedder = new EmbeddingService();
        $this->chunkSize = $chunkSize;
        $this->overlap = $overlap;
    }

    /**
     * Process a document: chunk text, generate embeddings, store in DB.
     */
    public function processDocument(CustomAgentDocument $document): bool
    {
        $document->update(['status' => 'processing']);

        try {
            $text = $document->raw_content;
            if (!$text || mb_strlen(trim($text)) < 10) {
                $document->update(['status' => 'failed', 'error_message' => 'Contenu trop court ou vide']);
                return false;
            }

            // Split into chunks
            $chunks = $this->splitIntoChunks($text);
            if (empty($chunks)) {
                $document->update(['status' => 'failed', 'error_message' => 'Impossible de découper le texte']);
                return false;
            }

            // Delete old chunks for this document (re-processing)
            CustomAgentChunk::where('document_id', $document->id)->delete();

            $stored = 0;
            foreach ($chunks as $index => $chunkText) {
                $embedding = $this->embedder->embed($chunkText);

                CustomAgentChunk::create([
                    'document_id' => $document->id,
                    'custom_agent_id' => $document->custom_agent_id,
                    'content' => $chunkText,
                    'embedding' => $embedding ? json_encode($embedding) : null,
                    'chunk_index' => $index,
                    'metadata' => ['char_count' => mb_strlen($chunkText)],
                ]);
                $stored++;
            }

            $document->update([
                'status' => 'ready',
                'chunk_count' => $stored,
                'error_message' => null,
            ]);

            Log::info("KnowledgeChunker: processed document #{$document->id} → {$stored} chunks");
            return true;

        } catch (\Throwable $e) {
            Log::error("KnowledgeChunker: failed for document #{$document->id}: " . $e->getMessage());
            $document->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
            return false;
        }
    }

    /**
     * Split text into overlapping chunks by sentences.
     *
     * @return string[]
     */
    public function splitIntoChunks(string $text): array
    {
        $text = $this->cleanText($text);

        // Split by sentences (French + English punctuation)
        $sentences = preg_split('/(?<=[.!?…])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($sentences)) {
            // Fallback: split by words
            return $this->splitByWords($text);
        }

        $chunks = [];
        $current = '';
        $overlapBuffer = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (!$sentence) continue;

            // If single sentence exceeds chunk size, split by words
            if (mb_strlen($sentence) > $this->chunkSize && !$current) {
                $wordChunks = $this->splitByWords($sentence);
                foreach ($wordChunks as $wc) {
                    $chunks[] = $wc;
                }
                continue;
            }

            $candidate = $current ? $current . ' ' . $sentence : $sentence;

            if (mb_strlen($candidate) > $this->chunkSize && $current) {
                $chunks[] = trim($current);
                // Keep overlap from end of current chunk
                $overlapBuffer = $this->getOverlapText($current);
                $current = $overlapBuffer ? $overlapBuffer . ' ' . $sentence : $sentence;
            } else {
                $current = $candidate;
            }
        }

        if ($current && mb_strlen(trim($current)) > 20) {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * Extract text from various file types.
     */
    public function extractText(string $filePath, string $mimeType): string
    {
        Log::info("KnowledgeChunker: extractText", ['path' => $filePath, 'mime' => $mimeType, 'exists' => file_exists($filePath), 'size' => file_exists($filePath) ? filesize($filePath) : 'N/A']);

        if (str_contains($mimeType, 'pdf')) {
            $text = $this->extractPdf($filePath);
            Log::info("KnowledgeChunker: PDF extracted", ['chars' => mb_strlen($text)]);
            return $text;
        }

        if (str_contains($mimeType, 'text/') || str_contains($mimeType, 'csv') || str_contains($mimeType, 'json') || str_contains($mimeType, 'xml')) {
            return file_get_contents($filePath) ?: '';
        }

        // Word documents
        if (str_contains($mimeType, 'wordprocessingml') || str_contains($mimeType, 'msword')) {
            return $this->extractWord($filePath);
        }

        return file_get_contents($filePath) ?: '';
    }

    /**
     * Fetch and extract text from a URL.
     */
    public function extractFromUrl(string $url): string
    {
        try {
            // Detect PDF URLs and download + parse them
            $isPdf = str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.pdf');

            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders(['User-Agent' => 'ZeniClaw/1.0'])
                ->get($url);

            if (!$response->successful()) {
                return '';
            }

            $contentType = $response->header('Content-Type') ?? '';
            if ($isPdf || str_contains($contentType, 'pdf')) {
                // Save to temp file and extract with PDF parser
                $tmpFile = tempnam(sys_get_temp_dir(), 'zc_pdf_');
                file_put_contents($tmpFile, $response->body());
                $text = $this->extractPdf($tmpFile);
                @unlink($tmpFile);
                return $text;
            }

            $html = $response->body();

            // Strip scripts, styles, nav
            $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
            $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
            $html = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $html);
            $html = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $html);
            $html = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $html);

            // Convert to text
            $text = strip_tags($html);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);

            return trim($text);
        } catch (\Throwable $e) {
            Log::warning("KnowledgeChunker: URL extraction failed for {$url}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Semantic search across all chunks of a custom agent.
     *
     * @return array{content: string, similarity: float, document_title: string}[]
     */
    public function search(CustomAgent $customAgent, string $query, int $limit = 5, float $threshold = 0.25): array
    {
        $queryVector = $this->embedder->embed($query);
        if (!$queryVector) {
            // Fallback to keyword search
            return $this->keywordSearch($customAgent, $query, $limit);
        }

        $chunks = CustomAgentChunk::where('custom_agent_id', $customAgent->id)
            ->whereNotNull('embedding')
            ->with('document:id,title')
            ->get();

        $results = [];
        foreach ($chunks as $chunk) {
            $raw = is_resource($chunk->embedding) ? stream_get_contents($chunk->embedding) : $chunk->embedding;
            $chunkVector = json_decode($raw, true);
            if (!$chunkVector) continue;

            $similarity = EmbeddingService::cosineSimilarity($queryVector, $chunkVector);
            if ($similarity >= $threshold) {
                $results[] = [
                    'content' => $chunk->content,
                    'similarity' => round($similarity, 4),
                    'document_title' => $chunk->document->title ?? 'Unknown',
                    'chunk_index' => $chunk->chunk_index,
                ];
            }
        }

        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Keyword fallback search (when embeddings unavailable).
     */
    private function keywordSearch(CustomAgent $customAgent, string $query, int $limit = 5): array
    {
        $keywords = preg_split('/\s+/', mb_strtolower($query));

        $chunks = CustomAgentChunk::where('custom_agent_id', $customAgent->id)
            ->with('document:id,title')
            ->get();

        $results = [];
        foreach ($chunks as $chunk) {
            $text = mb_strtolower($chunk->content);
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $score += 1.0 / count($keywords);
                }
            }
            if ($score > 0.2) {
                $results[] = [
                    'content' => $chunk->content,
                    'similarity' => $score,
                    'document_title' => $chunk->document->title ?? 'Unknown',
                    'chunk_index' => $chunk->chunk_index,
                ];
            }
        }

        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($results, 0, $limit);
    }

    private function cleanText(string $text): string
    {
        // Normalize whitespace
        $text = preg_replace('/\r\n/', "\n", $text);
        // Remove excessive blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        // Trim each line
        $lines = array_map('trim', explode("\n", $text));
        return implode("\n", array_filter($lines, fn($l) => $l !== ''));
    }

    private function splitByWords(string $text): array
    {
        $words = preg_split('/\s+/', $text);
        $chunks = [];
        $current = [];
        $currentLen = 0;

        foreach ($words as $word) {
            $wordLen = mb_strlen($word) + 1;
            if ($currentLen + $wordLen > $this->chunkSize && !empty($current)) {
                $chunks[] = implode(' ', $current);
                // Keep last N words as overlap
                $overlapWords = (int) ($this->overlap / 5);
                $current = array_slice($current, -$overlapWords);
                $currentLen = mb_strlen(implode(' ', $current));
            }
            $current[] = $word;
            $currentLen += $wordLen;
        }

        if (!empty($current)) {
            $chunks[] = implode(' ', $current);
        }

        return $chunks;
    }

    private function getOverlapText(string $text): string
    {
        if ($this->overlap <= 0) return '';
        $end = mb_substr($text, -$this->overlap);
        // Try to start at a word boundary
        $spacePos = mb_strpos($end, ' ');
        return $spacePos !== false ? mb_substr($end, $spacePos + 1) : $end;
    }

    private function extractPdf(string $filePath): string
    {
        Log::info("extractPdf: starting", ['file' => $filePath, 'exists' => file_exists($filePath)]);

        // Try smalot/pdfparser if available
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText() ?: '';
                Log::info("extractPdf: smalot result", ['chars' => mb_strlen($text)]);
                if (mb_strlen(trim($text)) >= 20) {
                    return $text;
                }
                Log::warning("extractPdf: smalot returned too little text, trying pdftotext");
            } catch (\Throwable $e) {
                Log::warning("extractPdf: smalot failed: " . $e->getMessage());
            }
        }

        // Fallback: pdftotext CLI
        $output = [];
        $exitCode = 0;
        exec('pdftotext ' . escapeshellarg($filePath) . ' - 2>&1', $output, $exitCode);
        Log::info("extractPdf: pdftotext result", ['exit' => $exitCode, 'lines' => count($output), 'chars' => mb_strlen(implode("\n", $output))]);
        if ($exitCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        Log::warning("extractPdf: all methods failed", ['file' => $filePath]);
        return '';
    }

    private function extractWord(string $filePath): string
    {
        if (class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
            try {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= $element->getText() . "\n";
                        }
                    }
                }
                return $text;
            } catch (\Throwable $e) {
                Log::warning("Word extraction failed: " . $e->getMessage());
            }
        }
        return '';
    }
}
