<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks;

use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\Interfaces\WhiteLabelMachineOrderUpdateStrategyInterface;

class PostFinanceCheckoutOrderUpdater
{
	/**
	 * @var WhiteLabelMachineOrderUpdateStrategyInterface $strategy
	 */
	private $strategy;
	
	public function __construct(WhiteLabelMachineOrderUpdateStrategyInterface $strategy)
	{
		$this->strategy = $strategy;
	}
	
	/**
	 * @param WhiteLabelMachineOrderUpdateStrategyInterface $strategy
	 * @return void
	 */
	public function setStrategy(WhiteLabelMachineOrderUpdateStrategyInterface $strategy)
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
