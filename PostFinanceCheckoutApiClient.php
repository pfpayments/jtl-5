<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout;

use JTL\Shop;
use PostFinanceCheckout\Sdk\ApiClient;
use JTL\Plugin\Helper as PluginHelper;

/**
 * Class PostFinanceCheckoutApiClient
 * @package Plugin\jtl_postfinancecheckout
 */
class PostFinanceCheckoutApiClient
{
	/**
	 * @var ApiClient $apiClient
	 */
	protected $apiClient;
	
	
	const SHOP_SYSTEM = 'x-meta-shop-system';
	const SHOP_SYSTEM_VERSION = 'x-meta-shop-system-version';
	const SHOP_SYSTEM_AND_VERSION = 'x-meta-shop-system-and-version';
	
	public function __construct(int $pluginId)
	{
		if (!$this->getApiClient()) {
			$config = PostFinanceCheckoutHelper::getConfigByID($pluginId);
			$userId = $config[PostFinanceCheckoutHelper::USER_ID] ?? null;
			$applicationKey = $config[PostFinanceCheckoutHelper::APPLICATION_KEY] ?? null;
			$plugin = PluginHelper::getLoaderByPluginID($pluginId)->init($pluginId);
			
			if (empty($userId) || empty($applicationKey)) {
				if (isset($_POST['Setting'])) {
					$translations = PostFinanceCheckoutHelper::getTranslations($plugin->getLocalization(), [
					  'jtl_postfinancecheckout_incorrect_user_id_or_application_key',
					]);
					Shop::Container()->getAlertService()->addDanger(
					  $translations['jtl_postfinancecheckout_incorrect_user_id_or_application_key'],
					  'getApiClient'
					);
				}
				return null;
			}
			
			try {
				$apiClient = new ApiClient($userId, $applicationKey);
				$apiClientBasePath = getenv('POSTFINANCECHECKOUT_API_BASE_PATH') ? getenv('POSTFINANCECHECKOUT_API_BASE_PATH') : $apiClient->getBasePath();
				$apiClient->setBasePath($apiClientBasePath);
				foreach (self::getDefaultHeaderData() as $key => $value) {
					$apiClient->addDefaultHeader($key, $value);
				}
				$this->apiClient = $apiClient;
			} catch (\Exception $exception) {
				$translations = PostFinanceCheckoutHelper::getTranslations($plugin->getLocalization(), [
				  'jtl_postfinancecheckout_incorrect_user_id_or_application_key',
				]);
				Shop::Container()->getAlertService()->addDanger(
				  $translations['jtl_postfinancecheckout_incorrect_user_id_or_application_key'],
				  'getApiClient'
				);
				return null;
			}
		}
	}
	
	/**
	 * @return array
	 */
	protected static function getDefaultHeaderData(): array
	{
		$shop_version = APPLICATION_VERSION;
		[$major_version, $minor_version, $_] = explode('.', $shop_version, 3);
		return [
		  self::SHOP_SYSTEM => 'jtl',
		  self::SHOP_SYSTEM_VERSION => $shop_version,
		  self::SHOP_SYSTEM_AND_VERSION => 'jtl-' . $major_version . '.' . $minor_version,
		];
	}
	
	/**
	 * @return ApiClient|null
	 */
	public function getApiClient(): ?ApiClient
	{
		return $this->apiClient;
	}
}

