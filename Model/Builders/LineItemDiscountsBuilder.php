<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Builders;

use PlaceholderTech\Klar\Api\Data\DiscountInterface;
use PlaceholderTech\Klar\Api\Data\DiscountInterfaceFactory;
use PlaceholderTech\Klar\Model\AbstractApiRequestParamsBuilder;
use PlaceholderTech\Klar\Api\DiscountServiceInterface;
use Magento\Bundle\Model\Product\Type as BundleProductType;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderItemInterface as SalesOrderItemInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Model\RuleFactory;

class LineItemDiscountsBuilder extends AbstractApiRequestParamsBuilder
{
    private DiscountInterfaceFactory $discountFactory;
    private DiscountServiceInterface $discountService;
    private RuleRepositoryInterface $salesRuleRepository;
    private RuleFactory $ruleFactory;

    /**
     * LineItemDiscountsBuilder constructor.
     *
     * @param DateTimeFactory $dateTimeFactory
     * @param DiscountInterfaceFactory $discountFactory
     * @param DiscountServiceInterface $discountService
     * @param RuleRepositoryInterface $salesRuleRepository
     * @param RuleFactory $ruleFactory
     */
    public function __construct(
        DateTimeFactory $dateTimeFactory,
        DiscountInterfaceFactory $discountFactory,
        DiscountServiceInterface $discountService,
        RuleRepositoryInterface $salesRuleRepository,
        RuleFactory $ruleFactory
    ) {
        parent::__construct($dateTimeFactory);
        $this->discountFactory = $discountFactory;
        $this->discountService = $discountService;
        $this->salesRuleRepository = $salesRuleRepository;
        $this->ruleFactory = $ruleFactory;
    }

    /**
     * Build line item discounts array from sales order item.
     *
     * @param SalesOrderItemInterface $salesOrderItem
     *
     * @return array
     */
    public function buildFromSalesOrderItem(SalesOrderItemInterface $salesOrderItem): array
    {
        $discounts = [];
        $discountAmount = $this->discountService->getDiscountAmountFromOrderItem($salesOrderItem);
        $discountLeft = $discountAmount;
        $qtyOrdered = $salesOrderItem->getQtyOrdered() ? (int) $salesOrderItem->getQtyOrdered() : 0;

        if (($discountAmount || $this->isBundle($salesOrderItem))
            && $salesOrderItem->getAppliedRuleIds()) {
            $ruleIds = explode(',', $salesOrderItem->getAppliedRuleIds());

            foreach ($ruleIds as $ruleId) {
                $discount = $this->buildRuleDiscount(
                    (int)$ruleId,
                    (float)$salesOrderItem->getPriceInclTax()
                );

                if (!empty($discount)) {
                    if ($this->isBundle($salesOrderItem)) {
                        // Use zero discount for Bundles because we don't know actual discount amount for every rule
                        $discount['discountAmount'] = 0;
                    }

                    $discounts[] = $discount;
                    if (isset($discount['discountAmount'])) {
                        $discountLeft -= $qtyOrdered * $discount['discountAmount'];
                    }
                }
            }
        }

        if ($this->isBundle($salesOrderItem)) {
            return $discounts;
        }

        if (round($discountLeft,2) > 0.02) {
            $discounts[] = $this->buildOtherDiscount($discountLeft / $qtyOrdered);
        }

        $calculatedDiscounts = $this->sumCalculatedDiscounts($discounts, $qtyOrdered);
        if ($calculatedDiscounts - $discountAmount > 0.02) { // case when calculated discount is bigger than actual
            $discounts = $this->rebuildDiscountsBasedOnFlatData($discounts, $discountAmount, $qtyOrdered);
        }

        $price = round((float)$salesOrderItem->getPriceInclTax(),2);
        $originalPrice = round((float)$salesOrderItem->getOriginalPrice(),2);

        if ($price < $originalPrice) {
            $discounts[] = $this->buildSpecialPriceDiscount($price, $originalPrice);
        }

        return $discounts;
    }

