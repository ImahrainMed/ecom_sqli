<?php

namespace App\Repository;

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

    public function search(?string $categorySlug, ?string $keyword): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.category', 'c')
            ->addSelect('c');

        if ($categorySlug) {
            $qb->andWhere('c.slug = :slug')
               ->setParameter('slug', $categorySlug);
        }

        if ($keyword) {
            $qb->andWhere('p.name LIKE :keyword OR p.description LIKE :keyword')
               ->setParameter('keyword', '%' . $keyword . '%');
        }

        return $qb->getQuery()->getResult();
    }
}
