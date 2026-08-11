<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Forum;
use App\Entity\Thread;
use App\Entity\User;
use App\Repository\AnnouncementRepository;
use App\Repository\FavoriteRepository;
use App\Repository\ForumReadRepository;
use App\Repository\ForumRepository;
use App\Repository\ThreadRepository;
use App\Repository\UserRepository;
use App\Security\Voter\ForumVoter;
use App\Service\BoardStatsService;
use App\Service\OnlineTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Thread listings: a forum, one user's threads, and the favorites list.
 *
 * The original crammed all three into forum.php, distinguished by which query
 * parameter happened to be set, plus two more actions (add/remove favorite) that
 * were GET links with no token.
 */
final class ForumController extends AbstractBoardController
{
    #[Route('/forum/{id<\d+>}', name: 'app_forum', methods: ['GET'])]
    public function show(
        Forum $forum,
        Request $request,
        ThreadRepository $threads,
        ForumReadRepository $reads,
        AnnouncementRepository $announcements,
        OnlineTracker $online,
        BoardStatsService $stats,
    ): Response {
        $this->denyAccessUnlessGranted(ForumVoter::VIEW, $forum);
        $this->trackForum($request, $forum);

        $viewer = $this->boardUser();
        $page = $this->pageFrom($request);
        $perPage = $this->perPage($request, $viewer?->getThreadsPerPage() ?? 50, 'tpp', 200);

        $paginator = $threads->paginateForum($forum, $page, $perPage);
        $readMap = null !== $viewer ? $reads->getReadMap($viewer) : [];

        return $this->render('forum/show.html.twig', [
            'forum' => $forum,
            'threads' => iterator_to_array($paginator),
            'total' => \count($paginator),
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil(\count($paginator) / $perPage)),
            'readAt' => $readMap[$forum->getId()] ?? null,
            'guestCutoff' => new \DateTimeImmutable('-1 hour'),
            'hotThreshold' => $stats->get()->getHotThreadThreshold(),
            'globalAnnouncement' => $announcements->findLatestGlobal(),
            'forumAnnouncement' => $announcements->findLatestForForum($forum),
            'online' => $online->snapshot($forum),
            'postsPerPage' => $viewer?->getPostsPerPage() ?? 20,
            'listing' => 'forum',
        ]);
    }

    /**
     * Threads started by one user, across every forum the *viewer* may read.
     * The original leaked restricted threads here by filtering in the template
     * rather than the query.
     */
    #[Route('/user/{id<\d+>}/threads', name: 'app_forum_by_user', methods: ['GET'])]
    public function byUser(
        User $user,
        Request $request,
        ThreadRepository $threads,
        ForumRepository $forums,
        BoardStatsService $stats,
    ): Response {
        $page = $this->pageFrom($request);
        $perPage = $this->perPage($request, $this->boardUser()?->getThreadsPerPage() ?? 50, 'tpp', 200);

        $paginator = $threads->paginateByAuthor(
            $user,
            $forums->findReadableIds($this->viewerPower()),
            $page,
            $perPage,
        );

        return $this->render('forum/show.html.twig', [
            'forum' => null,
            'listingUser' => $user,
            'threads' => iterator_to_array($paginator),
            'total' => \count($paginator),
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil(\count($paginator) / $perPage)),
            'readAt' => null,
            'guestCutoff' => new \DateTimeImmutable('-1 hour'),
            'hotThreshold' => $stats->get()->getHotThreadThreshold(),
            'globalAnnouncement' => null,
            'forumAnnouncement' => null,
            'online' => null,
            'postsPerPage' => $this->boardUser()?->getPostsPerPage() ?? 20,
            'listing' => 'user',
        ]);
    }

    #[Route('/favorites', name: 'app_favorites', methods: ['GET'])]
    public function favorites(
        Request $request,
        ThreadRepository $threads,
        ForumRepository $forums,
        BoardStatsService $stats,
    ): Response {
        $viewer = $this->requireBoardUser();
        $page = $this->pageFrom($request);
        $perPage = $this->perPage($request, $viewer->getThreadsPerPage(), 'tpp', 200);

        $paginator = $threads->paginateFavorites(
            $viewer,
            $forums->findReadableIds($this->viewerPower()),
            $page,
            $perPage,
        );

        return $this->render('forum/show.html.twig', [
            'forum' => null,
            'threads' => iterator_to_array($paginator),
            'total' => \count($paginator),
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil(\count($paginator) / $perPage)),
            'readAt' => null,
            'guestCutoff' => new \DateTimeImmutable('-1 hour'),
            'hotThreshold' => $stats->get()->getHotThreadThreshold(),
            'globalAnnouncement' => null,
            'forumAnnouncement' => null,
            'online' => null,
            'postsPerPage' => $viewer->getPostsPerPage(),
            'listing' => 'favorites',
        ]);
    }

    /** POST, tokened: the original made this a plain link, so it was CSRF-able. */
    #[Route('/thread/{id<\d+>}/favorite', name: 'app_favorite_toggle', methods: ['POST'])]
    public function toggleFavorite(
        Thread $thread,
        Request $request,
        FavoriteRepository $favorites,
        EntityManagerInterface $em,
    ): Response {
        $viewer = $this->requireBoardUser();
        $this->assertCsrf($request, 'favorite'.$thread->getId());
        $this->denyAccessUnlessGranted(ForumVoter::VIEW, $thread->getForum());

        $existing = $favorites->findOneBy(['user' => $viewer, 'thread' => $thread]);

        if (null !== $existing) {
            $em->remove($existing);
            $this->addFlash('success', \sprintf('"%s" removed from your favorites.', $thread->getTitle()));
        } else {
            $em->persist(new \App\Entity\Favorite($viewer, $thread));
            $this->addFlash('success', \sprintf('"%s" added to your favorites.', $thread->getTitle()));
        }

        $em->flush();

        return $this->redirectToRoute('app_thread', ['id' => $thread->getId()]);
    }

    /** Target of the forum jump menu; redirects to the chosen forum. */
    #[Route('/forum-jump', name: 'app_forum_jump', methods: ['GET'])]
    public function jump(Request $request, ForumRepository $forums): Response
    {
        $forum = $forums->find($request->query->getInt('id'));

        if (null === $forum || !$this->isGranted(ForumVoter::VIEW, $forum)) {
            return $this->redirectToRoute('app_index');
        }

        return $this->redirectToRoute('app_forum', ['id' => $forum->getId()]);
    }

    #[Route('/forum/{id<\d+>}/mark-read', name: 'app_forum_mark_read', methods: ['POST'])]
    public function markRead(Forum $forum, Request $request, ForumReadRepository $reads): Response
    {
        $viewer = $this->requireBoardUser();
        $this->assertCsrf($request, 'mark-read');
        $this->denyAccessUnlessGranted(ForumVoter::VIEW, $forum);

        $reads->markRead($viewer, $forum);
        $this->addFlash('success', \sprintf('"%s" marked as read.', $forum->getTitle()));

        return $this->redirectToRoute('app_forum', ['id' => $forum->getId()]);
    }
}
