<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Builders;

use PlaceholderTech\Klar\Api\Data\LineItemInterface;
use PlaceholderTech\Klar\Api\Data\LineItemInterfaceFactory;
use PlaceholderTech\Klar\Helper\Config;
use PlaceholderTech\Klar\Model\AbstractApiRequestParamsBuilder;
use Magento\Bundle\Model\Product\Type as BundleProductType;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderInterface as SalesOrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface as SalesOrderItemInterface;

class LineItemsBuilder extends AbstractApiRequestParamsBuilder
{
    private LineItemInterfaceFactory $lineItemFactory;
    private CategoryRepositoryInterface $categoryRepository;
    private TaxesBuilder $taxesBuilder;
    private Config $config;
    private LineItemDiscountsBuilder $discountsBuilder;

    /**
     * LineItemsBuilder constructor.
     *
     * @param DateTimeFactory $dateTimeFactory
     * @param LineItemInterfaceFactory $lineItemFactory
     * @param CategoryRepositoryInterface $categoryRepository
     * @param TaxesBuilder $taxesBuilder
     * @param Config $config
     * @param LineItemDiscountsBuilder $discountsBuilder
     */
    public function __construct(
        DateTimeFactory $dateTimeFactory,
        LineItemInterfaceFactory $lineItemFactory,
        CategoryRepositoryInterface $categoryRepository,
        TaxesBuilder $taxesBuilder,
        Config $config,
        LineItemDiscountsBuilder $discountsBuilder
    ) {
        parent::__construct($dateTimeFactory);
        $this->lineItemFactory = $lineItemFactory;
        $this->categoryRepository = $categoryRepository;
        $this->taxesBuilder = $taxesBuilder;
        $this->config = $config;
        $this->discountsBuilder = $discountsBuilder;
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
            $productBrand = false;
            $categoryName = $this->getCategoryName($salesOrderItem);
            $totalBeforeTaxesAndDiscounts = $salesOrderItem->getOriginalPrice() * $salesOrderItem->getQtyOrdered();
            $weightInGrams = 0;

            if ($product) {
                $productBrand = $product->getAttributeText('manufacturer');
                $weightInGrams = $this->getWeightInGrams($product);
            }

            /* @var LineItemInterface $lineItem */
            $lineItem = $this->lineItemFactory->create();

            $lineItem->setId((string)$salesOrderItem->getItemId());
            $lineItem->setProductName($salesOrderItem->getName());
            $lineItem->setProductId((string)$salesOrderItem->getProductId());

            if ($productVariant) {
                $lineItem->setProductVariantName($productVariant['name']);
                $lineItem->setProductVariantId((string)$productVariant['id']);
            }

            if ($productBrand) {
                $lineItem->setProductBrand($productBrand);
            }

            if ($categoryName) {
                $lineItem->setProductCollection($categoryName);
            }

            $lineItem->setProductCogs((float)$salesOrderItem->getBaseCost());
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

            // We temporarily use the item ID as a key to easily locate the Bundle product and add its children
            if ($parent) {
                // At this point parent product is Bundle
                $lineItems[$parent->getItemId()]['bundledProducts'][] = $this->snakeToCamel($lineItem->toArray());
            } else {
                $lineItems[$salesOrderItem->getItemId()] = $this->snakeToCamel($lineItem->toArray());
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
     * Get the highest level category name.
     *
     * @param SalesOrderItemInterface $salesOrderItem
     *
     * @return string|null
     */
    private function getCategoryName(SalesOrderItemInterface $salesOrderItem): ?string
    {
        $product = $salesOrderItem->getProduct();

        if (!$product) {
            return null;
        }

        $categoryIds = $product->getCategoryIds();
        $categoryNames = [];

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get($categoryId);
            } catch (NoSuchEntityException $e) {
                continue;
            }

            $categoryLevel = $category->getLevel();
            $categoryName = $category->getName();
            $categoryNames[$categoryLevel] = $categoryName;
        }

        if (!empty($categoryNames)) {
            krsort($categoryNames);

            return reset($categoryNames);
        }

        return null;
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

    private function getProductGmv(SalesOrderItemInterface $salesOrderItem): float
    {
        return round((float) $salesOrderItem->getOriginalPrice(), 2) !== 0.0 ?
            (float) $salesOrderItem->getOriginalPrice() : (float) $salesOrderItem->getPrice();
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

        $taxAmount = round($taxAmount, 2);
        if (abs($salesOrderItem->getTaxAmount() - $taxAmount) > 0) {
            // If calculated tax amount does not match tax amount from the order item, the latter takes preference.
            // This fix covers edge case scenario when Magento may add extra cent to the order item's tax amount
            // but we want all subtotals to match still
            $taxAmount = $salesOrderItem->getTaxAmount();
        }

        return $productGmv + $taxAmount - $discountAmount;
    }
}
