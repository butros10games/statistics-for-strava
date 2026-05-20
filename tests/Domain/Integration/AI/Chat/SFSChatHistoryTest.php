<?php

namespace App\Tests\Domain\Integration\AI\Chat;

use App\Domain\Integration\AI\Chat\AddChatMessage\AddChatMessage;
use App\Domain\Integration\AI\Chat\SFSChatHistory;
use App\Tests\Infrastructure\CQRS\Command\Bus\SpyCommandBus;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SFSChatHistoryTest extends TestCase
{
    public function testItDispatchesNewMessagesWithTextContent(): void
    {
        $commandBus = new SpyCommandBus();
        $history = new SFSChatHistory($commandBus);

        $history->addMessage(new UserMessage('How did my ride go?'));

        $commands = $commandBus->getDispatchedCommands();
        $this->assertCount(1, $commands);
        $this->assertInstanceOf(AddChatMessage::class, $commands[0]);
        $this->assertSame('How did my ride go?', $commands[0]->getMessage());
        $this->assertSame(MessageRole::USER, $commands[0]->getMessageRole());
    }

    #[DataProvider('provideMessagesWithoutTextContent')]
    public function testItIgnoresMessagesWithoutTextContent(Message $message): void
    {
        $commandBus = new SpyCommandBus();
        $history = new SFSChatHistory($commandBus);

        $history->addMessage($message);

        $this->assertSame([], $commandBus->getDispatchedCommands());
    }

    public static function provideMessagesWithoutTextContent(): iterable
    {
        yield 'empty content' => [new UserMessage('')];
        yield 'zero string content is normalized away' => [new UserMessage('0')];
    }
}
