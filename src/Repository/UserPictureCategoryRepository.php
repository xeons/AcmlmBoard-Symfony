<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserPictureCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserPictureCategory>
 */
class UserPictureCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPictureCategory::class);
    }

    /**
     * The whole gallery for a page, pictures included.
     *
     * @return list<UserPictureCategory>
     */
    public function findWithPictures(int $page = 0): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.pictures', 'p')->addSelect('p')
            ->andWhere('c.page = :page')
            ->setParameter('page', $page)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<int> distinct gallery page numbers */
    public function findPageNumbers(): array
    {
        return array_map('intval', $this->createQueryBuilder('c')
            ->select('DISTINCT c.page')
            ->orderBy('c.page', 'ASC')
            ->getQuery()
            ->getSingleColumnResult());
    }
}
