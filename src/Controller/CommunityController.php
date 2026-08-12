<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\CalendarEventRepository;
use App\Repository\DailyStatRepository;
use App\Repository\RankSetRepository;
use App\Repository\UserRepository;
use App\Service\BoardStatsService;
use App\Service\OnlineTracker;
use App\Service\RankResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The community pages: member list, active users, ranks, online list, calendar,
 * statistics and the post radar.
 */
final class CommunityController extends AbstractBoardController
{
    /**
     * Name lookup behind the member pickers.
     *
     * Any signed-in member can call this, which gives away nothing: /members
     * already lists everyone by name. It exists so that the forms that need to
     * name a member can offer a type-ahead instead of demanding the numeric id
     * the original's `?u=N` links happened to expose.
     */
    #[Route('/members/find', name: 'app_member_find', methods: ['GET'])]
    public function findMember(Request $request, UserRepository $users): Response
    {
        $this->requireBoardUser();

        $fragment = trim((string) $request->query->get('q', ''));
        if (\strlen($fragment) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(
            static fn (User $u): array => ['id' => $u->getId(), 'name' => $u->getUsername()],
            $users->searchByUsername($fragment, 10),
        ));
    }

    /**
     * Sortable member list.
     *
     * The original built the ORDER BY by concatenating the `sort` query parameter
     * straight into the SQL, so `memberlist.php?sort=` was a direct injection point.
     * Sort keys are matched against a fixed map here.
     */
    #[Route('/members', name: 'app_members', methods: ['GET'])]
    public function members(Request $request, UserRepository $users, RankResolver $ranks): Response
    {
        $sortMap = [
            'name' => ['u.username', 'ASC'],
            'posts' => ['u.posts', 'DESC'],
            'registered' => ['u.registeredAt', 'DESC'],
            'active' => ['u.lastActivityAt', 'DESC'],
            'power' => ['u.powerLevel', 'DESC'],
        ];

        $sort = (string) $request->query->get('sort', 'posts');
        if (!isset($sortMap[$sort])) {
            $sort = 'posts';
        }

        [$field, $defaultDirection] = $sortMap[$sort];
        $direction = 'asc' === strtolower((string) $request->query->get('dir', '')) ? 'ASC' : $defaultDirection;

        $page = $this->pageFrom($request);
        $perPage = 50;

        $qb = $users->memberListQueryBuilder()
            ->orderBy($field, $direction)
            ->addOrderBy('u.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ('' !== $search = trim((string) $request->query->get('q', ''))) {
            $qb->andWhere('u.usernameCanonical LIKE :q')
                ->setParameter('q', '%'.User::canonicalizeUsername($search).'%');
        }

        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($qb->getQuery(), fetchJoinCollection: false);

        return $this->render('community/members.html.twig', [
            'members' => iterator_to_array($paginator),
            'total' => \count($paginator),
            'page' => $page,
            'pageCount' => max(1, (int) ceil(\count($paginator) / $perPage)),
            'sort' => $sort,
            'direction' => $direction,
            'search' => $search,
            'ranks' => $ranks,
        ]);
    }

