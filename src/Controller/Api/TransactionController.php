<?php

namespace App\Controller\Api;

use App\Entity\Article;
use App\Entity\Transaction;
use App\Entity\User;
use App\Exception\AccountBalanceBoundaryException;
use App\Exception\ArticleNotFoundException;
use App\Exception\TransactionBoundaryException;
use App\Exception\TransactionInvalidException;
use App\Exception\TransactionNotFoundException;
use App\Exception\UserNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/api")
 */
class TransactionController extends AbstractController {

    /**
     * @Route("/transaction", methods="GET")
     */
    public function list(Request $request, EntityManagerInterface $entityManager) {
        $limit = $request->query->get('limit', 25);
        $offset = $request->query->get('offset');

        $count = $entityManager->getRepository(Transaction::class)->count([]);
        $transactions = $entityManager->getRepository(Transaction::class)->findAll($limit, $offset);

        return $this->json([
            'count' => $count,
            'transactions' => $transactions
        ]);
    }

    /**
     * @Route("/user/{userId}/transaction", methods="POST")
     * @throws UserNotFoundException
     * @throws TransactionBoundaryException
     * @throws ArticleNotFoundException
     * @throws AccountBalanceBoundaryException
     * @throws TransactionInvalidException
     */
    public function createUserTransactions($userId, Request $request, EntityManagerInterface $entityManager) {

        $user = $entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            throw new UserNotFoundException($userId);
        }

        $article = null;
        $recipientUser = null;
        $recipientTransaction = null;

        $amount = (int) $request->request->get('amount', 0);
        $comment = $request->request->get('comment');
        $articleId = $request->request->get('articleId');

        if ($articleId) {
            $article = $entityManager->getRepository(Article::class)->findOneBy(
                ['id' => $articleId, 'active' => true]);

            if (!$article) {
                throw new ArticleNotFoundException($articleId);
            }

            $amount = $article->getAmount() * -1;
            $article->incrementUsageCount();
        }

        $this->checkTransactionBoundaries($amount);

        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setAmount($amount);
        $transaction->setArticle($article);
        $transaction->setComment($comment);

        $recipientId = $request->request->get('recipientId');
        if ($recipientId) {
            $recipientUser = $entityManager->getRepository(User::class)->find($recipientId);
            if (!$recipientUser) {
                throw new UserNotFoundException($recipientId);
            }

            $recipientTransaction = new Transaction();
            $recipientTransaction->setAmount($amount * -1);
            $recipientTransaction->setArticle($article);
            $recipientTransaction->setComment($comment);
            $recipientTransaction->setUser($recipientUser);

            $recipientTransaction->setSenderTransaction($transaction);
            $transaction->setRecipientTransaction($recipientTransaction);

            $recipientBalance = $recipientUser->getBalance() + ($amount * -1);
            $this->checkAccountBalance($recipientUser, $recipientBalance);

            $recipientUser->setBalance($recipientBalance);
        }

        $newBalance = $user->getBalance() + $amount;
        $this->checkAccountBalance($user, $newBalance);

        $user->setBalance($newBalance);

        $entityManager->transactional(function () use ($entityManager, $user, $transaction, $article, $recipientUser, $recipientTransaction) {
            $entityManager->persist($user);
            $entityManager->persist($transaction);

            if ($article) {
                $entityManager->persist($article);
            }

            if ($recipientUser) {
                $entityManager->persist($recipientUser);
            }

            if ($recipientTransaction) {
                $entityManager->persist($recipientTransaction);
            }
        });

