<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks\Strategies;

use JTL\Shop;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutOrderService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\Interfaces\PostFinanceCheckoutOrderUpdateStrategyInterface;
use PostFinanceCheckout\Sdk\Model\TransactionInvoiceState;
use PostFinanceCheckout\Sdk\Model\TransactionState;

class PostFinanceCheckoutNameOrderUpdateTransactionInvoiceStrategy implements PostFinanceCheckoutOrderUpdateStrategyInterface
{
    /**
     * @var PostFinanceCheckoutTransactionService $transactionService
     */
    public $transactionService;

    /**
     * @var PostFinanceCheckoutOrderService $orderService
     */
    private $orderService;

    public function __construct(PostFinanceCheckoutTransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
        $this->orderService = new PostFinanceCheckoutOrderService();
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function updateOrderStatus(string $entityId): void
    {
        $transactionInvoice = $this->transactionService->getTransactionInvoiceFromPortal($entityId);

        $transaction = $transactionInvoice->getCompletion()
            ->getLineItemVersion()
            ->getTransaction();
        
        
        $orderNr = $transaction->getMetaData()['order_nr'];
        $transactionId = $transaction->getId();
        
        // Fallback for older plugin versions
        if ($orderNr === null) {
            $localTransaction = $this->transactionService->getLocalPostFinanceCheckoutTransactionById((string)$transactionId);
            $orderId = (int)$localTransaction->order_id;
        } else {
            $orderData = $this->transactionService->getOrderIfExists($orderNr);
            if ($orderData === null) {
                Shop::Container()->getLogService()->error('Order was not found by nr: ' . $orderNr);
                return;
            }
            $orderId = (int)$orderData->kBestellung;
        }

        switch ($transactionInvoice->getState()) {
            case TransactionInvoiceState::DERECOGNIZED:
                $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_IN_BEARBEITUNG, \BESTELLUNG_STATUS_STORNO);
                $this->transactionService->updateTransactionStatus($transactionId, TransactionState::DECLINE);
                print 'Order ' . $orderId . ' status was updated to cancelled. Triggered by Transaction Invoice webhook.';
                break;

            //case TransactionInvoiceState::NOT_APPLICABLE:
            case TransactionInvoiceState::PAID:
                if (!$this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_OFFEN, \BESTELLUNG_STATUS_BEZAHLT)) {
                    $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_IN_BEARBEITUNG, \BESTELLUNG_STATUS_BEZAHLT);
                }

                $order = new Bestellung($orderId);
                $this->transactionService->addIncommingPayment((string)$transactionId, $order, $transaction);
                print 'Order ' . $orderId . ' status was updated to paid. Triggered by Transaction Invoice webhook.';
                break;
        }
    }
}
