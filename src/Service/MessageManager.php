<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Enum\MessageFolder;
use App\Repository\PostLayoutRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Sending, filing and deleting private and system messages.
 */
final class MessageManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostLayoutRepository $layouts,
        private readonly LayoutTokenExpander $tokens,
        private readonly RateLimiterFactoryInterface $privateMessageLimiter,
    ) {
    }

    public function send(User $sender, User $recipient, string $title, string $body, ?string $ip = null): PrivateMessage
    {
        $message = new PrivateMessage($sender, $recipient, $title, $body);
        $message->setIp($ip);
        $message->setRecipientFolder(MessageFolder::Inbox->value);
        $message->setSenderFolder(MessageFolder::Sent->value);

        // Freeze the sender's layout the same way a post does, so a signature edit
        // does not retroactively rewrite messages already delivered.
        $message->setHeaderLayout($this->layouts->findOrCreate($sender->getPostHeader()));
        $message->setSignatureLayout($this->layouts->findOrCreate($sender->getSignature()));
        $message->setTagValues($this->tokens->computeValues($sender));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * Sends a system message. These block the recipient from posting until read,
     * so they are marked explicitly rather than inferred from the sender's id.
     */
    public function sendSystem(?User $sender, User $recipient, string $title, string $body): PrivateMessage
    {
        $message = new PrivateMessage($sender, $recipient, $title, $body);
        $message->setSystem(true);
        $message->setRecipientFolder(MessageFolder::Inbox->value);
        $message->setSenderFolder(MessageFolder::Sent->value);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function consumeRateLimit(User $sender): bool
    {
        return $this->privateMessageLimiter->create('pm-'.$sender->getId())->consume()->isAccepted();
    }

    /**
     * Files messages into a folder. Only touches the side of the row the acting user
     * owns, so moving a message out of your inbox never disturbs the sender's copy.
     *
     * @param list<PrivateMessage> $messages
     */
    public function moveToFolder(User $actor, array $messages, int $folder, bool $sentSide = false): int
    {
        $moved = 0;

        foreach ($messages as $message) {
            if ($sentSide) {
                if ($message->getSender() !== $actor) {
                    continue;
                }
                $message->setSenderFolder($folder);
            } else {
                if ($message->getRecipient() !== $actor) {
                    continue;
                }
                $message->setRecipientFolder($folder);
            }
            ++$moved;
        }

        $this->em->flush();

        return $moved;
    }

    /**
     * Deletion is a move to the reserved "deleted" folder, so the other party keeps
     * their copy - the same semantics the original had.
     *
     * @param list<PrivateMessage> $messages
     */
    public function delete(User $actor, array $messages, bool $sentSide = false): int
    {
        return $this->moveToFolder($actor, $messages, MessageFolder::Deleted->value, $sentSide);
    }

    public function markRead(PrivateMessage $message, User $reader): void
    {
        if ($message->getRecipient() === $reader && $message->isUnread()) {
            $message->markRead();
            $this->em->flush();
        }
    }
}
