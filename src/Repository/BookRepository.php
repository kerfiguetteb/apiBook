<?php

namespace App\Repository;

use App\Entity\Book;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Book>
 */
class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    public function findAllWithPagination($page, $limit)
    {
        $qb = $this->createQueryBuilder('b')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        // $query = $qb->getQuery();
        // $query->setFetchMode(Book::class, "author", \Doctrine\ORM\Mapping\ClassMetadata::FETCH_EAGER);
        return $qb->getQuery()->getResult();
    }

       /**
        * @return Book[] Returns an array of Book objects
        */
       public function findOneByTitle($value): array
       {
        $qb = $this->createQueryBuilder('b');
           return $qb->where($qb->expr()->like('b.title', ':value'))
               ->setParameter('value', "%{$value}%")
               ->orderBy('b.title', 'ASC')
               ->getQuery()
               ->getResult()
           ;
       }

    //    public function findOneBySomeField($value): ?Book
    //    {
    //        return $this->createQueryBuilder('b')
    //            ->andWhere('b.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
