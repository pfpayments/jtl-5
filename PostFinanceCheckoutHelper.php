<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout;

use JTL\Plugin\Helper;
use JTL\Shop;
use JTL\Plugin\Data\Localization;
use PostFinanceCheckout\Sdk\ApiClient;
use JTL\Plugin\Helper as PluginHelper;

/**
 * Class PostFinanceCheckoutHelper
 * @package Plugin\jtl_postfinancecheckout
 */
class PostFinanceCheckoutHelper extends Helper
{
	const USER_ID = 'jtl_postfinancecheckout_user_id';
	const SPACE_ID = 'jtl_postfinancecheckout_space_id';
	const APPLICATION_KEY = 'jtl_postfinancecheckout_application_key';
	const SPACE_VIEW_ID = 'jtl_postfinancecheckout_space_view_id';
	const SEND_CONFIRMATION_EMAIL = 'jtl_postfinancecheckout_send_confirmation_email';
	
	const PAYMENT_METHOD_CONFIGURATION = 'PaymentMethodConfiguration';
	const REFUND = 'Refund';
	const TRANSACTION = 'Transaction';
	const TRANSACTION_INVOICE = 'TransactionInvoice';
	
	const PLUGIN_CUSTOM_PAGES = [
	  'thank-you-page' => [
		'ger' => 'postfinancecheckout-danke-seite',
		'eng' => 'postfinancecheckout-thank-you-page'
	  ],
	  'payment-page' => [
		'ger' => 'postfinancecheckout-zahlungsseite',
		'eng' => 'postfinancecheckout-payment-page'
	  ],
	  'fail-page' => [
		'ger' => 'postfinancecheckout-bezahlung-fehlgeschlagen',
		'eng' => 'postfinancecheckout-failed-payment'
	  ],
	];
	
	
	/**
	 * @param string $text
	 * @param string $divider
	 * @return string
	 */
	public static function slugify(string $text, string $divider = '_'): string
	{
		// replace non letter or digits by divider
		$text = preg_replace('~[^\pL\d]+~u', $divider, $text);
		
		// transliterate
		$text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
		
		// remove unwanted characters
		$text = preg_replace('~[^-\w]+~', '', $text);
		
		// trim
		$text = trim($text, $divider);
		
		// remove duplicate divider
		$text = preg_replace('~-+~', $divider, $text);
		
		// lowercase
		$text = strtolower($text);
		
		return $text;
	}
	
	/**
	 * @return string
	 */
	public static function getLanguageString(): string
	{
		switch ($_SESSION['currentLanguage']->localizedName) {
			case 'de':
				return 'de_DE';
			
			case 'fr':
				return 'fr_FR';
			
			case 'it':
				return 'it_IT';
			
			default:
				return 'en_GB';
		}
	}
	
	/**
	 * @return string
	 */
	public static function getLanguageIso(): string
	{
		$gettext = Shop::Container()->getGetText();
		$langTag = $_SESSION['AdminAccount']->language ?? $gettext->getLanguage();
		
		switch (substr($langTag, 0, 2)) {
			case 'de':
				return 'ger';
			
			case 'en':
				return 'eng';
			
			case 'fr':
				return 'fra';
			
			case 'it':
				return 'ita';
		}
	}
	
	/**
	 * @param Localization $localization
	 * @param array $keys
	 * @return array
	 */
	public static function getTranslations(Localization $localization, array $keys): array
	{
		$translations = [];
		foreach ($keys as $key) {
			$translations[$key] = $localization->getTranslation($key, self::getLanguageIso());
		}
		
		return $translations;
	}
	
	/**
	 * @param Localization $localization
	 * @return array
	 */
	public static function getPaymentStatusWithTransations(Localization $localization): array
	{
		$translations = PostFinanceCheckoutHelper::getTranslations($localization, [
		  'jtl_postfinancecheckout_order_status_cancelled',
		  'jtl_postfinancecheckout_order_status_open',
		  'jtl_postfinancecheckout_order_status_in_processing',
		  'jtl_postfinancecheckout_order_status_paid',
		  'jtl_postfinancecheckout_order_status_shipped',
		  'jtl_postfinancecheckout_order_status_partially_shipped',
		]);
		
		return [
		  '-1' => $translations['jtl_postfinancecheckout_order_status_cancelled'],
		  '1' => $translations['jtl_postfinancecheckout_order_status_open'],
		  '2' => $translations['jtl_postfinancecheckout_order_status_in_processing'],
		  '3' => $translations['jtl_postfinancecheckout_order_status_paid'],
		  '4' => $translations['jtl_postfinancecheckout_order_status_shipped'],
		  '5' => $translations['jtl_postfinancecheckout_order_status_partially_shipped'],
		];
	}
	
	/**
	 * @param int $pluginId
	 * @return ApiClient|null
	 */
	public static function getApiClient(int $pluginId): ?ApiClient
	{
		if (class_exists('PostFinanceCheckout\Sdk\ApiClient')) {
			$apiClient = new PostFinanceCheckoutApiClient($pluginId);
			return $apiClient->getApiClient();
		} else {
			
			if (isset($_POST['Setting'])) {
				$plugin = PluginHelper::getLoaderByPluginID($pluginId)->init($pluginId);
				$translations = PostFinanceCheckoutHelper::getTranslations($plugin->getLocalization(), [
				  'jtl_postfinancecheckout_need_to_install_sdk',
				]);
				Shop::Container()->getAlertService()->addDanger(
				  $translations['jtl_postfinancecheckout_need_to_install_sdk'],
				  'getApiClient'
				);
			}
			return null;
		}
	}
}

