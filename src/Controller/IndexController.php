<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnnouncementRepository;
use App\Repository\ForumReadRepository;
use App\Repository\ForumRepository;
use App\Repository\UserRepository;
use App\Service\BoardStatsService;
use App\Service\OnlineTracker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The board index: forum list grouped by category, who's online, birthdays, records.
 */
final class IndexController extends AbstractBoardController
{
    #[Route('/', name: 'app_index', methods: ['GET'])]
    public function index(
        ForumRepository $forums,
        ForumReadRepository $reads,
        UserRepository $users,
        OnlineTracker $online,
        BoardStatsService $stats,
        AnnouncementRepository $announcements,
    ): Response {
        $viewer = $this->boardUser();
        $now = new \DateTimeImmutable();

        // Forums, categories, moderators and last posters in a single query.
        $visible = $forums->findVisibleForIndex($this->viewerPower());

        // Group by category, preserving the ordering the query established.
        $categories = [];
        foreach ($visible as $forum) {
            $category = $forum->getCategory();
            if (null === $category) {
                continue;
            }

            $id = $category->getId();
            $categories[$id]['category'] = $category;
            $categories[$id]['forums'][] = $forum;

            // Track the newest post across the category for its summary column.
            $last = $forum->getLastPostAt();
            if (null !== $last && (!isset($categories[$id]['lastPostAt']) || $last > $categories[$id]['lastPostAt'])) {
                $categories[$id]['lastPostAt'] = $last;
            }
        }

        return $this->render('board/index.html.twig', [
            'categories' => $categories,
            // Read state drives the new-post icon; guests have none and fall back to
            // a one-hour window, as the original did.
            'readMap' => null !== $viewer ? $reads->getReadMap($viewer) : [],
            'guestCutoff' => $now->modify('-1 hour'),
            'online' => $online->snapshot(),
            'birthdays' => $users->findBirthdays((int) $now->format('n'), (int) $now->format('j')),
            'now' => $now,
            'newestUser' => $users->findNewest(),
            'totals' => $stats->totals(),
            'records' => $stats->get(),
            'announcement' => $announcements->findLatestGlobal(),
        ]);
    }

    #[Route('/mark-read', name: 'app_mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request, ForumReadRepository $reads): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->assertCsrf($request, 'mark-read');

        $reads->markAllRead($this->requireBoardUser());
        $this->addFlash('success', 'All forums marked as read.');

        return $this->redirectToRoute('app_index');
    }

    #[Route('/faq', name: 'app_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('board/faq.html.twig');
    }

    #[Route('/credits', name: 'app_credits', methods: ['GET'])]
    public function credits(): Response
    {
        return $this->render('board/credits.html.twig');
    }

    #[Route('/banned', name: 'app_banned', methods: ['GET'])]
    public function banned(): Response
    {
        return $this->render('error/banned.html.twig');
    }
}