    /** Members seen in the last 30 days, ordered by post count. */
    #[Route('/active-users', name: 'app_active_users', methods: ['GET'])]
    public function activeUsers(UserRepository $users): Response
    {
        $since = new \DateTimeImmutable('-30 days');

        $active = $users->createQueryBuilder('u')
            ->andWhere('u.lastActivityAt > :since OR u.lastPostAt > :since')
            ->setParameter('since', $since)
            ->orderBy('u.posts', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return $this->render('community/active_users.html.twig', [
            'members' => $active,
            'since' => $since,
        ]);
    }

    #[Route('/online', name: 'app_online', methods: ['GET'])]
    public function online(OnlineTracker $online, BoardStatsService $stats): Response
    {
        return $this->render('community/online.html.twig', [
            'online' => $online->snapshot(),
            'threshold' => $online->threshold(),
            'records' => $stats->get(),
        ]);
    }

    /**
     * The ranks ladder, showing who currently sits on each rung.
     */
    #[Route('/ranks', name: 'app_ranks', methods: ['GET'])]
    public function ranks(Request $request, RankSetRepository $rankSets, UserRepository $users): Response
    {
        $sets = $rankSets->findAllOrdered();
        $selectedId = $request->query->getInt('set');

        $selected = null;
        foreach ($sets as $set) {
            if ($set->getId() === $selectedId) {
                $selected = $set;
                break;
            }
        }
        $selected ??= $sets[0] ?? null;

        $showAll = $request->query->getBoolean('all');
        $activeSince = new \DateTimeImmutable('-30 days');

        $rows = [];
        if (null !== $selected) {
            $rungs = $selected->getRanks()->toArray();
            usort($rungs, static fn ($a, $b): int => $a->getMinPosts() <=> $b->getMinPosts());

            foreach ($rungs as $i => $rung) {
                // The band runs up to the next rung's threshold, or to infinity for
                // the top rung.
                $ceiling = isset($rungs[$i + 1]) ? $rungs[$i + 1]->getMinPosts() : \PHP_INT_MAX;

                $filterSet = $showAll ? null : $selected->getId();

                $rows[] = [
                    'rank' => $rung,
                    'occupants' => $users->findActiveInPostRange($rung->getMinPosts(), $ceiling, $activeSince, $filterSet),
                    'count' => $users->countInPostRange($rung->getMinPosts(), $ceiling, $filterSet),
                ];
            }
        }

        return $this->render('community/ranks.html.twig', [
            'sets' => $sets,
            'selected' => $selected,
            'rows' => $rows,
            'showAll' => $showAll,
        ]);
    }

    #[Route('/calendar', name: 'app_calendar', methods: ['GET'])]
    public function calendar(
        Request $request,
        CalendarEventRepository $events,
        UserRepository $users,
    ): Response {
        $now = new \DateTimeImmutable();
        $year = $request->query->getInt('year', (int) $now->format('Y'));
        $month = $request->query->getInt('month', (int) $now->format('n'));

        // Clamp to something a calendar can actually render.
        $month = max(1, min(12, $month));
        $year = max(1970, min(2100, $year));

        $first = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));

        // Birthdays are shown alongside events, grouped by day.
        $birthdaysByDay = [];
        foreach ($users->findBirthdaysInMonth($month) as $user) {
            $birthdaysByDay[(int) $user->getBirthday()?->format('j')][] = $user;
        }

        return $this->render('community/calendar.html.twig', [
            'year' => $year,
            'month' => $month,
            'firstOfMonth' => $first,
            'daysInMonth' => (int) $first->format('t'),
            'startWeekday' => (int) $first->format('N'),
            'eventsByDay' => $events->findForMonth($year, $month),
            'birthdaysByDay' => $birthdaysByDay,
            'today' => $now,
        ]);
    }

    #[Route('/stats', name: 'app_stats', methods: ['GET'])]
    public function stats(BoardStatsService $stats, DailyStatRepository $daily): Response
    {
        return $this->render('community/stats.html.twig', [
            'records' => $stats->get(),
            'totals' => $stats->totals(),
            'daily' => $daily->findRecent(60),
        ]);
    }

    /** The post-count race list. */
    #[Route('/post-radar', name: 'app_post_radar', methods: ['GET', 'POST'])]
    public function postRadar(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        \App\Service\PostRadar $radar,
    ): Response {
        $user = $this->requireBoardUser();

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'post-radar');

            $removing = 'remove' === $request->request->get('action');

            // The remove buttons carry the id of a row the server rendered; the
            // add field carries a name somebody typed. Separate fields so that a
            // member named "12" cannot stand in for member 12.
            $rival = $removing
                ? $users->find($request->request->getInt('rival'))
                : $users->findOneByUsername(trim((string) $request->request->get('member', '')));

            if (null === $rival) {
                $this->addFlash('error', 'No member by that name.');
            } elseif ($removing) {
                $user->removeRival($rival);
                $em->flush();
                $this->addFlash('success', \sprintf('%s removed from your radar.', $rival->getUsername()));
            } else {
                $user->addRival($rival);
                $em->flush();
                $this->addFlash('success', \sprintf('%s added to your radar.', $rival->getUsername()));
            }

            return $this->redirectToRoute('app_post_radar');
        }

        return $this->render('community/post_radar.html.twig', [
            'comparisons' => $radar->compare($user),
        ]);
    }
}
