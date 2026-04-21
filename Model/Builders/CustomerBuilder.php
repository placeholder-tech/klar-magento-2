<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Builders;

use PlaceholderTech\Klar\Api\Data\CustomerInterface;
use PlaceholderTech\Klar\Api\Data\CustomerInterfaceFactory;
use PlaceholderTech\Klar\Helper\Config;
use PlaceholderTech\Klar\Model\AbstractApiRequestParamsBuilder;
use PlaceholderTech\Klar\Model\AttributeValueResolver;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderInterface as SalesOrderInterface;

class CustomerBuilder extends AbstractApiRequestParamsBuilder
{
    private CustomerInterfaceFactory $customerFactory;
    private EncryptorInterface $encryptor;
    private Config $config;
    private AttributeValueResolver $attributeResolver;

    public function __construct(
        DateTimeFactory $dateTimeFactory,
        CustomerInterfaceFactory $customerFactory,
        EncryptorInterface $encryptor,
        Config $config,
        AttributeValueResolver $attributeResolver
    ) {
        parent::__construct($dateTimeFactory);
        $this->customerFactory = $customerFactory;
        $this->encryptor = $encryptor;
        $this->config = $config;
        $this->attributeResolver = $attributeResolver;
    }

    /**
     * Build customer from sales order.
     *
     * @param SalesOrderInterface $salesOrder
     *
     * @return array
     */
    public function buildFromSalesOrder(SalesOrderInterface $salesOrder): array
    {
        $customerId = $salesOrder->getCustomerId();
        // Legacy / admin-created orders can have customer_email = NULL.
        // PHP 8 trim(null) throws TypeError, which escapes catch(Exception)
        // in the caller and kills the whole async queue batch. Cast to string
        // defensively so a single bad row does not poison its siblings.
        $rawEmail = (string)($salesOrder->getCustomerEmail() ?? '');
        $normalizedEmail = strtolower(trim($rawEmail));
        $customerEmail = $this->config->getSendEmail() ? $rawEmail : "redacted@getklar.com";
        $customerEmailHash = sha1($this->config->getPublicKey() . $normalizedEmail);

        if (!$customerId) {
            $customerId = $this->generateGuestCustomerId($normalizedEmail);
        }

        /* @var CustomerInterface $customer */
        $customer = $this->customerFactory->create();

        $customer->setId((string)$customerId);
        $customer->setEmail($customerEmail);
        $customer->setEmailHash($customerEmailHash);

        $customerArray = $this->snakeToCamel($customer->toArray());

        // Merge in configurable field mappings (tags, isNewsletterSubscriberAtTimeOfCheckout, ...)
        $mappedFields = $this->attributeResolver->resolveAll('customer', [
            'order' => $salesOrder,
        ]);
        foreach ($mappedFields as $name => $value) {
            $customerArray[$name] = $value;
        }

        return $customerArray;
    }

    /**
     * Generate guest customer ID as per Klar recommendation.
     *
     * @param string $customerEmail
     *
     * @return string
     */
    private function generateGuestCustomerId(string $customerEmail): string
    {
        return $this->encryptor->hash($customerEmail, Encryptor::HASH_VERSION_MD5);
    }
}
