<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Rag;

use NeuronAI\RAG\DataLoader\DataLoaderInterface;
use NeuronAI\RAG\Document;
use Psr\Container\ContainerInterface;

/** Runs named Symfony data-loader pipelines through embeddings into the canonical vector store. */
final class RagIndexer
{
    /** @param array<string, array<string, mixed>> $pipelines */
    public function __construct(
        private readonly array $pipelines,
        private readonly EmbeddingProviderRegistry $embeddings,
        private readonly VectorStoreRegistry $vectorStores,
        private readonly ContainerInterface $loaders,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->pipelines);
    }

    public function index(string $pipeline, bool $reindex = false): RagIndexResult
    {
        $config = $this->pipelines[$pipeline] ?? throw new \InvalidArgumentException(\sprintf('Unknown RAG pipeline "%s".', $pipeline));
        $documents = [];
        foreach ((array) $config['loaders'] as $serviceId) {
            $loader = $this->loaders->get((string) $serviceId);
            if (!$loader instanceof DataLoaderInterface) {
                throw new \LogicException(\sprintf('RAG loader service "%s" must implement %s.', $serviceId, DataLoaderInterface::class));
            }
            foreach ($loader->getDocuments() as $document) {
                if (!$document instanceof Document) {
                    throw new \LogicException(\sprintf('RAG loader service "%s" returned a non-Document value.', $serviceId));
                }
                $documents[] = $document;
            }
        }

        $store = $this->vectorStores->get((string) $config['vector_store']);
        $sources = [];
        foreach ($documents as $document) {
            $sourceKey = $document->sourceType."\0".$document->sourceName;
            $sources[$sourceKey] = [$document->sourceType, $document->sourceName];
        }
        if ($reindex) {
            // Delete every source once before adding any chunks, preventing duplicate embeddings on repeatable imports.
            foreach ($sources as [$sourceType, $sourceName]) {
                $store->deleteBy($sourceType, $sourceName);
            }
        }

        $embeddings = $this->embeddings->get((string) $config['embeddings']);
        $chunkSize = max(1, (int) $config['chunk_size']);
        foreach (array_chunk($documents, $chunkSize) as $chunk) {
            $store->addDocuments($embeddings->embedDocuments($chunk));
        }

        return new RagIndexResult($pipeline, \count($documents), \count($sources), $reindex);
    }
}
