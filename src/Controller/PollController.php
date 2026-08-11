<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Poll;
use App\Entity\Thread;
use App\Form\PollType;
use App\Repository\PollVoteRepository;
use App\Security\Voter\ThreadVoter;
use App\Service\PollManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Poll voting and editing.
 */
final class PollController extends AbstractBoardController
{
    /**
     * Casting a vote.
     *
     * The original did this with `thread.php?id=N&choice=M&action=vote` - a GET link
     * that wrote to the database, so a poll could be stuffed by getting people to
     * load an image. It also inserted the choice id unvalidated, so voting for a
     * choice belonging to a *different* poll worked fine.
     */
    #[Route('/thread/{id<\d+>}/vote', name: 'app_poll_vote', methods: ['POST'])]
    public function vote(Thread $thread, Request $request, PollManager $polls): Response
    {
        $this->denyAccessUnlessGranted(ThreadVoter::VIEW, $thread);
        $this->assertCsrf($request, 'vote'.$thread->getId());

        $voter = $this->requireBoardUser();
        $poll = $thread->getPoll();

        if (null === $poll) {
            throw $this->createNotFoundException('This thread has no poll.');
        }

        $result = $polls->vote($poll, $voter, $request->request->getInt('choice'));

        $this->addFlash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
    }

    #[Route('/thread/{id<\d+>}/poll/edit', name: 'app_poll_edit', methods: ['GET', 'POST'])]
    public function edit(
        Thread $thread,
        Request $request,
        EntityManagerInterface $em,
        PollVoteRepository $votes,
    ): Response {
        // The thread's author may edit their own poll; otherwise it takes a moderator.
        $user = $this->requireBoardUser();
        if ($thread->getAuthor() !== $user) {
            $this->denyAccessUnlessGranted(ThreadVoter::MODERATE, $thread);
        }

        $poll = $thread->getPoll() ?? new Poll();
        $isNew = null === $thread->getPoll();

        $form = $this->createForm(PollType::class, $poll);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $thread->setPoll($poll);
                $em->persist($poll);
            }

            // Keep each choice's position in step with the order submitted.
            $position = 0;
            foreach ($poll->getChoices() as $choice) {
                $choice->setPosition($position++);
            }

            $em->flush();
            $this->addFlash('success', $isNew ? 'Poll added.' : 'Poll updated.');

            return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
        }

        return $this->render('poll/edit.html.twig', [
            'thread' => $thread,
            'poll' => $poll,
            'form' => $form,
            'isNew' => $isNew,
        ]);
    }
}
