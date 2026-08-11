<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ActionLog;
use App\Entity\Post;
use App\Form\EditPostType;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use App\Service\PostManager;
use App\Service\PostRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Editing and deleting individual posts.
 *
 * editpost.php in the original performed no authorisation check whatsoever - it
 * loaded the post by id from the query string and saved whatever was submitted. The
 * only thing standing between a visitor and rewriting any post on the board was that
 * the Edit link was not rendered for them. Every action here goes through PostVoter.
 */
final class PostController extends AbstractBoardController
{
    #[Route('/post/{id<\d+>}/edit', name: 'app_post_edit', methods: ['GET', 'POST'])]
    public function edit(
        Post $post,
        Request $request,
        PostManager $postManager,
        PostRenderer $renderer,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);
        $this->trackForum($request, $post->getThread()->getForum());

        $editor = $this->requireBoardUser();

        $form = $this->createForm(EditPostType::class, ['body' => $post->getBody()]);
        $form->handleRequest($request);

        $preview = null;
        if ($form->isSubmitted() && $form->get('preview')->isClicked() && $form->isValid()) {
            $preview = $renderer->renderPreview(
                $post->getAuthor() ?? $editor,
                (string) $form->get('body')->getData(),
                $this->boardUser(),
            );
        }

        if ($form->isSubmitted() && $form->isValid() && $form->get('submit')->isClicked()) {
            $postManager->edit($post, (string) $form->getData()['body'], $editor);

            if ($editor !== $post->getAuthor()) {
                $em->persist(new ActionLog(
                    $editor,
                    ActionLog::ACTION_POST_EDIT,
                    'post:'.$post->getId(),
                    ['author' => $post->getAuthor()?->getId()],
                    $this->clientIp($request),
                ));
                $em->flush();
            }

            $this->addFlash('success', 'Post updated.');

            return $this->redirectToRoute('app_post_permalink', ['id' => $post->getId()]);
        }

        return $this->render('post/edit.html.twig', [
            'post' => $post,
            'thread' => $post->getThread(),
            'form' => $form,
            'preview' => $preview,
        ]);
    }

    /**
     * Deletion is POST-only and tokened. The original used
     * `editpost.php?id=N&action=delete` behind a JavaScript confirm() - which is not
     * a security control, and the link was forgeable from any page on the internet.
     */
    #[Route('/post/{id<\d+>}/delete', name: 'app_post_delete', methods: ['POST'])]
    public function delete(
        Post $post,
        Request $request,
        PostManager $postManager,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(PostVoter::DELETE, $post);
        $this->assertCsrf($request, 'delete-post'.$post->getId());

        $actor = $this->requireBoardUser();
        $thread = $post->getThread();
        $forum = $thread->getForum();
        $wasOnlyPost = 0 === $thread->getReplies();

        $em->persist(new ActionLog(
            $actor,
            ActionLog::ACTION_POST_DELETE,
            'post:'.$post->getId(),
            ['thread' => $thread->getId(), 'author' => $post->getAuthor()?->getId()],
            $this->clientIp($request),
        ));
        $em->flush();

        $postManager->delete($post);

        $this->addFlash('success', 'Post deleted.');

        // Deleting the only post takes the thread with it, so there is nothing to
        // return to.
        return $wasOnlyPost
            ? $this->redirectToRoute('app_forum', ['id' => $forum->getId()])
            : $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
    }
}
