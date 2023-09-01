<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\adminmenu;

use JTL\Catalog\Product\Preise;
use JTL\Checkout\Bestellung;
use JTL\DB\DbInterface;
use JTL\DB\ReturnType;
use JTL\Language\LanguageHelper;
use JTL\Pagination\Pagination;
use JTL\Plugin\Plugin;
use JTL\Plugin\PluginInterface;
use JTL\Session\Frontend;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutRefundService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutApiClient;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\{TransactionInvoiceState, TransactionState};

class AdminTabProvider
{
	const ACTION_COMPLETE = 'complete';
	const ACTION_CANCEL = 'cancel';
	const ACTION_DOWNLOAD_INVOICE = 'download_invoice';
	const ACTION_DOWNLOAD_PACKAGING_SLIP = 'download_packaging_slip';
	const ACTION_REFUND = 'refund';
	const ACTION_ORDER_DETAILS = 'order_details';
	
	const FILE_DOWNLOAD_ALLOWED_STATES = [
	  'FULFILL',
	  'PAID',
	  'REFUNDED',
	  'PARTIALY_REFUNDED'
	];
	
	/**
	 * @var PluginInterface
	 */
	private $plugin;
	
	/**
	 * @var DbInterface
	 */
	private $db;
	
	/**
	 * @var JTLSmarty
	 */
	private $smarty;
	
	/**
	 * @param PluginInterface $plugin
	 */
	
	public function __construct(PluginInterface $plugin, DbInterface $db, JTLSmarty $smarty)
	{
		$this->plugin = $plugin;
		$this->db = $db;
		$this->smarty = $smarty;
		
		$this->apiClient = PostFinanceCheckoutHelper::getApiClient($plugin->getId());
		if (empty($this->apiClient)) {
			return;
		}
		
		$this->transactionService = new PostFinanceCheckoutTransactionService($this->apiClient, $this->plugin);
		$this->refundService = new PostFinanceCheckoutRefundService($this->apiClient, $this->plugin);
	}
	
	/**
	 *
	 * @param int $menuID
	 * @return string
	 */
	public function createOrdersTab(int $menuID): string
	{
		$action = !empty($_REQUEST['action']) ? $_REQUEST['action'] : null;
		
		if (!empty($action)) {
			
			switch ($action) {
				
				case self::ACTION_COMPLETE:
					$transactionId = $_REQUEST['transactionId'] ?: null;
					$this->completeTransaction($transactionId);
					exit;
				
				case self::ACTION_CANCEL:
					$transactionId = $_REQUEST['transactionId'] ?: null;
					$this->cancelTransaction($transactionId);
					exit;
				
				case self::ACTION_DOWNLOAD_INVOICE:
					$transactionId = $_REQUEST['transactionId'] ?: null;
					$this->downloadInvoice($transactionId);
					exit;
				
				case self::ACTION_DOWNLOAD_PACKAGING_SLIP:
					$transactionId = $_REQUEST['transactionId'] ?: null;
					$this->downloadPackagingSlip($transactionId);
					exit;
				
				case self::ACTION_REFUND:
					$transactionId = $_REQUEST['transactionId'] ?: null;
					$amount = $_REQUEST['amount'] ?: 0;
					$this->refundService->makeRefund($transactionId, floatval($amount));
					exit;
				
				case self::ACTION_ORDER_DETAILS:
					$this->displayOrderInfo($_REQUEST, $menuID);
					break;
			}
		}
		
		$orders = [];
		$ordersQuantity = $this->db->query('SELECT transaction_id FROM postfinancecheckout_transactions', ReturnType::AFFECTED_ROWS);
		$pagination = (new Pagination('postfinancecheckout-orders'))->setItemCount($ordersQuantity)->assemble();
		
		$orderArr = $this->db->query('SELECT ord.kBestellung, ord.fGesamtsumme, plugin.transaction_id, plugin.state FROM tbestellung ord JOIN postfinancecheckout_transactions plugin WHERE ord.kBestellung = plugin.order_id ORDER BY ord.kBestellung DESC LIMIT ' . $pagination->getLimitSQL(), ReturnType::ARRAY_OF_OBJECTS);
		foreach ($orderArr as $order) {
			$orderId = (int)$order->kBestellung;
			$ordObj = new Bestellung($orderId);
			$ordObj->fuelleBestellung(true, 0, false);
			$ordObj->postfinancecheckout_transaction_id = $order->transaction_id;
			$ordObj->postfinancecheckout_state = $order->state;
			$ordObj->total_amount = (float)$order->fGesamtsumme;
			$orders[$orderId] = $ordObj;
		}
		
		$paymentStatus = PostFinanceCheckoutHelper::getPaymentStatusWithTransations($this->plugin->getLocalization());
		$translations = PostFinanceCheckoutHelper::getTranslations($this->plugin->getLocalization(), [
		  'jtl_postfinancecheckout_order_number',
		  'jtl_postfinancecheckout_customer',
		  'jtl_postfinancecheckout_payment_method',
		  'jtl_postfinancecheckout_order_status',
		  'jtl_postfinancecheckout_amount',
		  'jtl_postfinancecheckout_there_are_no_orders',
		]);
		
		return $this->smarty->assign('orders', $orders)
		  ->assign('translations', $translations)
		  ->assign('pagination', $pagination)
		  ->assign('pluginId', $this->plugin->getID())
		  ->assign('postUrl', Shop::getURL() . '/' . \PFAD_ADMIN . 'plugin.php?kPlugin=' . $this->plugin->getID())
		  ->assign('paymentStatus', $paymentStatus)
		  ->assign('hash', 'plugin-tab-' . $menuID)
		  ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/postfinancecheckout_orders.tpl');
	}
	
