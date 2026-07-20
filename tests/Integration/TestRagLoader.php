<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use NeuronAI\RAG\DataLoader\DataLoaderInterface;
use NeuronAI\RAG\Document;

/** Represents an application data loader registered in a named ingestion pipeline. */
final class TestRagLoader implements DataLoaderInterface
{
    public function getDocuments(): array
    {
        $first = new Document('Symfony service containers');
        $first->sourceType = 'test';
        $first->sourceName = 'guide';
        $second = new Document('Neuron retrieval augmented generation');
        $second->sourceType = 'test';
        $second->sourceName = 'guide';

        return [$first, $second];
    }
}
