<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ThreadLayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThreadLayout>
 */
class ThreadLayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThreadLayout::class);
    }

    /** @return list<ThreadLayout> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.position', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?ThreadLayout
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findDefault(): ?ThreadLayout
    {
        return $this->findBySlug('regular') ?? $this->createQueryBuilder('l')
            ->orderBy('l.position', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return array<int, int> layout id => user count */
    public function getUsageCounts(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT thread_layout_id AS id, COUNT(*) AS n FROM users
             WHERE thread_layout_id IS NOT NULL GROUP BY thread_layout_id',
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (int) $row['n'];
        }

        return $out;
    }
}
