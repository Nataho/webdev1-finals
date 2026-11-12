<?php

namespace App\Repository;

use App\Entity\OrderItem;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * The full catalog: in-stock items first, out-of-stock at the very end,
     * newest listings leading within each group.
     *
     * @return Product[]
     */
    public function findAllInStockFirst(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('CASE WHEN p.productStockQuantity > 0 THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The catalog for the staff/admin stock tab: newest listings first, each
     * product carrying its creator in the same query (null for the rows that
     * predate ownership tracking) — narrowed to the search term when given.
     *
     * @return Product[]
     */
    public function searchAll(string $searchTerm = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.createdBy', 'creator')
            ->addSelect('creator')
            ->orderBy('p.id', 'DESC');

        if ('' !== $searchTerm) {
            $qb
                ->where('p.productName LIKE :term')
                ->setParameter('term', $searchTerm);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Best sellers: the top half of the catalog (rounded up) ranked by total
     * quantity sold, ties broken alphabetically. The sold count itself is a
     * hidden aggregate — it exists only for ordering.
     *
     * @return Product[]
     */
    public function findBestSellers(): array
    {
        // no products at all — nothing to rank
        $best_seller_limit = (int) ceil($this->count([]) * 0.5);
        if ($best_seller_limit === 0) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->select('p', 'COALESCE(SUM(oi.quantity), 0) AS HIDDEN sold')
            ->leftJoin(OrderItem::class, 'oi', 'WITH', 'oi.product = p')
            ->groupBy('p.id')
            ->orderBy('sold', 'DESC')
            ->addOrderBy('p.productName', 'ASC')
            ->setMaxResults($best_seller_limit)
            ->getQuery()
            ->getResult();
    }
}
