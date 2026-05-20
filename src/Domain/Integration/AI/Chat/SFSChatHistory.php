<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI\Chat;

use App\Domain\Integration\AI\Chat\AddChatMessage\AddChatMessage;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\InMemoryChatHistory as BaseInMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;

/**
 * @codeCoverageIgnore
 */
final class SFSChatHistory extends BaseInMemoryChatHistory
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function onNewMessage(Message $message): void
    {
        parent::onNewMessage($message);

        $content = $message->getContent();
        if (null === $content) {
            return;
        }

        $this->commandBus->dispatch(new AddChatMessage(
            message: $content,
            messageRole: MessageRole::from($message->getRole()),
        ));
    }
}
