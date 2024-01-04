<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks\Strategies;

use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutOrderService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutRefundService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\Interfaces\PostFinanceCheckoutOrderUpdateStrategyInterface;
use PostFinanceCheckout\Sdk\Model\TransactionState;

class PostFinanceCheckoutNameOrderUpdateRefundStrategy implements PostFinanceCheckoutOrderUpdateStrategyInterface
{
    /**
     * @var PostFinanceCheckoutRefundService $refundService
     */
    private $refundService;

    /**
     * @var PostFinanceCheckoutTransactionService $transactionService
     */
    private $transactionService;

    /**
     * @var PostFinanceCheckoutOrderService $orderService
     */
    private $orderService;

    public function __construct(PostFinanceCheckoutRefundService $refundService, PostFinanceCheckoutTransactionService $transactionService)
    {
        $this->refundService = $refundService;
        $this->transactionService = $transactionService;
        $this->orderService = new PostFinanceCheckoutOrderService();
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function updateOrderStatus(string $entityId): void
    {
        /**
         * @var \PostFinanceCheckout\Sdk\Model\Refund $refund
         */
        $refund = $this->refundService->getRefundFromPortal($entityId);

        $orderId = (int)$refund->getTransaction()->getMetaData()['orderId'];
        if (empty($orderId)) {
            $transactionId = (int)$refund->getTransaction()->getId();
            $localTransaction = $this->transactionService->getLocalPostFinanceCheckoutTransactionById((string)$transactionId);
            $orderId = (int)$localTransaction->order_id;
        }
        $this->refundService->createRefundRecord((int)$entityId, $orderId, $refund->getAmount());

        $transaction = $refund->getTransaction();
        $amountToBeRefunded = round(floatval($transaction->getCompletedAmount()) - $transaction->getRefundedAmount(), 2);

        if ($amountToBeRefunded > 0) {
            $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_BEZAHLT, \BESTELLUNG_STATUS_TEILVERSANDT);
            $this->transactionService->updateTransactionStatus($transaction->getId(), 'PARTIALLY REFUNDED');
            print 'Order ' . $orderId . ' status was partially refunded. Triggered by Refund webhook.';
        } else {
            if (!$this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_BEZAHLT, \BESTELLUNG_STATUS_STORNO)) {
                $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_TEILVERSANDT, \BESTELLUNG_STATUS_STORNO);
            }
            $this->transactionService->updateTransactionStatus($transaction->getId(), 'REFUNDED');
            print 'Order ' . $orderId . ' status was refunded. Triggered by Refund webhook.';
        }
    }
}
