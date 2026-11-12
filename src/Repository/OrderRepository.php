<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * The newest orders for the admin dashboard.
     *
     * @return Order[]
     */
    public function findRecent(int $limit): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.orderDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The full store order log, newest first — narrowed to one pipeline
     * status when given.
     *
     * @return Order[]
     */
    public function listAll(?string $status = null): array
    {
        return $this->listWhere($status);
    }

    /**
     * One customer's orders, newest first — narrowed to one pipeline status
     * when given.
     *
     * @return Order[]
     */
    public function listForUser(User $user, ?string $status = null): array
    {
        return $this->listWhere($status, $user);
    }

    private function listWhere(?string $status, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('o');
        $has_conditions = false;

        if ($user instanceof User) {
            $qb
                ->where('o.user = :user')
                ->setParameter('user', $user);
            $has_conditions = true;
        }

        if ($status !== null) {
            $has_conditions ? $qb->andWhere('o.status = :status') : $qb->where('o.status = :status');
            $qb->setParameter('status', $status);
        }

        return $qb
            ->orderBy('o.orderDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Orders per pipeline status — one account's own when given, the whole
     * store otherwise.
     *
     * @return array<string, int>
     */
    public function countByStatus(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('o.status', 'COUNT(o.id) AS total')
            ->groupBy('o.status');

        if ($user instanceof User) {
            $qb
                ->where('o.user = :user')
                ->setParameter('user', $user);
        }

        $counts = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
