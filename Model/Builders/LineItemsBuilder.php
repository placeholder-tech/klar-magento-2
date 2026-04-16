<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Builders;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use PlaceholderTech\Klar\Api\Data\LineItemInterface;
use PlaceholderTech\Klar\Api\Data\LineItemInterfaceFactory;
use PlaceholderTech\Klar\Helper\Config;
use PlaceholderTech\Klar\Model\AbstractApiRequestParamsBuilder;
use PlaceholderTech\Klar\Model\AttributeValueResolver;
use Magento\Bundle\Model\Product\Type as BundleProductType;
use Magento\Catalog\Model\Product;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderInterface as SalesOrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface as SalesOrderItemInterface;

class LineItemsBuilder extends AbstractApiRequestParamsBuilder
{
    private LineItemInterfaceFactory $lineItemFactory;
    private TaxesBuilder $taxesBuilder;
    private Config $config;
    private LineItemDiscountsBuilder $discountsBuilder;
    private AttributeValueResolver $attributeResolver;

    public function __construct(
        DateTimeFactory $dateTimeFactory,
        LineItemInterfaceFactory $lineItemFactory,
        TaxesBuilder $taxesBuilder,
        Config $config,
        LineItemDiscountsBuilder $discountsBuilder,
        AttributeValueResolver $attributeResolver
    ) {
        parent::__construct($dateTimeFactory);
        $this->lineItemFactory = $lineItemFactory;
        $this->taxesBuilder = $taxesBuilder;
        $this->config = $config;
        $this->discountsBuilder = $discountsBuilder;
        $this->attributeResolver = $attributeResolver;
    }

    /**
     * Build line items array from sales order.
     *
     * @param SalesOrderInterface $salesOrder
     *
     * @return array
     */
    public function buildFromSalesOrder(SalesOrderInterface $salesOrder): array
    {
        $lineItems = [];

        foreach ($salesOrder->getItems() as $salesOrderItem) {
            // Skip children of non-Bundle products
            $parent = $salesOrderItem->getParentItem();
            if ($parent && $parent->getProductType() !== BundleProductType::TYPE_CODE) {
                continue;
            }

            $product = $salesOrderItem->getProduct();
            $productVariant = $this->getProductVariant($salesOrderItem);
            $totalBeforeTaxesAndDiscounts = (float)$salesOrderItem->getRowTotalInclTax();
            $weightInGrams = $product ? $this->getWeightInGrams($product) : 0;

            /* @var LineItemInterface $lineItem */
            $lineItem = $this->lineItemFactory->create();

            $lineItem->setId((string)$salesOrderItem->getItemId());
            $lineItem->setProductName($salesOrderItem->getName());
            $lineItem->setProductId((string)$salesOrderItem->getProductId());

            if ($productVariant) {
                $lineItem->setProductVariantName($productVariant['name']);
                $lineItem->setProductVariantId((string)$productVariant['id']);
            }

            $lineItem->setProductCogs($this->getBaseCost($salesOrderItem));
            $lineItem->setProductGmv($this->getProductGmv($salesOrderItem));
            $lineItem->setProductShippingWeightInGrams($weightInGrams);
            $lineItem->setSku($salesOrderItem->getSku());
            $lineItem->setQuantity((float)$salesOrderItem->getQtyOrdered());
            $lineItem->setDiscounts($this->discountsBuilder->buildFromSalesOrderItem($salesOrderItem));
            $lineItem->setTaxes(
                $this->taxesBuilder->build((int)$salesOrderItem->getOrderId(), $salesOrderItem)
            );
            $lineItem->setTotalAmountBeforeTaxesAndDiscounts($totalBeforeTaxesAndDiscounts);

            $totalAfterTaxesAndDiscounts = $this->calculateTotalAfterTaxesAndDiscounts($lineItem, $salesOrderItem);
            $lineItem->setTotalAmountAfterTaxesAndDiscounts($totalAfterTaxesAndDiscounts ?: 0.0);

            $lineItemArray = $this->snakeToCamel($lineItem->toArray());

            // Merge in configurable field mappings (productBrand, productCollection, productTags, ...)
            $mappedFields = $this->attributeResolver->resolveAll('line_item', [
                'product' => $product,
                'order' => $salesOrder,
                'order_item' => $salesOrderItem,
            ]);
            foreach ($mappedFields as $name => $value) {
                $lineItemArray[$name] = $value;
            }

            // We temporarily use the item ID as a key to easily locate the Bundle product and add its children
            if ($parent) {
                // At this point parent product is Bundle
                $lineItems[$parent->getItemId()]['bundledProducts'][] = $lineItemArray;
            } else {
                $lineItems[$salesOrderItem->getItemId()] = $lineItemArray;
            }
        }

        // Loop line items again to fill missing info for the Bundle product's children
        foreach ($lineItems as &$lineItem) {
            if (isset($lineItem['bundledProducts'])) {
                $lineItem['discounts'] = $this->discountsBuilder->buildBundleDiscount($lineItem);
                $lineItem['taxes'] = $this->taxesBuilder->buildBundleTaxes($lineItem);

                $productShippingWeightInGrams = 0.0;
                $totalAmountBeforeTaxesAndDiscounts = 0.0;
                $totalAmountAfterTaxesAndDiscounts = 0.0;
                foreach ($lineItem['bundledProducts'] as $bundledProduct) {
                    $productShippingWeightInGrams += $bundledProduct['productShippingWeightInGrams'];
                    $totalAmountBeforeTaxesAndDiscounts += $bundledProduct['totalAmountBeforeTaxesAndDiscounts'];
                    $totalAmountAfterTaxesAndDiscounts += $bundledProduct['totalAmountAfterTaxesAndDiscounts'];
                }

                $lineItem['productShippingWeightInGrams'] = $productShippingWeightInGrams;
                $lineItem['totalAmountBeforeTaxesAndDiscounts'] = $totalAmountBeforeTaxesAndDiscounts;
                $lineItem['totalAmountAfterTaxesAndDiscounts'] = $totalAmountAfterTaxesAndDiscounts;
            }
        }

        // We clear the keys here because we no longer need them
        return array_values($lineItems);
    }

