<?php

namespace App\Controller\Api;

use App\Entity\Article;
use App\Entity\ArticleTag;
use App\Entity\Barcode;
use App\Entity\Tag;
use App\Exception\ArticleBarcodeAlreadyExistsException;
use App\Exception\ArticleInactiveException;
use App\Exception\ArticleNotFoundException;
use App\Serializer\ArticleSerializer;
use App\Service\ArticleService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/api/article")
 */
class ArticleController extends AbstractController {

    /**
     * @var ArticleSerializer
     */
    private $articleSerializer;

    function __construct(ArticleSerializer $articleSerializer) {
        $this->articleSerializer = $articleSerializer;
    }

    /**
     * @Route(methods="GET")
     */
    function list(Request $request, EntityManagerInterface $entityManager) {
        $limit = $request->query->get('limit', 25);
        $offset = $request->query->get('offset');
        $active = $request->query->getBoolean('active', true);

        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('a1')
            ->from(Article::class, 'a1')
            ->leftJoin(Barcode::class, 'b', Join::WITH, 'b.article = a1')
            ->where('a1.active = :active')
            ->setParameter('active', $active);

        $barcode = $request->query->get('barcode');
        if ($barcode) {
            $queryBuilder
                ->andWhere('b.barcode = :barcode')
                ->setParameter('barcode', $barcode);
        }

        $precursor = $request->query->getBoolean('precursor', true);
        if (!$precursor) {
            $queryBuilder->andWhere('a1.precursor IS NULL');
        }

        $ancestor = $request->query->get('ancestor', null);
        if ($ancestor === 'true') {
            $queryBuilder->leftJoin(Article::class, 'a2', Join::WITH, 'a2.precursor = a1.id')->andWhere('a2.id IS NOT NULL');
        } elseif ($ancestor === 'false') {
            $queryBuilder->leftJoin(Article::class, 'a2', Join::WITH, 'a2.precursor = a1.id')->andWhere('a2.id IS NULL');
        }

        $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('a1.name', 'ASC');

        $articles = $queryBuilder->getQuery()->getResult();
        $articleRepository = $entityManager->getRepository(Article::class);

        return $this->json([
            'count' => $articleRepository->countActive(),
            'articles' => array_map(function (Article $article) {
                return $this->articleSerializer->serialize($article);
            }, $articles),
        ]);
    }

    /**
     * @Route(methods="POST")
     */
    function createArticle(Request $request, ArticleService $articleService, EntityManagerInterface $entityManager) {
        $article = $articleService->createArticleByRequest($request);

        $entityManager->persist($article);
        $entityManager->flush();

        return $this->json([
            'article' => $this->articleSerializer->serialize($article),
        ]);
    }

    /**
     * @Route("/search", methods="GET")
     */
    function search(Request $request, EntityManagerInterface $entityManager) {
        $query = $request->query->get('query');
        $limit = $request->query->get('limit', 25);
        $barcode = $request->query->get('barcode');
        $tag = $request->query->get('tag');

        $queryBuilder = $entityManager
            ->getRepository(Article::class)
            ->createQueryBuilder('a')
            ->leftJoin(Barcode::class, 'b', Join::WITH, 'b.article = a')
            ->leftJoin(ArticleTag::class, 'at', Join::WITH, 'at.article = a')
            ->leftJoin(Tag::class, 't', Join::WITH, 'at.tag = t');

        if ($barcode) {
            $query = false;

            $queryBuilder
                ->where('b.barcode = :barcode')
                ->setParameter('barcode', $barcode);
        }

        if ($tag) {
            $query = false;

            $queryBuilder
                ->where('t.tag = :tag')
                ->setParameter('tag', $tag);
        }

        if ($query) {
            $queryBuilder
                ->where('b.barcode = :barcode')
                ->orWhere('t.tag = :tag')
                ->orWhere('a.name LIKE :query')
                ->setParameter('barcode', $query)
                ->setParameter('tag', $query)
                ->setParameter('query', '%' . $query . '%');
        }

        $results = $queryBuilder
            ->andWhere('a.active = true')
            ->orderBy('a.name')
            ->setMaxResults($limit)
            ->groupBy('a')
            ->getQuery()
            ->getResult();

        return $this->json([
            'count' => count($results),
            'articles' => array_map(function (Article $article) {
                return $this->articleSerializer->serialize($article);
            }, $results),
        ]);
    }

    /**
     * @Route("/{articleId}", methods="GET")
     */
    function getArticle($articleId, Request $request, EntityManagerInterface $entityManager) {
        $depth = $request->query->get('depth', 1);

        $article = $entityManager->getRepository(Article::class)->find($articleId);
        if (!$article) {
            throw new ArticleNotFoundException($articleId);
        }

        return $this->json([
            'article' => $this->articleSerializer->serialize($article, $depth),
        ]);
    }

    /**
     * @Route("/{articleId}", methods="POST")
     */
    function updateArticle($articleId, Request $request, ArticleService $articleService, EntityManagerInterface $entityManager) {
        $article = $entityManager->getRepository(Article::class)->find($articleId);

        if (!$article) {
            throw new ArticleNotFoundException($articleId);
        }

        if (!$article->isActive()) {
            throw new ArticleInactiveException($article);
        }

        $article = $articleService->updateArticle($request, $article);

        return $this->json([
            'article' => $this->articleSerializer->serialize($article),
        ]);
    }

    /**
     * @Route("/{articleId}", methods="DELETE")
     */
    function deleteArticle($articleId, EntityManagerInterface $entityManager) {
        $article = $entityManager->getRepository(Article::class)->find($articleId);
        if (!$article) {
            throw new ArticleNotFoundException($articleId);
        }

        $article->setActive(false);

        $entityManager->persist($article);
        $entityManager->flush();

        return $this->json([
            'article' => $this->articleSerializer->serialize($article),
        ]);
    }
}
