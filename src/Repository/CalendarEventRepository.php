<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    /**
     * Events falling in a month, grouped by day-of-month. Annual events match on
     * month regardless of the year they were entered under.
     *
     * @return array<int, list<CalendarEvent>>
     */
    public function findForMonth(int $year, int $month): array
    {
        $start = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('first day of next month');

        $events = $this->createQueryBuilder('e')
            ->leftJoin('e.author', 'u')->addSelect('u')
            ->andWhere('(e.annual = false AND e.date >= :start AND e.date < :end) OR e.annual = true')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();

        $byDay = [];
        foreach ($events as $event) {
            if ($event->isAnnual() && (int) $event->getDate()->format('n') !== $month) {
                continue;
            }
            $byDay[(int) $event->getDate()->format('j')][] = $event;
        }

        return $byDay;
    }
}
