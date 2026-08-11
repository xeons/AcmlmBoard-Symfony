<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Passkey;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Passkey>
 */
class PasskeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Passkey::class);
    }

    /** @param string $credentialId base64url-encoded */
    public function findByCredentialId(string $credentialId): ?Passkey
    {
        return $this->findOneBy(['credentialId' => $credentialId]);
    }

    /** @return list<Passkey> */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByHandleAndCredential(string $userHandle, string $credentialId): ?Passkey
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')->addSelect('u')
            ->andWhere('p.credentialId = :cid')
            ->andWhere('u.webauthnHandle = :handle')
            ->setParameter('cid', $credentialId)
            ->setParameter('handle', $userHandle)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
