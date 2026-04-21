<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Queue;

use Exception;
use PlaceholderTech\Klar\Model\Api;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\ResourceConnection;

class OrderQueue
{
    private Api $api;
    private Json $jsonSerializer;
    private AdapterInterface $connection;

    public function __construct(
        Api $api,
        Json $jsonSerializer,
        ResourceConnection $resourceConnection
    ) {
        $this->api = $api;
        $this->jsonSerializer = $jsonSerializer;
        $this->connection = $resourceConnection->getConnection();
    }

    public function process(OperationInterface $operation): void
    {
        $serializedData = $operation->getSerializedData();
        $ids = $this->jsonSerializer->unserialize($serializedData);
        $ids = array_map('intval', (array)$ids);
        $totalCount = count($ids);
        $needRetry = false;

        try {
            $acceptedCount = $this->api->send($ids);
            $failedIds = array_map('intval', $this->api->getLastFailedIds());
            $errorMessage = $this->api->getLastError() ?: null;

            // Determine per-ID outcome:
            // - If the API reported which IDs were rejected (HTTP 207 multi-status),
            //   flag only those as failed and the rest as synced.
            // - Otherwise fall back to batch-level success/failure inferred from
            //   the accepted count.
            if ($failedIds) {
                $failedSet = array_flip($failedIds);
                $successIds = array_values(array_filter($ids, static fn(int $id) => !isset($failedSet[$id])));
            } elseif ($acceptedCount === $totalCount) {
                $successIds = $ids;
                $failedIds = [];
            } else {
                $successIds = [];
                $failedIds = $ids;
            }

            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $tableName = $this->connection->getTableName('klar_order_attributes');

            foreach ($successIds as $id) {
                $this->connection->insertOnDuplicate(
                    $tableName,
                    [
                        'order_id' => (int)$id,
                        'sync' => 1,
                        'synced_at' => $now,
                        'error_message' => null,
                    ],
                    ['sync', 'synced_at', 'error_message']
                );
            }

            foreach ($failedIds as $id) {
                $this->connection->insertOnDuplicate(
                    $tableName,
                    [
                        'order_id' => (int)$id,
                        'sync' => 0,
                        'synced_at' => $now,
                        'error_message' => $errorMessage,
                    ],
                    ['sync', 'synced_at', 'error_message']
                );
            }

            // Only retry transient failures (5xx, rate limit, network).
            // Deterministic failures (validation, auth) can't succeed without
            // code/data changes — retrying just hammers the API.
            $needRetry = !empty($failedIds) && $this->api->isLastResponseRetryable();
        } catch (\Throwable $exception) {
            // Catch \Throwable (not just Exception) so PHP 8 TypeError / Error
            // from a buggy builder does not silently kill the consumer.
            // Record the failure per order before re-throwing.
            $needRetry = true;
            try {
                $now = (new \DateTime())->format('Y-m-d H:i:s');
                $tableName = $this->connection->getTableName('klar_order_attributes');
                foreach ($ids as $id) {
                    $this->connection->insertOnDuplicate(
                        $tableName,
                        [
                            'order_id' => (int)$id,
                            'sync' => 0,
                            'synced_at' => $now,
                            'error_message' => $exception->getMessage(),
                        ],
                        ['sync', 'synced_at', 'error_message']
                    );
                }
            } catch (\Throwable $dbError) {
                // Fall through — at worst the row keeps its existing state.
            }
        }

        if ($needRetry) {
            throw new Exception('#NEED_TO_RETRY#');
        }
    }
}
