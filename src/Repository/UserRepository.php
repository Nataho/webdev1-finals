<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->findOneByEmailOrUsername($identifier);
    }

    public function findOneByEmailOrUsername(string $identifier): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :identifier OR u.username = :identifier')
            ->setParameter('identifier', $identifier)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every account, newest first — narrowed to the search term when one is given.
     *
     * @return User[]
     */
    public function searchAll(string $searchTerm = ''): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.id', 'DESC');

        if ('' !== $searchTerm) {
            $qb
                ->where('u.username LIKE :term OR u.email LIKE :term')
                ->setParameter('term', $searchTerm);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * How many accounts hold the given role (the dashboard's staff stat).
     */
    public function countByRole(string $role): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Uniqueness checks that leave one account out (the one being edited).
     */
    public function countByEmailExcept(string $email, ?int $exceptId = null): int
    {
        return $this->countExcept('email', $email, $exceptId);
    }

    public function countByUsernameExcept(string $username, ?int $exceptId = null): int
    {
        return $this->countExcept('username', $username, $exceptId);
    }

    private function countExcept(string $field, string $value, ?int $exceptId): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where("u.$field = :value")
            ->setParameter('value', $value);

        if (null !== $exceptId) {
            $qb
                ->andWhere('u.id != :exceptId')
                ->setParameter('exceptId', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
