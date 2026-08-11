<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RpgProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RpgProfile>
 */
class RpgProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RpgProfile::class);
    }

    /**
     * The user's shop state, created on demand.
     *
     * shop.php created these by running `INSERT INTO users_rpg (uid) SELECT id FROM
     * users` - one insert per registered account - every time anyone opened a shop
     * category. One row is created here, for the user who actually needs it.
     */
    public function findOrCreateFor(User $user): RpgProfile
    {
        $profile = $this->findOneBy(['user' => $user]);

        if (null === $profile) {
            $profile = new RpgProfile($user);
            $user->setRpgProfile($profile);
            $this->getEntityManager()->persist($profile);
            $this->getEntityManager()->flush();
        }

        return $profile;
    }
}
