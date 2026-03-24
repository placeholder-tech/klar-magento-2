<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model;

use PlaceholderTech\Klar\Api\DiscountServiceInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class DiscountService implements DiscountServiceInterface
{   
    private $customDiscountColumns = [
        'aw_afptc_amount'
    ];

    public function getDiscountAmountFromOrderItem(OrderItemInterface $salesOrderItem): float
    {
        $discount = (float) $salesOrderItem->getDiscountAmount();

        foreach ($this->customDiscountColumns as $discountColumn) {
            if ($salesOrderItem->getData($discountColumn) && $salesOrderItem->getData($discountColumn) > 0) {
                $discount += (float) $salesOrderItem->getData($discountColumn);
            }
        }

        return $discount;
    }
}