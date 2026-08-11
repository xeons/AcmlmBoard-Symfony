<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Forum;
use App\Entity\Post;
use App\Entity\Thread;
use App\Entity\User;
use App\Form\NewThreadType;
use App\Form\ReplyType;
use App\Repository\FavoriteRepository;
use App\Repository\ForumRepository;
use App\Repository\PollVoteRepository;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use App\Security\Voter\ForumVoter;
use App\Security\Voter\ThreadVoter;
use App\Service\OnlineTracker;
use App\Service\PostingGuard;
use App\Service\PostManager;
use App\Service\PostRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reading threads, starting them, and replying.
 */
final class ThreadController extends AbstractBoardController
{
    #[Route('/thread/{id<\d+>}', name: 'app_thread', methods: ['GET'])]
    public function show(
        Thread $thread,
        Request $request,
        PostRepository $posts,
        ThreadRepository $threads,
        PostRenderer $renderer,
        PollVoteRepository $pollVotes,
        FavoriteRepository $favorites,
        OnlineTracker $online,
    ): Response {
        $this->denyAccessUnlessGranted(ThreadVoter::VIEW, $thread);
        $this->trackForum($request, $thread->getForum());

        $viewer = $this->boardUser();
        $page = $this->pageFrom($request);
        $perPage = $this->perPage($request, $viewer?->getPostsPerPage() ?? 20);

        $paginator = $posts->paginateThread($thread, $page, $perPage);
        $pagePosts = iterator_to_array($paginator);

        // One aggregate for the whole page, rather than the original's per-post query.
        $activity = $posts->getRecentActivityByUser(new \DateTimeImmutable('-1 day'));

        $threads->incrementViews($thread);

        return $this->render('thread/show.html.twig', [
            'thread' => $thread,
            'forum' => $thread->getForum(),
            'posts' => $renderer->renderAll($pagePosts, $viewer, $activity),
            'total' => \count($paginator),
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil(\count($paginator) / $perPage)),
            'poll' => $thread->getPoll(),
            'votedChoiceIds' => null !== $thread->getPoll()
                ? $pollVotes->findChoiceIdsVotedBy($thread->getPoll(), $viewer)
                : [],
            'isFavorite' => null !== $viewer && $favorites->exists($viewer, $thread),
            'newerThread' => $threads->findNewerSibling($thread),
            'olderThread' => $threads->findOlderSibling($thread),
            'online' => $online->snapshot($thread->getForum()),
        ]);
    }

    /**
     * Permalink to a single post: works out which page it is on and redirects there.
     *
     * The original loaded every post id in the thread into a PHP array and walked it
     * to find the index. This is one COUNT.
     */
    #[Route('/post/{id<\d+>}', name: 'app_post_permalink', methods: ['GET'])]
    public function permalink(Post $post, Request $request, PostRepository $posts): Response
    {
        $thread = $post->getThread();
        $this->denyAccessUnlessGranted(ThreadVoter::VIEW, $thread);

        $perPage = $this->perPage($request, $this->boardUser()?->getPostsPerPage() ?? 20);
        $position = $posts->getPositionInThread($post);

        return $this->redirectToRoute('app_thread', [
            'id' => $thread->getId(),
            'page' => (int) ceil($position / $perPage),
            '_fragment' => 'post-'.$post->getId(),
        ]);
    }

    /** Posts by one user, scoped to forums the viewer can read. */
    #[Route('/user/{id<\d+>}/posts', name: 'app_posts_by_user', methods: ['GET'])]
    public function byUser(
        User $user,
        Request $request,
        PostRepository $posts,
        ForumRepository $forums,
        PostRenderer $renderer,
    ): Response {
        $page = $this->pageFrom($request);
        $perPage = $this->perPage($request, $this->boardUser()?->getPostsPerPage() ?? 20);

        $paginator = $posts->paginateByAuthor(
            $user,
            $forums->findReadableIds($this->viewerPower()),
            $page,
            $perPage,
        );

        return $this->render('thread/post_list.html.twig', [
            'heading' => \sprintf('Posts by %s', $user->getUsername()),
            'listingUser' => $user,
            'posts' => $renderer->renderAll(iterator_to_array($paginator), $this->boardUser()),
            'total' => \count($paginator),
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil(\count($paginator) / $perPage)),
            'route' => 'app_posts_by_user',
            'routeParams' => ['id' => $user->getId()],
        ]);
    }

    #[Route('/forum/{id<\d+>}/new-thread', name: 'app_thread_new', methods: ['GET', 'POST'])]
    public function newThread(
        Forum $forum,
        Request $request,
        PostManager $postManager,
        PostingGuard $guard,
        PostRenderer $renderer,
    ): Response {
        $this->denyAccessUnlessGranted(ForumVoter::CREATE_THREAD, $forum);
        $this->trackForum($request, $forum);

        $author = $this->requireBoardUser();

        if (null !== $reason = $guard->refusalReasonForNewThread($author, $forum)) {
            return $this->render('thread/refused.html.twig', [
                'reason' => $reason,
                'backRoute' => 'app_forum',
                'backParams' => ['id' => $forum->getId()],
            ]);
        }

        $form = $this->createForm(NewThreadType::class, null, [
            'allow_poll' => true,
            'is_moderator' => $this->isGranted(ForumVoter::MODERATE, $forum),
        ]);
        $form->handleRequest($request);

        // Preview renders the post exactly as it will appear, through the same
        // sanitizer - so what you see in the preview is what gets stored.
        $preview = null;
        if ($form->isSubmitted() && $form->get('preview')->isClicked() && $form->isValid()) {
            $preview = $renderer->renderPreview($author, (string) $form->get('body')->getData(), $this->boardUser());
        }

        if ($form->isSubmitted() && $form->isValid() && $form->get('submit')->isClicked()) {
            if (!$guard->consumeRateLimit($author)) {
                $this->addFlash('error', 'You are posting too quickly. Please wait a moment.');

                return $this->redirectToRoute('app_forum', ['id' => $forum->getId()]);
            }

            $data = $form->getData();

            $thread = $postManager->createThread(
                $forum,
                $author,
                (string) $data['title'],
                (string) $data['body'],
                $this->clientIp($request),
                $data['icon'] ?? null,
            );

            if (($data['withPoll'] ?? false) && isset($data['pollQuestion'])) {
                $postManager->attachPoll(
                    $thread,
                    (string) $data['pollQuestion'],
                    $data['pollBriefing'] ?? null,
                    array_values(array_filter(array_map('trim', $data['pollChoices'] ?? []))),
                    (bool) ($data['pollMultiVote'] ?? false),
                );
            }

            return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
        }

        return $this->render('thread/new.html.twig', [
            'forum' => $forum,
            'form' => $form,
            'preview' => $preview,
        ]);
    }

    #[Route('/thread/{id<\d+>}/reply', name: 'app_thread_reply', methods: ['GET', 'POST'])]
    public function reply(
        Thread $thread,
        Request $request,
        PostRepository $posts,
        PostManager $postManager,
        PostingGuard $guard,
        PostRenderer $renderer,
    ): Response {
        $this->denyAccessUnlessGranted(ThreadVoter::REPLY, $thread);
        $this->trackForum($request, $thread->getForum());

        $author = $this->requireBoardUser();

        if (null !== $reason = $guard->refusalReasonForReply($author, $thread)) {
            return $this->render('thread/refused.html.twig', [
                'reason' => $reason,
                'backRoute' => 'app_thread',
                'backParams' => ['id' => $thread->getId()],
            ]);
        }

        // Quoting pre-fills the box; the quoted post must be in this thread.
        $initial = null;
        if ($quoteId = $request->query->getInt('quote')) {
            $quoted = $posts->find($quoteId);
            if (null !== $quoted && $quoted->getThread() === $thread) {
                $initial = \sprintf(
                    "[quote=%s]%s[/quote]\n\n",
                    $quoted->getAuthor()?->getUsername() ?? 'deleted user',
                    $quoted->getBody(),
                );
            }
        }

        $form = $this->createForm(ReplyType::class, ['body' => $initial], [
            'is_moderator' => $this->isGranted(ThreadVoter::MODERATE, $thread),
        ]);
        $form->handleRequest($request);

        $preview = null;
        if ($form->isSubmitted() && $form->get('preview')->isClicked() && $form->isValid()) {
            $preview = $renderer->renderPreview($author, (string) $form->get('body')->getData(), $this->boardUser());
        }

        if ($form->isSubmitted() && $form->isValid() && $form->get('submit')->isClicked()) {
            if (!$guard->consumeRateLimit($author)) {
                $this->addFlash('error', 'You are posting too quickly. Please wait a moment.');

                return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
            }

            $data = $form->getData();

            $post = $postManager->reply(
                $thread,
                $author,
                (string) $data['body'],
                $this->clientIp($request),
                (bool) ($data['closeAfter'] ?? false),
            );

            return $this->redirectToRoute('app_post_permalink', ['id' => $post->getId()]);
        }

        // The tail of the thread, shown under the reply box for context. Fetched
        // newest-first then reversed, so it is the *last* ten posts and not the
        // first ten - which is what the original showed when a thread got long.
        $recent = array_reverse($posts->findLatestForFeed($thread, 10));

        return $this->render('thread/reply.html.twig', [
            'thread' => $thread,
            'forum' => $thread->getForum(),
            'form' => $form,
            'preview' => $preview,
            'recentPosts' => $renderer->renderAll($recent, $this->boardUser()),
        ]);
    }
}
