<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PostLayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostLayout>
 */
class PostLayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostLayout::class);
    }

    /**
     * Returns the shared row for this body, creating it if new. Null bodies and
     * whitespace-only bodies deliberately map to null so empty signatures do not
     * each get a row.
     *
     * Two people posting simultaneously with an identical brand-new signature race
     * here; the unique index settles it and the loser re-reads the winner's row.
     */
    public function findOrCreate(?string $body): ?PostLayout
    {
        if (null === $body || '' === trim($body)) {
            return null;
        }

        $hash = PostLayout::hash($body);
        $existing = $this->findOneBy(['bodyHash' => $hash]);
        if (null !== $existing) {
            return $existing;
        }

        $em = $this->getEntityManager();
        $layout = new PostLayout($body);

        try {
            $em->persist($layout);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $em->clear(PostLayout::class);
            $found = $this->findOneBy(['bodyHash' => $hash]);
            if (null === $found) {
                throw new \RuntimeException('Post layout row vanished after a unique-constraint collision.');
            }

            return $found;
        }

        return $layout;
    }

    /** Rows no post, message or announcement references any more. */
    public function deleteOrphans(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE pl FROM post_layouts pl
             LEFT JOIN posts p1 ON p1.header_layout_id = pl.id
             LEFT JOIN posts p2 ON p2.signature_layout_id = pl.id
             LEFT JOIN private_messages m1 ON m1.header_layout_id = pl.id
             LEFT JOIN private_messages m2 ON m2.signature_layout_id = pl.id
             LEFT JOIN announcements a1 ON a1.header_layout_id = pl.id
             LEFT JOIN announcements a2 ON a2.signature_layout_id = pl.id
             WHERE p1.id IS NULL AND p2.id IS NULL
               AND m1.id IS NULL AND m2.id IS NULL
               AND a1.id IS NULL AND a2.id IS NULL',
        );
    }
}
