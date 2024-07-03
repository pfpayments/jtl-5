<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Services;

use JTL\Shop;
use PostFinanceCheckout\Sdk\ApiClient;

class PostFinanceCheckoutOrderService
{
	public function updateOrderStatus($orderId, $currentStatus, $newStatus)
	{
		return Shop::Container()
		    ->getDB()->update(
			    'tbestellung',
			    ['kBestellung', 'cStatus'],
			    [$orderId, $currentStatus],
			    (object)['cStatus' => $newStatus]
		    );
	}
}
