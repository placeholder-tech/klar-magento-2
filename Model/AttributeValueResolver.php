<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface as SalesOrderInterface;
use PlaceholderTech\Klar\Model\Config\FieldMapping\Data as FieldMappingData;
use Psr\Log\LoggerInterface;

/**
 * Resolves Magento entity attribute values into Klar API field values
 * based on the configured field mapping.
 *
 * Sources:
 *   - product_attribute    : reads from a Catalog Product
 *   - customer_attribute   : reads from the Customer associated with the order
 *   - order_attribute      : reads from the SalesOrder itself
 *   - customer_group       : returns the order's customer_group_id
 *   - category_top         : returns the highest-level category name of the product
 *
 * Value types:
 *   - text                 : raw string value
 *   - label                : dropdown / select label (uses getAttributeText())
 *   - multiselect_labels   : array of labels for a multiselect attribute
 *   - joined_labels        : multiselect labels joined into a single string
 *                            (separator attribute, default ", ")
 *   - boolean              : truthy → true, falsy → false
 *   - int                  : integer cast
 *   - float                : float cast
 *   - prefixed_id          : "<prefix><id>" string (used for tags)
 */
class AttributeValueResolver
{
    private FieldMappingData $mappingData;
    private CategoryRepositoryInterface $categoryRepository;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    public function __construct(
        FieldMappingData $mappingData,
        CategoryRepositoryInterface $categoryRepository,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->mappingData = $mappingData;
        $this->categoryRepository = $categoryRepository;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Resolve all configured fields for a given group, returning a name→value map.
     * Skips fields that resolve to null/empty so callers can rely on isset().
     *
     * @param string $group e.g. 'line_item', 'customer', 'optional_identifiers', 'order'
     * @param array $context resolver-specific context (e.g. ['product' => $product])
     * @return array
     */
    public function resolveAll(string $group, array $context): array
    {
        $result = [];
        foreach ($this->mappingData->getFieldsForGroup($group) as $fieldName => $field) {
            $value = $this->resolveField($field, $context);
            if ($value !== null && $value !== '' && $value !== []) {
                $result[$fieldName] = $value;
            }
        }
        return $result;
    }

    /**
     * @param array{name:string,source:string,code:?string,type:string,prefix:?string,separator:string} $field
     * @param array $context
     * @return mixed
     */
    private function resolveField(array $field, array $context)
    {
        try {
            switch ($field['source']) {
                case 'product_attribute':
                    return $this->fromProductAttribute($field, $context['product'] ?? null);
                case 'customer_attribute':
                    return $this->fromCustomerAttribute($field, $context['order'] ?? null);
                case 'order_attribute':
                    return $this->fromOrderAttribute($field, $context['order'] ?? null);
                case 'customer_group':
                    return $this->fromCustomerGroup($field, $context['order'] ?? null);
                case 'category_top':
                    return $this->fromTopCategory($context['product'] ?? null);
                default:
                    return null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Klar field mapping: failed to resolve %s (%s/%s): %s',
                $field['name'],
                $field['source'],
                $field['code'] ?? '-',
                $e->getMessage()
            ));
            return null;
        }
    }

    private function fromProductAttribute(array $field, ?ProductInterface $product)
    {
        if (!$product || !$field['code']) {
            return null;
        }

        $code = $field['code'];

        if ($field['type'] === 'label') {
            $label = $product->getAttributeText($code);
            return $label !== false && $label !== '' ? $label : null;
        }

        if ($field['type'] === 'multiselect_labels' || $field['type'] === 'joined_labels') {
            $labels = $this->getMultiselectLabels($product, $code);
            if (!$labels) {
                return null;
            }
            if ($field['type'] === 'joined_labels') {
                return implode($field['separator'] ?? ', ', $labels);
            }
            return $labels;
        }

        $value = $product->getData($code);
        return $this->castType($value, $field);
    }

    /**
     * @return string[]
     */
    private function getMultiselectLabels(ProductInterface $product, string $code): array
    {
        $raw = $product->getData($code);
        if ($raw === null || $raw === '') {
            return [];
        }
        $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
        $labels = [];
        $attribute = method_exists($product, 'getResource')
            ? $product->getResource()->getAttribute($code)
            : null;
        if ($attribute && $attribute->usesSource()) {
            foreach ($ids as $id) {
                $label = $attribute->getSource()->getOptionText(trim((string)$id));
                if ($label) {
                    $labels[] = is_array($label) ? implode(' ', $label) : (string)$label;
                }
            }
        }
        return $labels;
    }

    private function fromCustomerAttribute(array $field, ?SalesOrderInterface $order)
    {
        if (!$order || !$field['code']) {
            return null;
        }
        $customerId = $order->getCustomerId();
        if (!$customerId) {
            return null;
        }
        try {
            $customer = $this->customerRepository->getById((int)$customerId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        $code = $field['code'];

        // Try standard getter first
        $getter = 'get' . str_replace('_', '', ucwords($code, '_'));
        $value = null;
        if (method_exists($customer, $getter)) {
            $value = $customer->{$getter}();
        } else {
            $customAttr = $customer->getCustomAttribute($code);
            $value = $customAttr ? $customAttr->getValue() : null;
        }

        return $this->castType($value, $field);
    }

    private function fromOrderAttribute(array $field, ?SalesOrderInterface $order)
    {
        if (!$order || !$field['code']) {
            return null;
        }
        $value = $order->getData($field['code']);
        return $this->castType($value, $field);
    }

    private function fromCustomerGroup(array $field, ?SalesOrderInterface $order)
    {
        if (!$order) {
            return null;
        }
        $groupId = $order->getCustomerGroupId();
        if ($groupId === null || $groupId === '') {
            return null;
        }

        $value = $this->castType($groupId, $field);
        // For "tags" Klar expects an array
        if ($field['name'] === 'tags' && !is_array($value)) {
            return [(string)$value];
        }
        return $value;
    }

    private function fromTopCategory(?ProductInterface $product): ?string
    {
        if (!$product) {
            return null;
        }
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return null;
        }
        $categoryNamesByLevel = [];
        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get((int)$categoryId);
            } catch (NoSuchEntityException $e) {
                continue;
            }
            $categoryNamesByLevel[(int)$category->getLevel()] = $category->getName();
        }
        if (!$categoryNamesByLevel) {
            return null;
        }
        krsort($categoryNamesByLevel);
        return reset($categoryNamesByLevel);
    }

    private function castType($value, array $field)
    {
        if ($value === null || $value === '') {
            return null;
        }
        switch ($field['type']) {
            case 'boolean':
                return (bool)$value;
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'prefixed_id':
                return ($field['prefix'] ?? '') . (string)$value;
            case 'text':
            default:
                return is_array($value) ? $value : (string)$value;
        }
    }
}
