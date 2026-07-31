<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Builders;

use PlaceholderTech\Klar\Api\Data\DiscountInterface;
use PlaceholderTech\Klar\Api\Data\DiscountInterfaceFactory;
use PlaceholderTech\Klar\Model\AbstractApiRequestParamsBuilder;
use PlaceholderTech\Klar\Api\DiscountServiceInterface;
use PlaceholderTech\Klar\Helper\Config;
use Magento\Bundle\Model\Product\Type as BundleProductType;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderInterface as SalesOrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface as SalesOrderItemInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\RuleFactory;

class LineItemDiscountsBuilder extends AbstractApiRequestParamsBuilder
{
    private const AMPROMO_ACTION_PREFIX = 'ampromo_';

    private DiscountInterfaceFactory $discountFactory;
    private DiscountServiceInterface $discountService;
    private RuleRepositoryInterface $salesRuleRepository;
    private RuleFactory $ruleFactory;
    private Config $config;
    private ?CouponFactory $couponFactory;

    /**
     * Coupon code of the sales order currently being built (set per call
     * by buildFromSalesOrderItem when the order is passed in).
     */
    private ?string $orderCouponCode = null;

    /**
     * Memoized coupon-code -> rule-id lookups (one DB query per distinct
     * code per process; queue consumers process orders in batches).
     *
     * @var array<string, int|null>
     */
    private array $couponRuleIdCache = [];

    /**
     * LineItemDiscountsBuilder constructor.
     *
     * @param DateTimeFactory $dateTimeFactory
     * @param DiscountInterfaceFactory $discountFactory
     * @param DiscountServiceInterface $discountService
     * @param RuleRepositoryInterface $salesRuleRepository
     * @param RuleFactory $ruleFactory
     * @param Config $config
     * @param CouponFactory|null $couponFactory
     */
    public function __construct(
        DateTimeFactory $dateTimeFactory,
        DiscountInterfaceFactory $discountFactory,
        DiscountServiceInterface $discountService,
        RuleRepositoryInterface $salesRuleRepository,
        RuleFactory $ruleFactory,
        Config $config,
        ?CouponFactory $couponFactory = null
    ) {
        parent::__construct($dateTimeFactory);
        $this->discountFactory = $discountFactory;
        $this->discountService = $discountService;
        $this->salesRuleRepository = $salesRuleRepository;
        $this->ruleFactory = $ruleFactory;
        $this->config = $config;
        $this->couponFactory = $couponFactory;
    }

    /**
     * Build line item discounts array from sales order item.
     *
     * Pass the sales order too: its coupon_code is the code the customer
     * actually redeemed, which is the only reliable source for rules using
     * auto-generated coupons (their rule has no primary coupon row, so
     * Rule::getCouponCode() returns null there).
     *
     * @param SalesOrderItemInterface $salesOrderItem
     * @param SalesOrderInterface|null $salesOrder
     *
     * @return array
     */
    public function buildFromSalesOrderItem(
        SalesOrderItemInterface $salesOrderItem,
        ?SalesOrderInterface $salesOrder = null
    ): array {
        $this->orderCouponCode = $salesOrder && $salesOrder->getCouponCode() !== null
            ? (string)$salesOrder->getCouponCode()
            : null;

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

        // Amasty Free Gift (GWP) rules: these add a free $0 item rather than
        // reducing existing items' prices, so discount_amount is 0 on all items.
        // When enabled, emit a zero-amount discount entry carrying the voucher
        // code so Klar can attribute the order to the coupon.
        if (!$discountAmount
            && !$this->isBundle($salesOrderItem)
            && $this->config->getIsAmastyGwpEnabled()
            && $salesOrderItem->getAppliedRuleIds()
        ) {
            $ruleIds = explode(',', $salesOrderItem->getAppliedRuleIds());
            foreach ($ruleIds as $ruleId) {
                $gwpDiscount = $this->buildGwpRuleDiscount((int)$ruleId);
                if (!empty($gwpDiscount)) {
                    $discounts[] = $gwpDiscount;
                }
            }
        }

        if ($this->isBundle($salesOrderItem)) {
            return $discounts;
        }

        // Backfill per-item amount for cart_fixed / buy_x_get_y discounts:
        // these were added with discountAmount=0. Distribute the remaining
        // discount (from Magento's stored discount_amount) to them.
        if (round($discountLeft, 2) > 0.02) {
            $zeroAmountDiscounts = [];
            foreach ($discounts as $idx => $d) {
                if (isset($d['discountAmount']) && (float)$d['discountAmount'] === 0.0) {
                    $zeroAmountDiscounts[] = $idx;
                }
            }

            if (!empty($zeroAmountDiscounts)) {
                // Distribute evenly across all zero-amount discounts
                $perDiscount = $discountLeft / count($zeroAmountDiscounts) / $qtyOrdered;
                foreach ($zeroAmountDiscounts as $idx) {
                    $discounts[$idx]['discountAmount'] = round($perDiscount, 4);
                    $discountLeft -= round($perDiscount, 4) * $qtyOrdered;
                }
            }
        }

        if (round($discountLeft, 2) > 0.02) {
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
            $discount->setIsVoucher(true);
            $discount->setVoucherCode($this->resolveVoucherCode($ruleId));
        }

        if ($salesRule->getSimpleAction() === RuleInterface::DISCOUNT_ACTION_BY_PERCENT) {
            $discountPercent = $salesRule->getDiscountAmount() / 100;
            $discount->setDiscountAmount($baseItemPrice * $discountPercent);
        } elseif ($salesRule->getSimpleAction() === RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT) {
            $discount->setDiscountAmount((float)$salesRule->getDiscountAmount());
        } else {
            // For cart_fixed and buy_x_get_y: per-item amount can't be calculated
            // from the rule alone. Set 0 here — the caller will backfill the actual
            // amount from Magento's stored discount_amount.
            $discount->setDiscountAmount(0);
        }

        return $this->snakeToCamel($discount->toArray());
    }

