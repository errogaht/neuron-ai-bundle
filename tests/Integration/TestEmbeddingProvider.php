<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/** Deterministic offline embedding provider used to prove Symfony RAG wiring without network calls. */
final class TestEmbeddingProvider implements EmbeddingsProviderInterface
{
    public function embedText(string $text): array
    {
        return [(float) mb_strlen($text), 1.0];
    }

    public function embedDocument(Document $document): Document
    {
        $document->embedding = $this->embedText($document->content);

        return $document;
    }

    public function embedDocuments(array $documents): array
    {
        return array_map($this->embedDocument(...), $documents);
    }
}
