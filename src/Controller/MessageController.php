<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PrivateMessage;
use App\Entity\PrivateMessageFolder;
use App\Entity\User;
use App\Enum\MessageFolder;
use App\Form\MessageFolderType;
use App\Form\SendMessageType;
use App\Repository\PrivateMessageFolderRepository;
use App\Repository\PrivateMessageRepository;
use App\Security\Voter\ProfileVoter;
use App\Service\MarkupRenderer;
use App\Service\MessageManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Private messaging and system messages.
 */
#[Route('/messages')]
final class MessageController extends AbstractBoardController
{
    #[Route('', name: 'app_messages', methods: ['GET'])]
    public function inbox(
        Request $request,
        PrivateMessageRepository $messages,
        PrivateMessageFolderRepository $folders,
    ): Response {
        $user = $this->requireBoardUser();
        $folder = $this->resolveFolder($request, $user, $folders);

        $page = $this->pageFrom($request);
        $paginator = $messages->paginateFolder($user, $folder['number'], $page, 50);

        return $this->render('message/inbox.html.twig', [
            'messages' => iterator_to_array($paginator),
            'total' => \count($paginator),
            'page' => $page,
            'pageCount' => max(1, (int) ceil(\count($paginator) / 50)),
            'folder' => $folder,
            'folders' => $folders->findForUser($user),
            'isSentFolder' => MessageFolder::Sent->value === $folder['number'],
            'isSystem' => false,
        ]);
    }

    #[Route('/system', name: 'app_system_messages', methods: ['GET'])]
    public function systemMessages(Request $request, PrivateMessageRepository $messages): Response
    {
        $user = $this->requireBoardUser();
        $page = $this->pageFrom($request);

        $paginator = $messages->paginateFolder($user, MessageFolder::Inbox->value, $page, 50, system: true);

        return $this->render('message/inbox.html.twig', [
            'messages' => iterator_to_array($paginator),
            'total' => \count($paginator),
            'page' => $page,
            'pageCount' => max(1, (int) ceil(\count($paginator) / 50)),
            'folder' => ['number' => MessageFolder::Inbox->value, 'name' => 'System messages'],
            'folders' => [],
            'isSentFolder' => false,
            'isSystem' => true,
        ]);
    }

    #[Route('/{id<\d+>}', name: 'app_message_show', methods: ['GET'])]
    public function show(
        PrivateMessage $message,
        MessageManager $manager,
        MarkupRenderer $markup,
    ): Response {
        $user = $this->requireBoardUser();

        // Only the two parties may read it. The original let an admin read anyone's
        // messages by passing ?id=, which is kept - but as an explicit check.
        $isParty = $message->getRecipient() === $user || $message->getSender() === $user;
        if (!$isParty && !$user->isAdmin()) {
            throw $this->createAccessDeniedException('That message is not yours.');
        }

        $manager->markRead($message, $user);

        return $this->render('message/show.html.twig', [
            'message' => $message,
            'body' => $markup->render($message->getBody()),
            'header' => $markup->render($message->getHeaderLayout()?->getBody()),
            'signature' => $markup->render($message->getSignatureLayout()?->getBody()),
            'canReply' => null !== $message->getSender() && !$message->isSystem(),
        ]);
    }

    #[Route('/send/{id<\d+>}', name: 'app_message_send', methods: ['GET', 'POST'])]
    public function send(
        User $recipient,
        Request $request,
        MessageManager $manager,
        PrivateMessageRepository $messages,
    ): Response {
        $this->denyAccessUnlessGranted(ProfileVoter::SEND_MESSAGE, $recipient);

        $sender = $this->requireBoardUser();

        // Quoting a message pre-fills the reply, and must be one the sender can see.
        $initial = [];
        if ($replyTo = $request->query->getInt('reply')) {
            $original = $messages->find($replyTo);
            if (null !== $original && ($original->getRecipient() === $sender || $original->getSender() === $sender)) {
                $initial = [
                    'title' => str_starts_with($original->getTitle(), 'Re: ')
                        ? $original->getTitle()
                        : 'Re: '.$original->getTitle(),
                    'body' => \sprintf(
                        "[quote=%s]%s[/quote]\n\n",
                        $original->getSender()?->getUsername() ?? 'deleted user',
                        $original->getBody(),
                    ),
                ];
            }
        }

        $form = $this->createForm(SendMessageType::class, $initial);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$manager->consumeRateLimit($sender)) {
                $this->addFlash('error', 'You are sending messages too quickly. Please wait a while.');

                return $this->redirectToRoute('app_messages');
            }

