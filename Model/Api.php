<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model;

use Exception;
use PlaceholderTech\Klar\Api\Data\ApiInterface;
use PlaceholderTech\Klar\Helper\Config;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderInterface as SalesOrderInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class Api implements ApiInterface
{
    private Curl $curl;
    private Config $config;
    private PsrLoggerInterface $logger;
    private ApiRequestParamsBuilder $paramsBuilder;
    private string $requestData;
    private Json $jsonSerializer;
    private OrderCollectionFactory $orderCollectionFactory;
    private string $lastError = '';

    /**
     * Api constructor.
     *
     * @param Curl $curl
     * @param Config $config
     * @param PsrLoggerInterface $logger
     * @param ApiRequestParamsBuilder $paramsBuilder
     * @param Json $jsonSerializer
     * @param OrderCollectionFactory $orderCollectionFactory
     */
    public function __construct(
        Curl $curl,
        Config $config,
        PsrLoggerInterface $logger,
        ApiRequestParamsBuilder $paramsBuilder,
        Json $jsonSerializer,
        OrderCollectionFactory $orderCollectionFactory
    ) {
        $this->requestData = '';
        $this->curl = $curl;
        $this->config = $config;
        $this->logger = $logger;
        $this->paramsBuilder = $paramsBuilder;
        $this->jsonSerializer = $jsonSerializer;
        $this->orderCollectionFactory = $orderCollectionFactory;
    }

    /**
     * Get orders by order IDs.
     *
     * @param int[] $orderIds
     *
     * @return null|SalesOrderInterface[]
     * @throws NoSuchEntityException
     */
    private function getOrders(array $orderIds): ?array
    {
        if ($this->config->getIsEnabled()) {
            $items = $this->orderCollectionFactory->create()
                ->addFieldToFilter('entity_id', ['in' => $orderIds])
                ->getItems();

            if (count($items) !== count($orderIds)) {
                throw new NoSuchEntityException(
                    __('Could not find orders with ids: `%ids`',
                        [
                            'ids' => implode(', ', array_diff(array_keys($items), $orderIds))
                        ]

                    )
                );
            }
            return $items;
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function send(array $ids): int
    {
        $result = 0;
        $salesOrders = $this->getOrders($ids);

        if ($salesOrders) {
            $this->setRequestData($salesOrders);
        } else {
            return $result;
        }

        return $this->json($salesOrders);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getJsonDataForOrders(array $ids): string
    {
        $salesOrders = $this->getOrders($ids);

        if ($salesOrders) {
            $this->setRequestData($salesOrders);
            return $this->requestData;
        } else {
            return __('Could not get order data');
        }
    }

    /**
     * Set request data.
     *
     * @param SalesOrderInterface[] $salesOrders
     *
     * @return void
     */
    private function setRequestData(array $salesOrders): void
    {
        try {
            $items = [];
            foreach ($salesOrders as $salesOrder) {
                $items[] = $this->paramsBuilder->buildFromSalesOrder($salesOrder);
            }

            $this->requestData = $this->jsonSerializer->serialize($items);
        } catch (Exception $e) {
            $this->logger->error(__('Error building order payload: %1', $e->getMessage()));
        }
    }

    /**
     * Get CURL client.
     *
     * @return Curl
     */
    private function getCurlClient(): Curl
    {
        $this->curl->setHeaders($this->getHeaders());

        return $this->curl;
    }

    /**
     * Get request headers.
     *
     * @return string[]
     */
    private function getHeaders(): array
    {
        return [
            'Expect' => '',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getApiToken(),
            'User-Agent' => 'getklar/' . $this->config->getCurrentVersion() .' (magento2)'
        ];
    }

    /**
     * Get API token.
     *
     * @return string
     */
    private function getApiToken(): string
    {
        return $this->config->getApiToken();
    }

    /**
     * Get request endpoint URL.
     *
     * @param string $path
     * @param bool $includeVersion
     *
     * @return string
     */
    private function getRequestUrl(string $path, bool $includeVersion = false): string
    {
        $baseUrl = rtrim($this->config->getApiUrl(), "/");

        if ($includeVersion) {
            $version = $this->config->getApiVersion();
            return $baseUrl . '/' . $version . $path . '?newErrors=true&failedOrderIds=true';
        }

        return $baseUrl . $path . '?newErrors=true';
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus(): array
    {
        if ($this->config->getIsEnabled()) {
            return $this->status();
        }

        return [];
    }

    /**
     * Make order status API request.
     *
     * @return array
     */
    private function status(): array
    {
        $this->getCurlClient()->get($this->getRequestUrl(self::ORDERS_STATUS_PATH));

        if ($this->getCurlClient()->getStatus() === 200) {
            try {
                return json_decode(
                    $this->getCurlClient()->getBody(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Exception $e) {
                $this->logger->error(__('Orders status error: %1', $e->getMessage()));
            }
        }

        return [__('Error fetching orders status.')];
    }

    /**
     * Handle success.
     *
     * @param string $orderIds
     *
     * @return bool
     */
    private function handleSuccess(string $orderIds): bool
    {
        $body = $this->getCurlBody();

        if (isset($body['status']) && $body['status'] === self::ORDER_STATUS_VALID) {
            $this->logger->info(__('Orders "#%1" is valid and can be sent to Klar.', $orderIds));

            return true;
        }

        return false;
    }

    /**
     * Get curl request response body.
     *
     * @return array
     */
    private function getCurlBody(): array
    {
        try {
            return json_decode(
                $this->getCurlClient()->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Exception $e) {
            $this->logger->info(__('Error getting body from request response: %1', $e->getMessage()));
        }

        return [];
    }

    /**
     * Handle error.
     *
     * @param string $orderIds
     *
     * @return bool
     */
    private function handleError(string $orderIds): bool
    {
        $body = $this->getCurlBody();

        if (isset($body['status'], $body['errors']) && $body['status'] === self::ORDER_STATUS_INVALID) {
            foreach ($body['errors'] as $errorMessage) {
                $this->logger->info($errorMessage);
            }

            $this->logger->info(__('Failed to validate orders "#%1":', $orderIds));

            return false;
        }

        return false;
    }

    /**
     * Make order json request.
     *
     * @param SalesOrderInterface[] $salesOrders
     *
     * @return int
     */
    private function json(array $salesOrders): int
    {
        $result = 0;
        $orderSummaries = [];
        foreach ($salesOrders as $order) {
            $orderSummaries[] = $order->getIncrementId() . ' (id:' . $order->getEntityId() . ')';
        }
        $orderLabel = implode(', ', $orderSummaries);
        $batchCount = count($salesOrders);

        $this->logger->info(__('Sending %1 order(s): %2', $batchCount, $orderLabel));

        $this->getCurlClient()->post(
            $this->getRequestUrl(self::ORDERS_JSON_PATH, true),
            $this->requestData
        );

        $this->lastError = '';
        $body = $this->getCurlBody();
        if (isset($body['status']) && $body['status'] === self::ORDER_STATUS_VALID) {
            $this->logger->info(__('OK — %1 order(s) accepted by Klar: %2', $batchCount, $orderLabel));
            $result = count($salesOrders);
        } elseif (isset($body['status']) && $body['status'] === self::ORDER_STATUS_INVALID) {
            $errorMessages = [];
            $this->logger->error(__('FAILED — %1 order(s) rejected by Klar: %2', $batchCount, $orderLabel));
            if (isset($body['orderIds']) && is_array($body['orderIds'])) {
                $this->logger->error(__('Failed order IDs: %1', implode(', ', $body['orderIds'])));
            }
            if (isset($body['errors']) && is_array($body['errors'])) {
                foreach ($body['errors'] as $error) {
                    if (is_string($error)) {
                        $errorMessages[] = $error;
                        $this->logger->error($error);
                    } elseif (is_array($error) && isset($error['message'])) {
                        $msg = ($error['key'] ?? 'unknown') . ': ' . $error['message'];
                        $errorMessages[] = $msg;
                        $this->logger->error($msg);
                    }
                }
            }
            $this->lastError = implode(' | ', $errorMessages) ?: 'Validation failed';
        } else {
            $httpStatus = $this->getCurlClient()->getStatus();
            $this->lastError = 'HTTP ' . $httpStatus;
            $this->logger->error(__('FAILED — Could not reach Klar API. HTTP %1. Orders: %2', $httpStatus, $orderLabel));
        }

        return $result;
    }
}
