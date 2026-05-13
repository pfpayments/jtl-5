<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks\Strategies;

use JTL\Checkout\Bestellung;
use JTL\Checkout\Zahlungsart;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\Plugin;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutOrderService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\Interfaces\PostFinanceCheckoutOrderUpdateStrategyInterface;
use PostFinanceCheckout\Sdk\Model\Transaction;
use PostFinanceCheckout\Sdk\Model\TransactionState;

class PostFinanceCheckoutNameOrderUpdateTransactionStrategy implements PostFinanceCheckoutOrderUpdateStrategyInterface
{
    /**
     * @var Plugin $plugin
     */
    private $plugin;

    /**
     * @var PostFinanceCheckoutTransactionService $transactionService
     */
    private $transactionService;

    /**
     * @var PostFinanceCheckoutOrderService $orderService
     */
    private $orderService;

    public function __construct(PostFinanceCheckoutTransactionService $transactionService, Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->transactionService = $transactionService;
        $this->orderService = new PostFinanceCheckoutOrderService();
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function updateOrderStatus(string $entityId): void
    {
        $transaction = $this->transactionService->getTransactionFromPortal($entityId);
        if ($transaction === null) {
            print 'Transaction ' . $entityId . ' not found';
            exit;
        }

        $transactionId = $transaction->getId();
        $transactionState = $transaction->getState();

        $orderId = (int)$transaction->getMetaData()['orderId'];
        if ($orderId === 0) {
            print 'Order not found for transaction ' . $entityId;
            exit;
        }

        PostFinanceCheckoutHelper::log("webhook strategy: Processing transaction $transactionId with state $transactionState for order $orderId");
        switch ($transactionState) {
            case TransactionState::FULFILL:
                $order = new Bestellung($orderId);
                if ($order && (int )$order->cStatus !== \BESTELLUNG_STATUS_BEZAHLT) {
                    $orderData = $order->fuelleBestellung();
                    $this->transactionService->addIncomingPayment((string)$transactionId, $orderData, $transaction);
                    $this->transactionService->updateWawiSyncFlag($orderId, $this->transactionService::LET_SYNC_TO_WAWI);
                    $this->transactionService->handleNextOrderReferenceNumber($transaction->getMetaData()['order_no']);
                }
                break;

            case TransactionState::AUTHORIZED:
                $order = new Bestellung($orderId);
                if ($order && (int )$order->cStatus === \BESTELLUNG_STATUS_OFFEN) {
                    $this->transactionService->updateWawiSyncFlag($orderId, $this->transactionService::LET_SYNC_TO_WAWI);
                    $this->transactionService->handleNextOrderReferenceNumber($transaction->getMetaData()['order_no']);
                }
                break;

            case TransactionState::FAILED:
            case TransactionState::DECLINE:
            case TransactionState::VOIDED:
                PostFinanceCheckoutHelper::log("webhook strategy: Transaction $transactionId in state $transactionState. Cancelling order $orderId.");
                $order = new Bestellung($orderId);
                if (!empty($order->kZahlungsart)) {
                    $paymentMethodEntity = new Zahlungsart((int)$order->kZahlungsart);
                    $moduleId = $paymentMethodEntity->cModulId ?? '';
                    $paymentMethod = new Method($moduleId);
                    $paymentMethod->cancelOrder($orderId);
                }
                print 'Order ' . $orderId . ' status was updated to cancelled';
                break;
        }
        $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
    }
}