    /**
     * Get product variant name and ID.
     *
     * @param SalesOrderItemInterface $salesOrderItem
     *
     * @return array|false
     */
    private function getProductVariant(SalesOrderItemInterface $salesOrderItem)
    {
        $productOptions = $salesOrderItem->getProductOptions();

        if (isset($productOptions['simple_name'], $productOptions['simple_sku'])) {
            return [
                'name' => $productOptions['simple_name'],
                'id' => $productOptions['simple_sku'],
            ];
        }

        return false;
    }

    /**
     * Get product weight in grams.
     *
     * @param Product $product
     *
     * @return float
     */
    private function getWeightInGrams(Product $product): float
    {
        $productWeightInKgs = 0.00;
        $weightUnit = $this->config->getWeightUnit();
        $productWeight = (float)$product->getWeight();

        if ($productWeight) {
            // Convert LBS to KGS if unit is LBS
            if ($weightUnit === Config::WEIGHT_UNIT_LBS) {
                $productWeightInKgs = $this->convertLbsToKgs($productWeight);
            } else {
                $productWeightInKgs = $productWeight;
            }

            return $productWeightInKgs * 1000;
        }

        return $productWeightInKgs;
    }

    /**
     * Convert lbs to kgs.
     *
     * @param float $weightLbs
     *
     * @return float
     */
    private function convertLbsToKgs(float $weightLbs): float
    {
        $conversionFactor = 0.45359237;
        $weightInKgs = $weightLbs * $conversionFactor;

        return round($weightInKgs, 3);
    }

    /**
     * Return base cost. For Configurable - get base cost from Child product
     *
     * @param SalesOrderItemInterface $salesOrderItem
     * @return float
     */
    private function getBaseCost(SalesOrderItemInterface $salesOrderItem): float
    {
        $baseCost = (float)$salesOrderItem->getBaseCost();

        if ($salesOrderItem->getProductType() === Configurable::TYPE_CODE) {
            $productOptions = $salesOrderItem->getProductOptions();
            $childrenSku = $productOptions['simple_sku'] ?? null;

            if ($childrenSku) {
                foreach ($salesOrderItem->getChildrenItems() as $childItem) {
                    if ($childItem->getSku() === $childrenSku) {
                        $baseCost = (float)$childItem->getBaseCost();
                        break;
                    }
                }
            }
        }

        return $baseCost;
    }

    private function getProductGmv(SalesOrderItemInterface $salesOrderItem): float
    {
        $priceInclTax = (float) $salesOrderItem->getPriceInclTax();
        return $priceInclTax !== 0.0 ? $priceInclTax : (float) $salesOrderItem->getOriginalPrice();
    }

    /**
     * Calculate line item total after taxes and discounts.
     *
     * @param LineItemInterface $lineItem
     * @param SalesOrderItemInterface $salesOrderItem
     * @return float
     */
    private function calculateTotalAfterTaxesAndDiscounts(
        LineItemInterface $lineItem,
        SalesOrderItemInterface $salesOrderItem
    ): float {
        $taxAmount = 0;
        $discountAmount = 0;
        $quantity = $lineItem->getQuantity();
        $productGmv = $lineItem->getProductGmv() * $quantity;

        foreach ($lineItem->getTaxes() as $lineItemTax) {
            $taxAmount += $lineItemTax['taxAmount'] * $quantity;
        }

        foreach ($lineItem->getDiscounts() as $lineItemDiscount) {
            $discountAmount += $lineItemDiscount['discountAmount'] * $quantity;
        }

        return $productGmv - $taxAmount - $discountAmount;
    }
}
