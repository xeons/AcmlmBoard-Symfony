<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ForumRepository;
use App\Repository\PostRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where the board's posts are going: by forum, by thread and by hour of the day,
 * over a chosen window. Ported from postsbyforum.php, postsbythread.php and
 * postsbytime.php.
 *
 * Every query is scoped to the forums the viewer may read, so a restricted forum
 * cannot be inferred from a count.
 */
final class StatisticsController extends AbstractBoardController
{
    /** Window name => seconds. "all" is the original's 999999999. */
    private const WINDOWS = [
        'hour' => 3600,
        'day' => 86400,
        'week' => 604800,
        'month' => 2592000,
        'all' => null,
    ];

    #[Route('/statistics/forums', name: 'app_stats_by_forum', methods: ['GET'])]
    public function byForum(Request $request, PostRepository $posts, ForumRepository $forums, UserRepository $users): Response
    {
        [$window, $since] = $this->window($request);
        $member = $this->member($request, $users);

        $rows = $posts->countByForum($forums->findReadableIds($this->viewerPower()), $member, $since);

        return $this->render('statistics/by_forum.html.twig', [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'count')),
            'window' => $window,
            'windows' => array_keys(self::WINDOWS),
            'member' => $member,
            'route' => 'app_stats_by_forum',
        ]);
    }

    #[Route('/statistics/threads', name: 'app_stats_by_thread', methods: ['GET'])]
    public function byThread(Request $request, PostRepository $posts, ForumRepository $forums, UserRepository $users): Response
    {
        [$window, $since] = $this->window($request);
        $member = $this->member($request, $users);

        // Per-thread counts are only meaningful for one member; the original
        // required an id here too.
        if (null === $member) {
            return $this->render('statistics/pick_member.html.twig', [
                'window' => $window,
                'windows' => array_keys(self::WINDOWS),
                'route' => 'app_stats_by_thread',
            ]);
        }

        $rows = $posts->countByThread($forums->findReadableIds($this->viewerPower()), $member, $since);

        return $this->render('statistics/by_thread.html.twig', [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'count')),
            'window' => $window,
            'windows' => array_keys(self::WINDOWS),
            'member' => $member,
            'route' => 'app_stats_by_thread',
        ]);
    }

    #[Route('/statistics/hours', name: 'app_stats_by_hour', methods: ['GET'])]
    public function byHour(Request $request, PostRepository $posts, ForumRepository $forums, UserRepository $users): Response
    {
        [$window, $since] = $this->window($request);
        $member = $this->member($request, $users);

        $hours = $posts->countByHourOfDay(
            $forums->findReadableIds($this->viewerPower()),
            $member,
            $since,
            $this->boardUser()?->getCurrentUtcOffsetMinutes() ?? 0,
        );

        return $this->render('statistics/by_hour.html.twig', [
            'hours' => $hours,
            'peak' => max($hours),
            'total' => array_sum($hours),
            'window' => $window,
            'windows' => array_keys(self::WINDOWS),
            'member' => $member,
            'route' => 'app_stats_by_hour',
        ]);
    }

    /** @return array{string, \DateTimeImmutable|null} */
    private function window(Request $request): array
    {
        $window = (string) $request->query->get('window', 'day');
        if (!\array_key_exists($window, self::WINDOWS)) {
            $window = 'day';
        }

        $seconds = self::WINDOWS[$window];

        return [$window, null === $seconds ? null : new \DateTimeImmutable('@'.(time() - $seconds))];
    }

    private function member(Request $request, UserRepository $users): ?User
    {
        $id = $request->query->getInt('user');

        return $id > 0 ? $users->find($id) : null;
    }
}
