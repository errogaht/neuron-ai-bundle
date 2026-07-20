<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Rag;

/** Serializable summary of one deterministic RAG ingestion run. */
final class RagIndexResult implements \JsonSerializable
{
    public function __construct(
        public readonly string $pipeline,
        public readonly int $documents,
        public readonly int $sources,
        public readonly bool $reindexed,
    ) {
    }

    /** @return array{pipeline: string, documents: int, sources: int, reindexed: bool} */
    public function jsonSerialize(): array
    {
        return [
            'pipeline' => $this->pipeline,
            'documents' => $this->documents,
            'sources' => $this->sources,
            'reindexed' => $this->reindexed,
        ];
    }
}
