<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ColorScheme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ColorScheme>
 */
class ColorSchemeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ColorScheme::class);
    }

    /** @return list<ColorScheme> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?ColorScheme
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** The scheme applied to guests and to anyone who has not picked one. */
    public function findDefault(): ?ColorScheme
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * How many members use each scheme, shown next to the name in the profile form
     * exactly as the original did.
     *
     * @return array<int, int> scheme id => user count
     */
    public function getUsageCounts(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT color_scheme_id AS id, COUNT(*) AS n FROM users
             WHERE color_scheme_id IS NOT NULL GROUP BY color_scheme_id',
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (int) $row['n'];
        }

        return $out;
    }
}
