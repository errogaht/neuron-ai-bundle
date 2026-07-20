<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Tool;

use Errogaht\NeuronAiBundle\Tool\AbstractToolGroup;
use Errogaht\NeuronAiBundle\Tool\Attribute\Tool;
use Errogaht\NeuronAiBundle\Tool\Attribute\ToolParameter;
use PHPUnit\Framework\TestCase;

/** Covers multi-method service discovery, schema inference and invocation through native Neuron tools. */
final class AbstractToolGroupTest extends TestCase
{
    public function testAttributedMethodsBecomeExecutableTools(): void
    {
        // Scenario: one domain service exposes multiple related capabilities to a configured agent.
        $tools = (new ExampleOrderTools())->tools();

        self::assertCount(2, $tools);
        self::assertSame('find_order', $tools[0]->getName());
        self::assertSame(['short', 'full'], $tools[0]->getProperties()[1]->getEnum());
        self::assertFalse($tools[0]->getProperties()[1]->isRequired());

        $tools[0]->setInputs(['number' => 'A-42']);
        $tools[0]->execute();
        self::assertSame('A-42:short', $tools[0]->getResult());

        $tools[1]->setInputs(['number' => 'A-42', 'status' => 'paid']);
        $tools[1]->execute();
        self::assertSame('A-42:paid', $tools[1]->getResult());
    }
}

enum ExampleOrderStatus: string
{
    case PAID = 'paid';
    case SHIPPED = 'shipped';
}

/** Test fixture resembling an autowired application service with related order operations. */
final class ExampleOrderTools extends AbstractToolGroup
{
    #[Tool(description: 'Find an order visible to the current user.')]
    public function findOrder(
        #[ToolParameter(description: 'Public order number')] string $number,
        #[ToolParameter(description: 'Response detail', enum: ['short', 'full'])] string $format = 'short',
    ): string {
        return $number.':'.$format;
    }

    #[Tool(name: 'change_order_status', description: 'Change an order status.', maxRuns: 1)]
    public function changeStatus(string $number, ExampleOrderStatus $status): string
    {
        return $number.':'.$status->value;
    }
}
