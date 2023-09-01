<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Services;

use JTL\Shop;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use PostFinanceCheckout\Sdk\{Model\CreationEntityState,
  Model\CriteriaOperator,
  Model\EntityQuery,
  Model\EntityQueryFilter,
  Model\EntityQueryFilterType,
  Model\RefundState,
  Model\TransactionInvoiceState,
  Model\TransactionState,
  Model\WebhookListener,
  Model\WebhookListenerCreate,
  Model\WebhookUrl,
  Model\WebhookUrlCreate
};
use PostFinanceCheckout\Sdk\ApiClient;

class PostFinanceCheckoutWebhookService
{
	/**
	 * WebHook configs
	 */
	protected $webHookEntitiesConfig = [];
	
	/**
	 * WebHook configs
	 */
	protected $webHookEntityArrayConfig = [
		/**
		 * Transaction WebHook Entity Id
		 *
		 * @link https://postfinance.ch/en/business/products/e-commerce/postfinance-checkout-all-in-one.html/doc/api/webhook-entity/view/1472041829003
		 */
	  [
		'id' => '1472041829003',
		'name' => 'Jtl5::WebHook::Transaction',
		'states' => [
		  TransactionState::AUTHORIZED,
		  TransactionState::COMPLETED,
		  TransactionState::CONFIRMED,
		  TransactionState::DECLINE,
		  TransactionState::FAILED,
		  TransactionState::FULFILL,
		  TransactionState::PROCESSING,
		  TransactionState::VOIDED,
		],
		'notifyEveryChange' => false,
	  ],
		/**
		 * Transaction Invoice WebHook Entity Id
		 *
		 * @link https://postfinance.ch/en/business/products/e-commerce/postfinance-checkout-all-in-one.html/doc/api/webhook-entity/view/1472041816898
		 */
	  [
		'id' => '1472041816898',
		'name' => 'Jtl5::WebHook::Transaction Invoice',
		'states' => [
		  TransactionInvoiceState::NOT_APPLICABLE,
		  TransactionInvoiceState::PAID,
		  TransactionInvoiceState::DERECOGNIZED,
		],
		'notifyEveryChange' => false,
	  ],
		/**
		 * Refund WebHook Entity Id
		 *
		 * @link https://postfinance.ch/en/business/products/e-commerce/postfinance-checkout-all-in-one.html/doc/api/webhook-entity/view/1472041839405
		 */
	  [
		'id' => '1472041839405',
		'name' => 'Jtl5::WebHook::Refund',
		'states' => [
		  RefundState::FAILED,
		  RefundState::SUCCESSFUL,
		],
		'notifyEveryChange' => false,
	  ],
		/**
		 * Payment Method Configuration Id
		 *
		 * @link https://postfinance.ch/en/business/products/e-commerce/postfinance-checkout-all-in-one.html/doc/api/webhook-entity/view/1472041857405
		 */
	  [
		'id' => '1472041857405',
		'name' => 'Jtl5::WebHook::Payment Method Configuration',
		'states' => [
		  CreationEntityState::ACTIVE,
		  CreationEntityState::DELETED,
		  CreationEntityState::DELETING,
		  CreationEntityState::INACTIVE
		],
		'notifyEveryChange' => true,
	  ],
	
	];
	
	/**
	 * @var ApiClient $apiClient
	 */
	protected ApiClient $apiClient;
	
	/**
	 * @var $spaceId
	 */
	protected $spaceId;
	
	public function __construct($apiClient, $pluginId)
	{
		$this->apiClient = $apiClient;
		
		$config = PostFinanceCheckoutHelper::getConfigByID($pluginId);
		$spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID];
		$this->spaceId = $spaceId;
		
