<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Item;
use App\Entity\ItemCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Item>
 */
class ItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    /**
     * Stock of one shop: items in that category plus the uncategorised wildcards.
     *
     * @return list<Item>
     */
    public function findForShop(ItemCategory $category): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.category = :category OR i.category IS NULL')
            ->setParameter('category', $category)
            ->orderBy('i.price', 'ASC')
            ->addOrderBy('i.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Loads a whole loadout in one query.
     *
     * @param list<int> $ids
     *
     * @return array<int, Item> keyed by item id
     */
    public function findByIdsIndexed(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $items = $this->createQueryBuilder('i')
            ->andWhere('i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($items as $item) {
            $map[$item->getId()] = $item;
        }

        return $map;
    }
}
