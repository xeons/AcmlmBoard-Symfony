<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingRegistration;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PendingRegistration>
 */
class PendingRegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingRegistration::class);
    }

    public function findByUsername(string $username): ?PendingRegistration
    {
        return $this->findOneBy(['usernameCanonical' => User::canonicalizeUsername($username)]);
    }

    /** Unexpired pending registrations against an email, for the per-address cap. */
    public function countPendingForEmail(string $email, ?\DateTimeImmutable $now = null): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('LOWER(p.email) = :email')
            ->andWhere('p.expiresAt > :now')
            ->setParameter('email', mb_strtolower($email))
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPendingForIp(string $ip, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.ip = :ip')
            ->andWhere('p.createdAt > :since')
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function purgeExpired(?\DateTimeImmutable $now = null): int
    {
        return (int) $this->createQueryBuilder('p')
            ->delete()
            ->andWhere('p.expiresAt <= :now')
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