	private function displayOrderInfo(array $post, int $menuID): string
	{
		$translations = PostFinanceCheckoutHelper::getTranslations($this->plugin->getLocalization(), [
		  'jtl_postfinancecheckout_order_number',
		  'jtl_postfinancecheckout_transaction_id',
		  'jtl_postfinancecheckout_transaction_state',
		  'jtl_postfinancecheckout_transaction_no_possible_actions',
		  'jtl_postfinancecheckout_complete',
		  'jtl_postfinancecheckout_cancel',
		  'jtl_postfinancecheckout_refunds',
		  'jtl_postfinancecheckout_download_invoice',
		  'jtl_postfinancecheckout_download_packaging_slip',
		  'jtl_postfinancecheckout_make_refund',
		  'jtl_postfinancecheckout_amount_to_refund',
		  'jtl_postfinancecheckout_refund_now',
		  'jtl_postfinancecheckout_refunded_amount',
		  'jtl_postfinancecheckout_amount',
		  'jtl_postfinancecheckout_refund_date',
		  'jtl_postfinancecheckout_total',
		  'jtl_postfinancecheckout_no_refunds_info_text',
		]);
		
		$currency = Frontend::getCurrency();
		$refunds = $this->refundService->getRefunds($post['order_id']);
		$totalRefundsAmount = $this->refundService->getTotalRefundsAmount($refunds);
		$amountToBeRefunded = round(floatval($post['total_amount']) - $totalRefundsAmount, 2);
		
		$showRefundsForm = $post['transaction_state'] !== 'REFUNDED' && $amountToBeRefunded > 0;
		
		$smartyVar = $this->smarty->assign('adminUrl', $this->plugin->getPaths()->getadminURL())
		  ->assign('refunds', $refunds)
		  ->assign('totalAmount', $post['total_amount'])
		  ->assign('totalAmountText', Preise::getLocalizedPriceString($post['total_amount'], $currency, true))
		  ->assign('totalRefundsAmount', $totalRefundsAmount)
		  ->assign('totalRefundsAmountText', Preise::getLocalizedPriceString($totalRefundsAmount, $currency, true))
		  ->assign('amountToBeRefunded', $amountToBeRefunded)
		  ->assign('showRefundsForm', $showRefundsForm)
		  ->assign('orderNo', $post['order_no'])
		  ->assign('transactionId', $post['transaction_id'])
		  ->assign('transactionState', $post['transaction_state'])
		  ->assign('translations', $translations)
		  ->assign('menuId', '#plugin-tab-' . $menuID)
		  ->assign('postUrl', Shop::getURL() . '/' . \PFAD_ADMIN . 'plugin.php?kPlugin=' . $this->plugin->getID())
		  ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/postfinancecheckout_order_details.tpl');
		
		print $smartyVar;
		exit;
	}
	
	/**
	 * @param $transactionId
	 * @return void
	 */
	private function completeTransaction($transactionId): void
	{
		$transaction = $this->transactionService->getLocalPostFinanceCheckoutTransactionById($transactionId);
		if ($transaction->state === 'AUTHORIZED') {
			$this->transactionService->completePortalTransaction($transactionId);
		}
	}
	
	/**
	 * @param $transactionId
	 * @return void
	 */
	private function cancelTransaction($transactionId): void
	{
		$transaction = $this->transactionService->getLocalPostFinanceCheckoutTransactionById($transactionId);
		if ($transaction->state === 'AUTHORIZED') {
			$this->transactionService->cancelPortalTransaction($transactionId);
		}
	}
	
	/**
	 * @param $transactionId
	 * @return void
	 */
	private function downloadInvoice($transactionId): void
	{
		$transaction = $this->transactionService->getLocalPostFinanceCheckoutTransactionById($transactionId);
		if (\in_array(strtoupper($transaction->state), self::FILE_DOWNLOAD_ALLOWED_STATES)) {
			$this->transactionService->downloadInvoice($transactionId);
		}
	}
	
	/**
	 * @param $transactionId
	 * @return void
	 */
	private function downloadPackagingSlip($transactionId): void
	{
		$transaction = $this->transactionService->getLocalPostFinanceCheckoutTransactionById($transactionId);
		if (\in_array(strtoupper($transaction->state), self::FILE_DOWNLOAD_ALLOWED_STATES)) {
			$this->transactionService->downloadPackagingSlip($transactionId);
		}
	}
}