            $data = $form->getData();
            $manager->send(
                $sender,
                $recipient,
                (string) $data['title'],
                (string) $data['body'],
                $this->clientIp($request),
            );

            $this->addFlash('success', \sprintf('Message sent to %s.', $recipient->getUsername()));

            return $this->redirectToRoute('app_messages');
        }

        return $this->render('message/send.html.twig', [
            'recipient' => $recipient,
            'form' => $form,
        ]);
    }

    /**
     * Bulk delete or move. Ownership is enforced by the query that loads the rows,
     * not by filtering afterwards.
     */
    #[Route('/bulk', name: 'app_message_bulk', methods: ['POST'])]
    public function bulk(
        Request $request,
        PrivateMessageRepository $messages,
        PrivateMessageFolderRepository $folders,
        MessageManager $manager,
    ): Response {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'message-bulk');

        /** @var list<int> $ids */
        $ids = array_values(array_filter(array_map(
            'intval',
            (array) $request->request->all('selected'),
        )));

        $sentSide = MessageFolder::Sent->value === $request->request->getInt('folder');
        $owned = $messages->findOwnedByIds($user, $ids, $sentSide);

        if ([] === $owned) {
            $this->addFlash('error', 'No messages were selected.');

            return $this->redirectToRoute('app_messages');
        }

        if ('delete' === $request->request->get('action')) {
            $count = $manager->delete($user, $owned, $sentSide);
            $this->addFlash('success', \sprintf('%d message(s) deleted.', $count));
        } else {
            $target = $request->request->getInt('moveTo');

            // Only the reserved inbox or a folder this user actually owns.
            $valid = MessageFolder::Inbox->value === $target
                || null !== $folders->findByNumber($user, $target);

            if (!$valid) {
                $this->addFlash('error', 'That folder does not exist.');

                return $this->redirectToRoute('app_messages');
            }

            $count = $manager->moveToFolder($user, $owned, $target, $sentSide);
            $this->addFlash('success', \sprintf('%d message(s) moved.', $count));
        }

        return $this->redirectToRoute('app_messages');
    }

    #[Route('/folders', name: 'app_message_folders', methods: ['GET', 'POST'])]
    public function folders(
        Request $request,
        PrivateMessageFolderRepository $folders,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->requireBoardUser();

        $form = $this->createForm(MessageFolderType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $folder = new PrivateMessageFolder(
                $user,
                $folders->nextNumberFor($user),
                (string) $form->getData()['name'],
            );
            $em->persist($folder);
            $em->flush();

            $this->addFlash('success', 'Folder created.');

            return $this->redirectToRoute('app_message_folders');
        }

        return $this->render('message/folders.html.twig', [
            'folders' => $folders->findForUser($user),
            'form' => $form,
        ]);
    }

    #[Route('/folders/{id<\d+>}/delete', name: 'app_message_folder_delete', methods: ['POST'])]
    public function deleteFolder(
        PrivateMessageFolder $folder,
        Request $request,
        PrivateMessageRepository $messages,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'delete-folder'.$folder->getId());

        if ($folder->getUser() !== $user) {
            throw $this->createAccessDeniedException('That folder is not yours.');
        }

        // Return its contents to the inbox rather than orphaning them into a folder
        // number that no longer resolves - which is what the original did.
        $em->getConnection()->executeStatement(
            'UPDATE private_messages SET recipient_folder = :inbox
             WHERE recipient_id = :uid AND recipient_folder = :folder',
            [
                'inbox' => MessageFolder::Inbox->value,
                'uid' => $user->getId(),
                'folder' => $folder->getNumber(),
            ],
        );

        $em->remove($folder);
        $em->flush();

        $this->addFlash('success', 'Folder deleted; its messages went back to your inbox.');

        return $this->redirectToRoute('app_message_folders');
    }

    /**
     * @return array{number: int, name: string}
     */
    private function resolveFolder(Request $request, User $user, PrivateMessageFolderRepository $folders): array
    {
        $view = (string) $request->query->get('folder', '');

        if ('sent' === $view) {
            return ['number' => MessageFolder::Sent->value, 'name' => 'Sent'];
        }

        if (ctype_digit($view)) {
            $number = (int) $view;
            $folder = $folders->findByNumber($user, $number);
            if (null !== $folder) {
                return ['number' => $folder->getNumber(), 'name' => $folder->getName()];
            }
        }

        return ['number' => MessageFolder::Inbox->value, 'name' => 'Inbox'];
    }
}
