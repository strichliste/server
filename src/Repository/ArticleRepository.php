<?php

namespace App\Repository;

use App\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method Article|null find($id, $lockMode = null, $lockVersion = null)
 * @method Article|null findOneBy(array $criteria, array $orderBy = null)
 * @method Article[]    findAll()
 * @method Article[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ArticleRepository extends ServiceEntityRepository {

    public function __construct(RegistryInterface $registry) {
        parent::__construct($registry, Article::class);
    }

    public function findOneActive($id) {
        return $this->findOneBy(['active' => true, 'id' => $id]);
    }

    public function findAllActive($limit = null, $offset = null) {
        return $this->findBy(['active' => true], ['name' => 'ASC'], $limit, $offset);
    }

    public function findActiveBy(array $criteria, $limit = null, $offset = null) {
        $criteria = array_merge(['active' => true], $criteria);
        return $this->findBy($criteria, ['name' => 'ASC'], $limit, $offset);
    }

    public function findOneActiveBy(array $criteria) : ?Article {
        $criteria = array_merge(['active' => true], $criteria);
        return $this->findOneBy($criteria);
    }

    public function countActive(): int {
        return $this->count([
            'active' => true
        ]);
    }
}