    /**
     * Build discount array from sales rule.
     *
     * @param int $ruleId
     * @param float $baseItemPrice
     *
     * @return array
     */
    private function buildRuleDiscount(int $ruleId, float $baseItemPrice): array
    {
        try {
            $salesRule = $this->salesRuleRepository->getById($ruleId);
        } catch (NoSuchEntityException|LocalizedException $e) {
            // Rule doesn't exist, manual calculation is not possible.
            return [];
        }

        if (!(float)$salesRule->getDiscountAmount()) {
            return [];
        }

        /* @var DiscountInterface $discount */
        $discount = $this->discountFactory->create();

        $discount->setTitle($salesRule->getName());
        $discount->setDescriptor($salesRule->getDescription());

        if ($salesRule->getCouponType() === RuleInterface::COUPON_TYPE_SPECIFIC_COUPON) {
            $couponCode = $this->ruleFactory->create()->load($ruleId)->getCouponCode();

            $discount->setIsVoucher(true);
            $discount->setVoucherCode($couponCode);
        }

        if ($salesRule->getSimpleAction() === RuleInterface::DISCOUNT_ACTION_BY_PERCENT) {
            $discountPercent = $salesRule->getDiscountAmount() / 100;
            $discount->setDiscountAmount($baseItemPrice * $discountPercent);
        } elseif ($salesRule->getSimpleAction() === RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT) {
            $discount->setDiscountAmount((float)$salesRule->getDiscountAmount());
        } else {
            return []; // Disallow other action types
        }

        return $this->snakeToCamel($discount->toArray());
    }

    /**
     * Build discount array if there is some discount left after calculating them from core magento rules
     *
     * @param float $discountLeft
     *
     * @return array
     */
    private function buildOtherDiscount(float $discountLeft): array
    {
        /* @var DiscountInterface $discount */
        $discount = $this->discountFactory->create();

        $discount->setTitle(DiscountInterface::OTHER_DISCOUNT_TITLE);
        $discount->setDescriptor(DiscountInterface::OTHER_DISCOUNT_DESCRIPTOR);
        $discount->setDiscountAmount($discountLeft);

        return $this->snakeToCamel($discount->toArray());
    }

    /**
     * Build discount array for special price.
     *
     * @param float $price
     * @param float $originalPrice
     *
     * @return array
     */
    private function buildSpecialPriceDiscount(float $price, float $originalPrice): array
    {
        /* @var DiscountInterface $discount */
        $discount = $this->discountFactory->create();

        $discount->setTitle(DiscountInterface::SPECIAL_PRICE_DISCOUNT_TITLE);
        $discount->setDescriptor(DiscountInterface::SPECIAL_PRICE_DISCOUNT_DESCRIPTOR);
        $discount->setDiscountAmount($originalPrice - $price);

        return $this->snakeToCamel($discount->toArray());
    }

    private function sumCalculatedDiscounts(array $discounts, int $qty): float
    {
        $calculatedDiscounts = 0.00;
        foreach ($discounts as $discount) {
            $calculatedDiscounts += isset($discount['discountAmount']) ? $discount['discountAmount'] * $qty : 0.00;
        }

        return round($calculatedDiscounts, 2);
    }

    private function rebuildDiscountsBasedOnFlatData(array $discounts, float $discountAmount, int $qty): array
    {
        $newDiscounts = [];
        foreach ($discounts as $discount) {
            if (abs($discountAmount - ($discount['discountAmount'] * $qty)) < 0.02) {
                $newDiscounts[] = $discount;
                break;
            }
        }

        if (empty($newDiscounts)) {
            $newDiscounts[] = $this->buildOtherDiscount(round($discountAmount/$qty, 2));
        }

        return $newDiscounts;
    }

    /**
     * Check if product is Bundle
     *
     * @param SalesOrderItemInterface $salesOrderItem
     * @return bool
     */
    private function isBundle(SalesOrderItemInterface $salesOrderItem): bool
    {
        return $salesOrderItem->getProductType() === BundleProductType::TYPE_CODE;
    }

    /**
     * Build discounts for Bundle products
     *
     * @param array $lineItem
     * @return array
     */
    public function buildBundleDiscount(array $lineItem): array
    {
        $overallDiscount = $this->discountFactory->create();

        $overallDiscount->setTitle(DiscountInterface::BUNDLE_DISCOUNT_TITLE);
        $overallDiscount->setDescriptor(DiscountInterface::BUNDLE_DISCOUNT_DESCRIPTOR);

        $amount = 0;
        foreach ($lineItem['bundledProducts'] as $bundledProduct) {
            foreach ($bundledProduct['discounts'] as $discount) {
                $amount += $discount['discountAmount'];
            }
        }

        $overallDiscount->setDiscountAmount($amount);

        return array_merge(
            [$this->snakeToCamel($overallDiscount->toArray())],
            $lineItem['discounts']
        );
    }
}