    /**
     * Build a zero-amount discount entry for an Amasty Free Gift (GWP) rule,
     * carrying the voucher code so Klar can attribute the order to the coupon.
     *
     * Only emits a discount if the rule's simple_action starts with "ampromo_"
     * and the rule has a specific coupon code attached.
     *
     * @param int $ruleId
     * @return array
     */
    private function buildGwpRuleDiscount(int $ruleId): array
    {
        try {
            $salesRule = $this->salesRuleRepository->getById($ruleId);
        } catch (NoSuchEntityException|LocalizedException $e) {
            return [];
        }

        $action = (string)$salesRule->getSimpleAction();
        if (strpos($action, self::AMPROMO_ACTION_PREFIX) !== 0) {
            return [];
        }

        $discount = $this->discountFactory->create();
        $discount->setTitle($salesRule->getName());
        $discount->setDescriptor($salesRule->getDescription());
        $discount->setDiscountAmount(0);

        if ($salesRule->getCouponType() === RuleInterface::COUPON_TYPE_SPECIFIC_COUPON) {
            $discount->setIsVoucher(true);
            $discount->setVoucherCode($this->resolveVoucherCode($ruleId));
        }

        return $this->snakeToCamel($discount->toArray());
    }

    /**
     * Resolve the voucher code for a specific-coupon sales rule.
     *
     * Prefer the coupon code redeemed on the order, but only when that code
     * actually belongs to this rule (orders can carry several applied rules).
     * Fall back to the rule's primary coupon, which covers manually defined
     * specific codes. For auto-generation rules the primary coupon does not
     * exist, so without the order-level code the result is null (pre-fix
     * behavior).
     *
     * @param int $ruleId
     *
     * @return string|null
     */
    private function resolveVoucherCode(int $ruleId): ?string
    {
        if ($this->orderCouponCode !== null
            && $this->orderCouponCode !== ''
            && $this->getRuleIdForCouponCode($this->orderCouponCode) === $ruleId
        ) {
            return $this->orderCouponCode;
        }

        return $this->ruleFactory->create()->load($ruleId)->getCouponCode();
    }

    /**
     * Look up which rule a coupon code belongs to (memoized).
     *
     * @param string $couponCode
     *
     * @return int|null
     */
    private function getRuleIdForCouponCode(string $couponCode): ?int
    {
        if (!array_key_exists($couponCode, $this->couponRuleIdCache)) {
            if ($this->couponFactory === null) {
                // BC fallback: the parameter was appended after release, so
                // it defaults to null for callers built against the old
                // constructor signature.
                $this->couponFactory = \Magento\Framework\App\ObjectManager::getInstance()
                    ->get(CouponFactory::class);
            }

            $coupon = $this->couponFactory->create()->loadByCode($couponCode);

            $this->couponRuleIdCache[$couponCode] = $coupon->getRuleId()
                ? (int)$coupon->getRuleId()
                : null;
        }

        return $this->couponRuleIdCache[$couponCode];
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