		$this->setWebHookEntitiesConfig();
	}
	
	/**
	 * Set webhook configs
	 */
	protected function setWebHookEntitiesConfig(): void
	{
		foreach ($this->webHookEntityArrayConfig as $item) {
			$this->webHookEntitiesConfig[] = [
			  "id" => $item['id'],
			  "name" => $item['name'],
			  "states" => $item['states'],
			  "notifyEveryChange" => $item['notifyEveryChange']
			];
		}
	}
	
	/**
	 * Install WebHooks
	 *
	 * @return array
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	public function install(): array
	{
		return $this->installListeners();
	}
	
	/**
	 * Install Listeners
	 *
	 * @return array
	 */
	protected function installListeners(): array
	{
		$returnValue = [];
		try {
			$webHookUrlId = $this->getOrCreateWebHookUrl()->getId();
			$installedWebHooks = $this->getInstalledWebHookListeners($webHookUrlId);
			$webHookEntityIds = array_map(function (WebhookListener $webHook) {
				return $webHook->getEntity();
			}, $installedWebHooks);
			
			foreach ($this->webHookEntitiesConfig as $data) {
				
				if (in_array($data['id'], $webHookEntityIds)) {
					continue;
				}
				
				$entity = (new WebhookListenerCreate())
				  ->setName($data['name'])
				  ->setEntity($data['id'])
				  ->setNotifyEveryChange($data['notifyEveryChange'])
				  ->setState(CreationEntityState::CREATE)
				  ->setEntityStates($data['states'])
				  ->setUrl($webHookUrlId);
				
				$returnValue[] = $this->apiClient->getWebhookListenerService()->create($this->spaceId, $entity);
			}
		} catch (\Exception $exception) {
			return [];
		}
		
		return $returnValue;
	}
	
	/**
	 * Create WebHook URL
	 *
	 * @return WebhookUrl
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	protected function getOrCreateWebHookUrl()
	{
		$webHookUrl = $this->getWebhookUrl();
		
		if (!empty($webHookUrl[0])) {
			return $webHookUrl[0];
		}
		
		$webHookUrl = $this->createWebhookUrl();
		
		return current($webHookUrl);
	}
	
	protected function createWebhookUrl(): array
	{
		/** @noinspection PhpParamsInspection */
		$entity = (new WebhookUrlCreate())
		  ->setName('Jtl5::WebHookURL')
		  ->setUrl($this->getWebHookCallBackUrl())
		  ->setState(CreationEntityState::ACTIVE);
		
		$this->apiClient->getWebhookUrlService()->create($this->spaceId, $entity);
		
		return $this->getWebhookUrl();
	}
	
	protected function getWebhookUrl(): array
	{
		/** @noinspection PhpParamsInspection */
		$entityQueryFilter = (new EntityQueryFilter())
		  ->setType(EntityQueryFilterType::_AND)
		  ->setChildren([
			$this->getEntityFilter('state', CreationEntityState::ACTIVE),
			$this->getEntityFilter('url', $this->getWebHookCallBackUrl()),
		  ]);
		
		$query = (new EntityQuery())->setFilter($entityQueryFilter)->setNumberOfEntities(1);
		
		return $this->apiClient->getWebhookUrlService()->search($this->spaceId, $query);
	}
	
	/**
	 * Creates and returns a new entity filter.
	 *
	 * @param string $fieldName
	 * @param        $value
	 * @param string $operator
	 *
	 * @return \PostFinanceCheckout\Sdk\Model\EntityQueryFilter
	 */
	protected function getEntityFilter(string $fieldName, $value, string $operator = CriteriaOperator::EQUALS): EntityQueryFilter
	{
		/** @noinspection PhpParamsInspection */
		return (new EntityQueryFilter())
		  ->setType(EntityQueryFilterType::LEAF)
		  ->setOperator($operator)
		  ->setFieldName($fieldName)
		  ->setValue($value);
	}
	
	/**
	 * Get web hook callback url
	 *
	 * @return string
	 */
	protected function getWebHookCallBackUrl(): string
	{
		return Shop::getURL() . '/postfinancecheckout-webhook';
	}
	
	/**
	 * @param int $webHookUrlId
	 *
	 * @return array
	 * @throws \PostFinanceCheckout\Sdk\ApiException
	 * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
	 * @throws \PostFinanceCheckout\Sdk\VersioningException
	 */
	protected function getInstalledWebHookListeners(int $webHookUrlId): array
	{
		/** @noinspection PhpParamsInspection */
		$entityQueryFilter = (new EntityQueryFilter())
		  ->setType(EntityQueryFilterType::_AND)
		  ->setChildren([
			$this->getEntityFilter('state', CreationEntityState::ACTIVE),
			$this->getEntityFilter('url.id', $webHookUrlId),
		  ]);
		
		$query = (new EntityQuery())->setFilter($entityQueryFilter);
		
		return $this->apiClient->getWebhookListenerService()->search($this->spaceId, $query);
	}
}
