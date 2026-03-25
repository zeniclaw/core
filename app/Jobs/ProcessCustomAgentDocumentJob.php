<?php

namespace App\Jobs;

use App\Models\CustomAgentDocument;
use App\Services\KnowledgeChunker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessCustomAgentDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        private int $documentId,
    ) {}

    public function handle(): void
    {
        $document = CustomAgentDocument::find($this->documentId);
        if (!$document) return;

        $chunker = new KnowledgeChunker();
        $chunker->processDocument($document);
    }
}
