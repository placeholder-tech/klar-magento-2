<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Builders;

use Magento\Sales\Api\Data\OrderInterface as SalesOrderInterface;
use PlaceholderTech\Klar\Model\AbstractApiRequestParamsBuilder;
use PlaceholderTech\Klar\Model\AttributeValueResolver;
use PlaceholderTech\Klar\Api\Data\OptionalIdentifiersInterfaceFactory;
use Magento\Framework\Intl\DateTimeFactory;

class OptionalIdentifiersBuilder extends AbstractApiRequestParamsBuilder
{
    private OptionalIdentifiersInterfaceFactory $optionalIdentifiersFactory;
    private AttributeValueResolver $attributeResolver;

    public function __construct(
        DateTimeFactory $dateTimeFactory,
        OptionalIdentifiersInterfaceFactory $optionalIdentifiersFactory,
        AttributeValueResolver $attributeResolver
    ) {
        parent::__construct($dateTimeFactory);
        $this->optionalIdentifiersFactory = $optionalIdentifiersFactory;
        $this->attributeResolver = $attributeResolver;
    }

    /**
     * Build OptionalIdentifiers from sales order.
     */
    public function buildFromSalesOrder(SalesOrderInterface $salesOrder): array
    {
        $optionalIdentifiers = $this->optionalIdentifiersFactory->create();
        $optionalIdentifiers->setGoogleAnalyticsTransactionId($salesOrder->getIncrementId());

        $array = $this->snakeToCamel($optionalIdentifiers->toArray());

        // Merge in configurable field mappings (utmSource, utmMedium, orderChannelName, ...)
        $mappedFields = $this->attributeResolver->resolveAll('optional_identifiers', [
            'order' => $salesOrder,
        ]);
        foreach ($mappedFields as $name => $value) {
            $array[$name] = $value;
        }

        return $array;
    }
}
