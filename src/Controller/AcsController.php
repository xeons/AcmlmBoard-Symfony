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
 * ACS: who posted the most on a given day. Ported from acs.php.
 *
 * Two differences from the original. Days are bounded in the viewer's own timezone
 * rather than the server's, so "today" means today where the member is; and the
 * counts only include forums the viewer may read, so the page cannot be used to
 * infer activity in a restricted forum.
 */
final class AcsController extends AbstractBoardController
{
    private const VIEWS = ['full', 'top10', 'movement'];

    #[Route('/acs', name: 'app_acs', methods: ['GET'])]
    public function index(
        Request $request,
        PostRepository $posts,
        ForumRepository $forums,
        UserRepository $users,
    ): Response {
        $zone = $this->boardUser()?->getTimezoneObject() ?? new \DateTimeZone('UTC');
        $day = $this->day($request, $zone);

        $view = (string) $request->query->get('view', 'full');
        if (!\in_array($view, self::VIEWS, true)) {
            $view = 'full';
        }

        $visible = $forums->findReadableIds($this->viewerPower());

        $ranked = $this->rank($posts->countByAuthorBetween($visible, $day, $day->modify('+1 day')));

        // The movement view needs yesterday's placings to work out who moved.
        $previous = [];
        if ('movement' === $view) {
            foreach ($this->rank($posts->countByAuthorBetween($visible, $day->modify('-1 day'), $day)) as $row) {
                $previous[$row['user']->getId()] = $row['rank'];
            }
        }

        $highlight = $this->highlight($request, $users);

        return $this->render('community/acs.html.twig', [
            'rows' => 'top10' === $view ? $this->topTen($ranked, $highlight) : $ranked,
            'view' => $view,
            'views' => self::VIEWS,
            'day' => $day,
            'isToday' => $day->format('Y-m-d') === (new \DateTimeImmutable('now', $zone))->format('Y-m-d'),
            'total' => array_sum(array_column($ranked, 'count')),
            'busiest' => $ranked[0]['count'] ?? 0,
            'highlight' => $highlight,
            'previous' => $previous,
        ]);
    }

    /**
     * Assigns placings, sharing a rank between equal counts.
     *
     * @param list<array{user: User, count: int}> $rows
     *
     * @return list<array{user: User, count: int, rank: int}>
     */
    private function rank(array $rows): array
    {
        $ranked = [];
        $rank = 0;
        $previousCount = null;

        foreach ($rows as $index => $row) {
            if ($row['count'] !== $previousCount) {
                $rank = $index + 1;
                $previousCount = $row['count'];
            }

            $ranked[] = $row + ['rank' => $rank];
        }

        return $ranked;
    }

    /**
     * The top ten, plus the highlighted member if they placed outside it.
     *
     * @param list<array{user: User, count: int, rank: int}> $ranked
     *
     * @return list<array{user: User, count: int, rank: int}>
     */
    private function topTen(array $ranked, ?User $highlight): array
    {
        $top = array_values(array_filter($ranked, static fn (array $r): bool => $r['rank'] <= 10));

        if (null === $highlight) {
            return $top;
        }

        foreach ($ranked as $row) {
            if ($row['user'] === $highlight && $row['rank'] > 10) {
                $top[] = $row;
                break;
            }
        }

        return $top;
    }

    /** Midnight of the requested day, in the viewer's zone. */
    private function day(Request $request, \DateTimeZone $zone): \DateTimeImmutable
    {
        $requested = trim((string) $request->query->get('day', ''));

        if ('' !== $requested) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $requested, $zone);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return (new \DateTimeImmutable('now', $zone))->setTime(0, 0);
    }

    private function highlight(Request $request, UserRepository $users): ?User
    {
        $name = trim((string) $request->query->get('member', ''));
        if ('' !== $name) {
            return $users->findOneByUsername($name);
        }

        // "You", the original's default, once somebody is signed in.
        return $request->query->has('member') ? null : $this->boardUser();
    }
}
