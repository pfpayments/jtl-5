<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks;

use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\Interfaces\PostFinanceCheckoutOrderUpdateStrategyInterface;

class PostFinanceCheckoutOrderUpdater
{
	/**
	 * @var PostFinanceCheckoutOrderUpdateStrategyInterface $strategy
	 */
	private $strategy;

	public function __construct(PostFinanceCheckoutOrderUpdateStrategyInterface $strategy)
	{
		$this->strategy = $strategy;
	}

	/**
	 * @param PostFinanceCheckoutOrderUpdateStrategyInterface $strategy
	 * @return void
	 */
	public function setStrategy(PostFinanceCheckoutOrderUpdateStrategyInterface $strategy)
	{
		$this->strategy = $strategy;
	}

	/**
	 * @param string $transactionId
	 * @return void
	 */
	public function updateOrderStatus(string $transactionId): void
	{
		$this->strategy->updateOrderStatus($transactionId);
	}
}
