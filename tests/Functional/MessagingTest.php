<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PrivateMessage;
use App\Enum\MessageFolder;
use App\Repository\PrivateMessageRepository;
use App\Service\MessageManager;
use App\Tests\Support\BoardWebTestCase;

/**
 * Private messages.
 *
 * One row carries both sides of a conversation - the sender's copy and the
 * recipient's - with a folder column for each. private.php mixed those columns up
 * often enough that deleting a message from your inbox could remove it from the
 * other person's sent folder as well. Which side an action touches is therefore the
 * thing most worth asserting here.
 */
final class MessagingTest extends BoardWebTestCase
{
    public function testAMemberCanSendAMessageThroughTheForm(): void
    {
        $this->signInAs('Member');

        $this->client->request('GET', '/messages/send/'.$this->id('user', 'Other'));
        $this->client->submitForm('Send', [
            'send_message[title]' => 'A note',
            'send_message[body]' => 'Hello there.',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->signInAs('Other');
        $this->client->request('GET', '/messages');
        self::assertStringContainsString('A note', $this->client->getResponse()->getContent());
    }

    public function testASentMessageLandsInTheInboxUnread(): void
    {
        $message = $this->manager()->send($this->user('Member'), $this->user('Other'), 'Subject', 'Body');

        self::assertTrue($message->isUnread());
        self::assertSame(MessageFolder::Inbox->value, $message->getRecipientFolder());
        self::assertSame(MessageFolder::Sent->value, $message->getSenderFolder());
    }

    public function testUnreadCountsReflectWhatHasBeenRead(): void
    {
        $messages = $this->container()->get(PrivateMessageRepository::class);
        $recipient = $this->user('Other');

        $before = $messages->countUnread($recipient);

        $message = $this->manager()->send($this->user('Member'), $recipient, 'Subject', 'Body');
        self::assertSame($before + 1, $messages->countUnread($recipient));

        $this->manager()->markRead($message, $recipient);
        self::assertSame($before, $messages->countUnread($recipient));
    }

    /** Opening somebody else's copy must not mark it read for them. */
    public function testOnlyTheRecipientCanMarkAMessageRead(): void
    {
        $sender = $this->user('Member');
        $message = $this->manager()->send($sender, $this->user('Other'), 'Subject', 'Body');

        $this->manager()->markRead($message, $sender);

        self::assertTrue($message->isUnread(), 'The sender marked the recipient\'s copy as read.');
    }

    public function testAMemberCannotReadSomebodyElsesMessages(): void
    {
        $this->signInAs('Other');
        $this->client->request('GET', '/messages/'.$this->id('message', 'unread'));

        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [403, 404],
            'A member read a message addressed to somebody else.',
        );
    }

    // ------------------------------------------------------------------
    // Folders
    // ------------------------------------------------------------------

    /**
     * The shared-row problem: filing a message away in your inbox must leave the
     * other party's copy exactly where it was.
     */
    public function testFilingAMessageOnlyTouchesTheActorsOwnSide(): void
    {
        $sender = $this->user('Member');
        $recipient = $this->user('Other');
        $message = $this->manager()->send($sender, $recipient, 'Subject', 'Body');

        $moved = $this->manager()->moveToFolder($recipient, [$message], MessageFolder::FIRST_USER_FOLDER);

        self::assertSame(1, $moved);
        self::assertSame(MessageFolder::FIRST_USER_FOLDER, $message->getRecipientFolder());
        self::assertSame(MessageFolder::Sent->value, $message->getSenderFolder(), 'The sender\'s copy moved too.');
    }

    public function testTheSenderCanFileTheirOwnCopyIndependently(): void
    {
        $sender = $this->user('Member');
        $recipient = $this->user('Other');
        $message = $this->manager()->send($sender, $recipient, 'Subject', 'Body');

        $this->manager()->moveToFolder($sender, [$message], MessageFolder::FIRST_USER_FOLDER, sentSide: true);

        self::assertSame(MessageFolder::FIRST_USER_FOLDER, $message->getSenderFolder());
        self::assertSame(MessageFolder::Inbox->value, $message->getRecipientFolder());
    }

    /** A member must not be able to file a message that is not theirs. */
    public function testFilingSomebodyElsesMessageDoesNothing(): void
    {
        $message = $this->manager()->send($this->user('Member'), $this->user('Other'), 'Subject', 'Body');

        $moved = $this->manager()->moveToFolder($this->user('Mod'), [$message], MessageFolder::FIRST_USER_FOLDER);

        self::assertSame(0, $moved, 'An unrelated member filed somebody else\'s message.');
        self::assertSame(MessageFolder::Inbox->value, $message->getRecipientFolder());
    }

    public function testDeletingOnlyRemovesTheActorsSide(): void
    {
        $sender = $this->user('Member');
        $recipient = $this->user('Other');
        $message = $this->manager()->send($sender, $recipient, 'Subject', 'Body');
        $id = $message->getId();

        $this->manager()->delete($recipient, [$message]);

        $stored = $this->em()->find(PrivateMessage::class, $id);
        self::assertNotNull($stored, 'The row went away while the sender still had a copy.');
        self::assertSame(MessageFolder::Deleted->value, $stored->getRecipientFolder());
        self::assertSame(MessageFolder::Sent->value, $stored->getSenderFolder());
    }

    // ------------------------------------------------------------------
    // System messages
    // ------------------------------------------------------------------

    public function testSystemMessagesAreCountedSeparately(): void
    {
        $messages = $this->container()->get(PrivateMessageRepository::class);
        $recipient = $this->user('Other');

        $ordinaryBefore = $messages->countUnread($recipient);
        $systemBefore = $messages->countUnread($recipient, system: true);

        $this->manager()->sendSystem($this->user('Mod'), $recipient, 'Warning', 'Behave.');

        self::assertSame($ordinaryBefore, $messages->countUnread($recipient), 'A system message inflated the ordinary count.');
        self::assertSame($systemBefore + 1, $messages->countUnread($recipient, system: true));
    }

    /**
     * The flag is explicit rather than inferred from a null sender, because a
     * moderator sends system messages under their own name.
     */
    public function testASystemMessageFromANamedModeratorIsStillASystemMessage(): void
    {
        $message = $this->manager()->sendSystem($this->user('Mod'), $this->user('Other'), 'Warning', 'Behave.');

        self::assertTrue($message->isSystem());
        self::assertSame($this->user('Mod'), $message->getSender());
    }

    private function manager(): MessageManager
    {
        return $this->container()->get(MessageManager::class);
    }
}
