<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Api;

use Magento\Sales\Api\Data\OrderItemInterface;

interface DiscountServiceInterface
{
    public function getDiscountAmountFromOrderItem(OrderItemInterface $salesOrderItem): float;
}