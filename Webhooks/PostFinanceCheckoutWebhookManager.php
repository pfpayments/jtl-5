<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks;

use JTL\Plugin\Plugin;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutOrderService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutPaymentService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutRefundService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\WhiteLabelMachineOrderUpdateRefundStrategy;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\WhiteLabelMachineOrderUpdateTransactionInvoiceStrategy;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\WhiteLabelMachineOrderUpdateTransactionStrategy;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutApiClient;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\{TransactionInvoiceState, TransactionState};

/**
 * Class PostFinanceCheckoutWebhookManager
 * @package Plugin\jtl_postfinancecheckout
 */
class PostFinanceCheckoutWebhookManager
{
	/**
	 * @var array $data
	 */
	protected $data;
	
	/**
	 * @var ApiClient $apiClient
	 */
	protected ApiClient $apiClient;
	
	/**
	 * @var Plugin $plugin
	 */
	protected $plugin;
	
	/**
	 * @var PostFinanceCheckoutTransactionService $transactionService
	 */
	protected $transactionService;
	
	/**
	 * @var PostFinanceCheckoutRefundService $refundService
	 */
	protected $refundService;
	
	/**
	 * @var PostFinanceCheckoutOrderService $orderService
	 */
	protected $orderService;
	
	public function __construct(Plugin $plugin)
	{
		$this->plugin = $plugin;
		$this->data = json_decode(file_get_contents('php://input'), true);
		$this->apiClient = (new PostFinanceCheckoutApiClient($plugin->getId()))->getApiClient();
		$this->transactionService = new PostFinanceCheckoutTransactionService($this->apiClient, $this->plugin);
		$this->refundService = new PostFinanceCheckoutRefundService($this->apiClient, $this->plugin);
	}
	
	public function listenForWebhooks(): void
	{
		$listenerEntityTechnicalName = $this->data['listenerEntityTechnicalName'] ?? null;
		if (!$listenerEntityTechnicalName) {
			return;
		}
		
		$orderUpdater = new PostFinanceCheckoutOrderUpdater(new WhiteLabelMachineOrderUpdateTransactionStrategy($this->transactionService, $this->plugin));
		$entityId = (string)$this->data['entityId'];
		
		switch ($listenerEntityTechnicalName) {
			case PostFinanceCheckoutHelper::TRANSACTION:
				$orderUpdater->updateOrderStatus($entityId);
				break;
			
			case PostFinanceCheckoutHelper::TRANSACTION_INVOICE:
				$orderUpdater->setStrategy(new WhiteLabelMachineOrderUpdateTransactionInvoiceStrategy($this->transactionService));
				$orderUpdater->updateOrderStatus($entityId);
				break;
			
			case PostFinanceCheckoutHelper::REFUND:
				$orderUpdater->setStrategy(new WhiteLabelMachineOrderUpdateRefundStrategy($this->refundService, $this->transactionService));
				$orderUpdater->updateOrderStatus($entityId);
				break;
			
			case PostFinanceCheckoutHelper::PAYMENT_METHOD_CONFIGURATION:
				$paymentService = new PostFinanceCheckoutPaymentService($this->apiClient, $this->plugin->getId());
				$paymentService->syncPaymentMethods();
				break;
		}
	}
	
}

