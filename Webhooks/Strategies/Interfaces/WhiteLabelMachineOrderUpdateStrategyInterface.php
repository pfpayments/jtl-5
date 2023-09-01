<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks\Strategies\Interfaces;

interface WhiteLabelMachineOrderUpdateStrategyInterface
{
	public function updateOrderStatus(string $entityId): void;
}