        return $this->json([
            'transaction' => $transaction,
        ]);
    }

    /**
     * @Route("/user/{userId}/transaction", methods="GET")
     * @throws UserNotFoundException
     */
    public function getUserTransactions($userId, Request $request, EntityManagerInterface $entityManager) {
        $limit = $request->query->get('limit', 25);
        $offset = $request->query->get('offset');

        $user = $entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            throw new UserNotFoundException($userId);
        }

        $count = $entityManager->getRepository(Transaction::class)->countByUser($user);
        $transactions = $entityManager->getRepository(Transaction::class)->findByUser($user, $limit, $offset);

        return $this->json([
            'count' => $count,
            'transactions' => $transactions,
        ]);
    }

    /**
     * @Route("/user/{userId}/transaction/{transactionId}", methods="GET")
     * @throws UserNotFoundException
     * @throws TransactionNotFoundException
     */
    public function getUserTransaction($userId, $transactionId, EntityManagerInterface $entityManager) {
        $transaction = $this->getTransaction($userId, $transactionId, $entityManager);

        return $this->json([
            'transaction' => $transaction,
        ]);
    }

    /**
     * @Route("/user/{userId}/transaction/{transactionId}", methods="DELETE")
     * @throws UserNotFoundException
     * @throws TransactionNotFoundException
     */
    public function deleteTransaction($userId, $transactionId, EntityManagerInterface $entityManager) {
        $transaction = $this->getTransaction($userId, $transactionId, $entityManager);

        $entityManager->transactional(function() use ($entityManager, $transaction) {

            $article = $transaction->getArticle();
            if ($article) {
                $article->decrementUsageCount();
                $entityManager->persist($article);
            }

            $recipientTransaction = $transaction->getRecipientTransaction();
            if ($recipientTransaction) {
                $this->revertTransaction($recipientTransaction, $entityManager);
            }

            $senderTransaction = $transaction->getSenderTransaction();
            if ($senderTransaction) {
                $this->revertTransaction($senderTransaction, $entityManager);
            }

            $this->revertTransaction($transaction, $entityManager);
        });

        return $this->json([
            'transaction' => $transaction,
        ]);
    }

    /**
     * @param int $amount
     * @throws TransactionBoundaryException
     * @throws TransactionInvalidException
     */
    private function checkTransactionBoundaries($amount) {
        $settings = $this->getParameter('strichliste');

        $upper = $settings['payment']['boundary']['upper'];
        $lower = $settings['payment']['boundary']['lower'];

        if ($amount > $upper) {
            throw new TransactionBoundaryException($amount, $upper);
        } else if ($amount < $lower){
            throw new TransactionBoundaryException($amount, $lower);
        } else if ($amount === 0) {
            throw new TransactionInvalidException();
        }
    }

    /**
     * @param User $user
     * @param int $amount
     * @throws AccountBalanceBoundaryException
     */
    private function checkAccountBalance(User $user, $amount) {
        $settings = $this->getParameter('strichliste');

        $upper = $settings['account']['boundary']['upper'];
        $lower = $settings['account']['boundary']['lower'];

        if ($amount > $upper) {
            throw new AccountBalanceBoundaryException($user, $amount, $upper);
        } else if ($amount < $lower){
            throw new AccountBalanceBoundaryException($user, $amount, $lower);
        }
    }

    /**
     * @param int $userId
     * @param int $transactionId
     * @param EntityManagerInterface $entityManager
     * @return Transaction
     * @throws TransactionNotFoundException
     * @throws UserNotFoundException
     */
    private function getTransaction(int $userId, int $transactionId, EntityManagerInterface $entityManager): Transaction {
        $user = $entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            throw new UserNotFoundException($userId);
        }

        $transaction = $entityManager->getRepository(Transaction::class)->findByUserAndId($user, $transactionId);
        if (!$transaction) {
            throw new TransactionNotFoundException($user, $transactionId);
        }

        return $transaction;
    }

    /**
     * @param Transaction $transaction
     * @param EntityManagerInterface $entityManager
     */
    private function revertTransaction(Transaction $transaction, EntityManagerInterface $entityManager) {
        $recipientUser = $transaction->getUser();
        $recipientUser->setBalance($recipientUser->getBalance() + ($transaction->getAmount() * -1));

        $entityManager->persist($recipientUser);
        $entityManager->remove($transaction);
    }
}
