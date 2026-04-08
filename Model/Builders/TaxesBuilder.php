<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Builders;

use PlaceholderTech\Klar\Api\Data\TaxInterface;
use PlaceholderTech\Klar\Api\Data\TaxInterfaceFactory;
use PlaceholderTech\Klar\Api\DiscountServiceInterface;
use PlaceholderTech\Klar\Model\AbstractApiRequestParamsBuilder;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\ResourceModel\Order\Tax\Item as TaxItemResource;
use Magento\Tax\Model\Config;

class TaxesBuilder extends AbstractApiRequestParamsBuilder
{
    public const TAXABLE_ITEM_TYPE_PRODUCT = 'product';
    public const TAXABLE_ITEM_TYPE_SHIPPING = 'shipping';

    private TaxItemResource $taxItemResource;
    private TaxInterfaceFactory $taxFactory;
    private DiscountServiceInterface $discountService;
    private Config $taxConfig;

    /**
     * TaxesBuilder constructor.
     *
     * @param DateTimeFactory $dateTimeFactory
     * @param TaxItemResource $taxItemResource
     * @param TaxInterfaceFactory $taxFactory
     * @param DiscountServiceInterface $discountService
     * @param Config $taxConfig
     */
    public function __construct(
        DateTimeFactory $dateTimeFactory,
        TaxItemResource $taxItemResource,
        TaxInterfaceFactory $taxFactory,
        DiscountServiceInterface $discountService,
        Config $taxConfig
    ) {
        parent::__construct($dateTimeFactory);
        $this->taxItemResource = $taxItemResource;
        $this->taxFactory = $taxFactory;
        $this->discountService = $discountService;
        $this->taxConfig = $taxConfig;
    }

    /**
     * Get taxes from sales order by type.
     *
     * @param int $salesOrderId
     * @param OrderItemInterface|null $salesOrderItem
     * @param string $taxableItemType
     *
     * @return array
     */
    public function build(
        int $salesOrderId,
        OrderItemInterface $salesOrderItem = null,
        string $taxableItemType = self::TAXABLE_ITEM_TYPE_PRODUCT
    ): array {
        $taxes = [];
        $taxItems = $this->taxItemResource->getTaxItemsByOrderId($salesOrderId);

        foreach ($taxItems as $taxItem) {
            $taxRate = (float)($taxItem['tax_percent'] / 100);

            if ($taxItem['taxable_item_type'] === self::TAXABLE_ITEM_TYPE_PRODUCT &&
                $salesOrderItem !== null) {
                $salesOrderItemId = (int)$salesOrderItem->getId();

                if ((int)$taxItem['item_id'] !== $salesOrderItemId) {
                    continue;
                }

                $qty = $salesOrderItem->getQtyOrdered() ? $salesOrderItem->getQtyOrdered() : 1;

                $taxAmount = (float)$salesOrderItem->getTaxAmount() / $qty;
            } else {
                $taxAmount = (float)$taxItem['real_amount'];
            }

            if ($taxItem['taxable_item_type'] === $taxableItemType) {
                /* @var TaxInterface $tax */
                $tax = $this->taxFactory->create();

                $tax->setTitle($taxItem['title']);
                $tax->setTaxRate($taxRate);
                $tax->setTaxAmount($taxAmount);

                $taxes[$taxableItemType][] = $this->snakeToCamel($tax->toArray());
            }
        }

        if (!empty($taxes)) {
            return $taxes[$taxableItemType];
        }

        return $taxes;
    }

    /**
     * Build taxes for Bundle based on its children taxes
     *
     * @param array $lineItem
     * @return array
     */
    public function buildBundleTaxes(array $lineItem): array
    {
        $taxes = [];

        foreach ($lineItem['bundledProducts'] as $bundledProduct) {
            foreach ($bundledProduct['taxes'] as $tax) {
                if (!isset($taxes[$tax['title']])) {
                    // Add first record for current tax
                    $taxes[$tax['title']] = $tax;
                    $taxes[$tax['title']]['taxAmount'] *= $bundledProduct['quantity'];
                } else {
                    // Add tax amount to the existing record
                    $taxes[$tax['title']]['taxAmount'] += $tax['taxAmount'] * $bundledProduct['quantity'];
                }
            }
        }

        return array_values($taxes);
    }
}
