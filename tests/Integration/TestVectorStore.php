<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use NeuronAI\RAG\VectorStore\MemoryVectorStore;

/** In-memory canonical store lets indexing and query-clone behavior be asserted in the test container. */
final class TestVectorStore extends MemoryVectorStore
{
}
