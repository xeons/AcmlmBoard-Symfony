<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Forum;
use App\Entity\Thread;
use App\Form\EditThreadType;
use App\Repository\ForumRepository;
use App\Security\Voter\ThreadVoter;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Thread moderation.
 *
 * Every one of these was a GET link in the original - thread.php?id=1&qmod=1&st=1,
 * &cl=1, &lo=1, &trash=1 - with no token and no confirmation. Embedding
 * `<img src="board/thread.php?id=1&qmod=1&trash=1">` in a post was enough to make
 * any moderator who viewed it trash that thread. All POST and tokened now.
 */
#[Route('/thread/{id<\d+>}')]
final class ModerationController extends AbstractBoardController
{
    #[Route('/sticky', name: 'app_thread_sticky', methods: ['POST'])]
    public function sticky(Thread $thread, Request $request, ModerationService $moderation): Response
    {
        $this->denyAccessUnlessGranted(ThreadVoter::MODERATE, $thread);
        $this->assertCsrf($request, 'moderate'.$thread->getId());

        $sticky = $request->request->getBoolean('sticky');
        $moderation->setSticky($thread, $sticky, $this->requireBoardUser(), $this->clientIp($request));

        $this->addFlash('success', $sticky ? 'Thread stuck.' : 'Thread unstuck.');

        return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
    }

    #[Route('/close', name: 'app_thread_close', methods: ['POST'])]
    public function close(Thread $thread, Request $request, ModerationService $moderation): Response
    {
        $this->denyAccessUnlessGranted(ThreadVoter::MODERATE, $thread);
        $this->assertCsrf($request, 'moderate'.$thread->getId());

        $closed = $request->request->getBoolean('closed');
        $moderation->setClosed($thread, $closed, $this->requireBoardUser(), $this->clientIp($request));

        $this->addFlash('success', $closed ? 'Thread closed.' : 'Thread reopened.');

        return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
    }

    #[Route('/lock', name: 'app_thread_lock', methods: ['POST'])]
    public function lock(Thread $thread, Request $request, ModerationService $moderation): Response
    {
        $this->denyAccessUnlessGranted(ThreadVoter::LOCK, $thread);
        $this->assertCsrf($request, 'moderate'.$thread->getId());

        $locked = $request->request->getBoolean('locked');
        $moderation->setLocked($thread, $locked, $this->requireBoardUser(), $this->clientIp($request));

        $this->addFlash('success', $locked ? 'Thread locked against moderator edits.' : 'Thread unlocked.');

        return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
    }

    #[Route('/trash', name: 'app_thread_trash', methods: ['POST'])]
    public function trash(Thread $thread, Request $request, ModerationService $moderation): Response
    {
        $this->denyAccessUnlessGranted(ThreadVoter::MODERATE, $thread);
        $this->assertCsrf($request, 'moderate'.$thread->getId());

        $forumId = $thread->getForum()->getId();

        if (!$moderation->trash($thread, $this->requireBoardUser(), $this->clientIp($request))) {
            // The original hardcoded forum id 20 as the trash and silently moved
            // threads into whatever happened to live there.
            $this->addFlash('error', 'No trash forum is configured. Set one in the admin panel first.');

            return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
        }

        $this->addFlash('success', 'Thread moved to the trash.');

        return $this->redirectToRoute('app_forum', ['id' => $forumId]);
    }

    #[Route('/move', name: 'app_thread_move', methods: ['POST'])]
    public function move(
        Thread $thread,
        Request $request,
        ModerationService $moderation,
        ForumRepository $forums,
    ): Response {
        $this->denyAccessUnlessGranted(ThreadVoter::MODERATE, $thread);
        $this->assertCsrf($request, 'moderate'.$thread->getId());

        $destination = $forums->find($request->request->getInt('forum'));
        if (!$destination instanceof Forum) {
            $this->addFlash('error', 'That forum does not exist.');

            return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
        }

        // A moderator must be able to moderate the destination too, or a local
        // moderator could push threads into forums they have no authority over.
        $this->denyAccessUnlessGranted(\App\Security\Voter\ForumVoter::MODERATE, $destination);

        $moderation->move($thread, $destination, $this->requireBoardUser(), $this->clientIp($request));
        $this->addFlash('success', \sprintf('Thread moved to %s.', $destination->getTitle()));

        return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
    }

    #[Route('/delete', name: 'app_thread_delete', methods: ['POST'])]
    public function delete(Thread $thread, Request $request, ModerationService $moderation): Response
    {
        $this->denyAccessUnlessGranted(ThreadVoter::DELETE, $thread);
        $this->assertCsrf($request, 'moderate'.$thread->getId());

        $forumId = $thread->getForum()->getId();
        $moderation->deleteThread($thread, $this->requireBoardUser(), $this->clientIp($request));

        $this->addFlash('success', 'Thread deleted.');

        return $this->redirectToRoute('app_forum', ['id' => $forumId]);
    }

    /** Full thread editor: title, icon, and the flags in one form. */
    #[Route('/edit', name: 'app_thread_edit', methods: ['GET', 'POST'])]
    public function edit(
        Thread $thread,
        Request $request,
        EntityManagerInterface $em,
        ForumRepository $forums,
    ): Response {
        $this->denyAccessUnlessGranted(ThreadVoter::EDIT, $thread);
        $this->trackForum($request, $thread->getForum());

        $form = $this->createForm(EditThreadType::class, $thread, [
            'can_lock' => $this->isGranted(ThreadVoter::LOCK, $thread),
            'forums' => $forums->findForJumpMenu($this->viewerPower()),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Thread updated.');

            return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
        }

        return $this->render('thread/edit.html.twig', [
            'thread' => $thread,
            'form' => $form,
        ]);
    }
}
