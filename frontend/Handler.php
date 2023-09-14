<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\frontend;

use JTL\Checkout\Bestellung;
use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use PostFinanceCheckout\Sdk\ApiClient;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;

final class Handler
{
	/** @var PluginInterface */
	private $plugin;
	
	/** @var ApiClient|null */
	private $apiClient;
	
	/** @var DbInterface|null */
	private $db;
	
	/** @var PostFinanceCheckoutTransactionService */
	private $transactionService;
	
	/**
	 * Handler constructor.
	 * @param PluginInterface $plugin
	 * @param DbInterface|null $db
	 * @param ApiClient $apiClient
	 */
	public function __construct(PluginInterface $plugin, ApiClient $apiClient, ?DbInterface $db = null)
	{
		$this->plugin = $plugin;
		$this->apiClient = $apiClient;
		$this->db = $db ?? Shop::Container()->getDB();
		$this->transactionService = new PostFinanceCheckoutTransactionService($this->apiClient, $this->plugin);
	}
	
	/**
	 * @return string
	 */
	public function createTransaction(): string
	{
		$transactionId = $_SESSION['transactionId'] ?? null;
		if (!$transactionId) {
			$order = new Bestellung();
			$order->Positionen = $_SESSION['Warenkorb']->PositionenArr;
			$order->cBestellNr = rand(111111, 999999);
			
			$createdTransaction = $this->transactionService->createTransaction($order);
			$transactionId = $createdTransaction->getId();
			
			$_SESSION['transactionId'] = $transactionId;
		}
		
		return (string)$transactionId;
	}
	
	public function fetchPossiblePaymentMethods(string $transactionId)
	{
		return $this->transactionService->fetchPossiblePaymentMethods($transactionId);
	}
	
	public function getPaymentMethodsForForm(JTLSmarty $smarty): array
	{
		$createdTransactionId = $_SESSION['transactionId'] ?? null;
		$arrayOfPossibleMethods = $_SESSION['arrayOfPossibleMethods'] ?? null;
		$addressCheck = $_SESSION['addressCheck'] ?? null;
		$currencyCheck = $_SESSION['currencyCheck'] ?? null;
		
		if ($addressCheck !== md5(json_encode((array)$_SESSION['Lieferadresse'])) || $currencyCheck !== $_SESSION['cWaehrungName']) {
			$arrayOfPossibleMethods = null;
			if ($createdTransactionId) {
				$this->transactionService->updateTransaction($createdTransactionId);
			}
			$_SESSION['addressCheck'] = md5(json_encode((array)$_SESSION['Lieferadresse']));
			$_SESSION['currencyCheck'] = $_SESSION['cWaehrungName'];
		}
		
		if (!$createdTransactionId || !$arrayOfPossibleMethods) {
			if (!$createdTransactionId) {
				$createdTransactionId = $this->createTransaction();
			}
			$possiblePaymentMethods = $this->fetchPossiblePaymentMethods((string)$createdTransactionId);
			$arrayOfPossibleMethods = [];
			foreach ($possiblePaymentMethods as $possiblePaymentMethod) {
				$arrayOfPossibleMethods[] = PostFinanceCheckoutHelper::slugify($possiblePaymentMethod->getName(), '-');
			}
			$_SESSION['arrayOfPossibleMethods'] = $arrayOfPossibleMethods;
		}
		
		$paymentMethods = $smarty->getTemplateVars('Zahlungsarten');
		foreach ($paymentMethods as $key => $paymentMethod) {
			if (empty($paymentMethod->cAnbieter) || strtolower($paymentMethod->cAnbieter) !== 'postfinancecheckout') {
				continue;
			}
			$slug = PostFinanceCheckoutHelper::slugify($paymentMethod->cName, '-');
			if (!\in_array($slug, $arrayOfPossibleMethods, true)) {
				unset($paymentMethods[$key]);
			}
		}
		return $paymentMethods;
	}
	
	/**
	 * @param string $spaceId
	 * @param int $transactionId
	 * @return void
	 */
	public function confirmTransaction(string $spaceId, int $transactionId): void
	{
		$transaction = $this->apiClient->getTransactionService()->read($spaceId, $transactionId);
		$this->transactionService->confirmTransaction($transaction);
	}

	public function getRedirectUrlAfterCreatedTransaction($orderData): string
	{
		$config = PostFinanceCheckoutHelper::getConfigByID($this->plugin->getId());
		$spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID];
		
		$createdTransactionId = $_SESSION['transactionId'] ?? null;
		
		if (empty($createdTransactionId)) {
			$failedUrl = Shop::getURL() . '/' . PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['fail-page'][$_SESSION['cISOSprache']];
			header("Location: " . $failedUrl);
			exit;
		}
		$this->confirmTransaction($spaceId, $createdTransactionId);
		
		// TODO create setting with options ['payment_page', 'iframe'];
		$integration = 'iframe';
		$_SESSION['transactionID'] = $createdTransactionId;
		
		if ($integration == 'payment_page') {
			$redirectUrl = $this->apiClient->getTransactionPaymentPageService()
			  ->paymentPageUrl($spaceId, $createdTransactionId);
			
			return $redirectUrl;
		}
		
		$_SESSION['javascriptUrl'] = $this->apiClient->getTransactionIframeService()
		  ->javascriptUrl($spaceId, $createdTransactionId);
		$_SESSION['appJsUrl'] = $this->plugin->getPaths()->getBaseURL() . 'frontend/js/postfinancecheckout-app.js';
		
		$paymentMethod = $this->transactionService->getTransactionPaymentMethod($createdTransactionId, $spaceId);
		if (empty($paymentMethod)) {
			$failedUrl = Shop::getURL() . '/' . PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['fail-page'][$_SESSION['cISOSprache']];
			header("Location: " . $failedUrl);
			exit;
		}
		
		$_SESSION['possiblePaymentMethodId'] = $paymentMethod->getId();
		$_SESSION['possiblePaymentMethodName'] = $paymentMethod->getName();
		$_SESSION['orderData'] = $orderData;
		
		return PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['payment-page'][$_SESSION['cISOSprache']];
	}
	
	public function contentUpdate(array $args): void
	{
		if (Shop::getPageType() === \PAGE_BESTELLVORGANG) {
			$this->setPaymentMethodLogoSize();
		}
	}
	
	public function setPaymentMethodLogoSize(): void
	{
		global $step;
		
		if (in_array($step, ['Zahlung', 'Versand'])) {
			$paymentMethodsCss = '<link rel="stylesheet" href="' . $this->plugin->getPaths()->getBaseURL() . 'frontend/css/checkout-payment-methods.css">';
			pq('head')->append($paymentMethodsCss);
		}
	}
	
}
