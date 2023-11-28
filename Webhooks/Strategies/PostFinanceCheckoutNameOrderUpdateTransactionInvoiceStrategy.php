<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks\Strategies;

use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutOrderService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\Interfaces\PostFinanceCheckoutOrderUpdateStrategyInterface;
use PostFinanceCheckout\Sdk\Model\TransactionState;
use PostFinanceCheckout\Sdk\Model\TransactionInvoiceState;

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

		$orderId = (int)$transaction->getMetaData()['orderId'];
		$transactionId = $transaction->getId();

		switch ($transactionInvoice->getState()) {
			case TransactionInvoiceState::DERECOGNIZED:
				$this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_IN_BEARBEITUNG, \BESTELLUNG_STATUS_STORNO);
				$this->transactionService->updateTransactionStatus($transactionId, TransactionState::DECLINE);
				print 'Order ' . $orderId . ' status was updated to cancelled. Triggered by Transaction Invoice webhook.';
				break;

			case TransactionInvoiceState::NOT_APPLICABLE:
			case TransactionInvoiceState::PAID:
				if (!$this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_OFFEN, \BESTELLUNG_STATUS_BEZAHLT)) {
					$this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_IN_BEARBEITUNG, \BESTELLUNG_STATUS_BEZAHLT);
				}
				$this->transactionService->updateTransactionStatus($transactionId, TransactionState::FULFILL);
				print 'Order ' . $orderId . ' status was updated to paid. Triggered by Transaction Invoice webhook.';
				break;
		}
	}
}
