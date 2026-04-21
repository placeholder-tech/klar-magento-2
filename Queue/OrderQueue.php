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

        $result = false;
        $shouldRetry = true;

        try {
            $result = (bool) $this->api->send($ids);
            $syncValue = (int)$result;
            $errorMessage = $result ? null : $this->api->getLastError();
            $now = (new \DateTime())->format('Y-m-d H:i:s');

            $tableName = $this->connection->getTableName('klar_order_attributes');
            foreach ($ids as $id) {
                $this->connection->insertOnDuplicate(
                    $tableName,
                    [
                        'order_id' => (int)$id,
                        'sync' => $syncValue,
                        'synced_at' => $now,
                        'error_message' => $errorMessage,
                    ],
                    ['sync', 'synced_at', 'error_message']
                );
            }

            // Don't retry deterministic failures (validation, auth, 4xx).
            // They cannot succeed without a code or data change, and re-queueing
            // immediately just hammers the Klar API until it gives up or we do.
            $shouldRetry = !$result && $this->api->isLastResponseRetryable();
        } catch (Exception $exception) {
            $result = false;
            // Local error (DB, serialization). Retry — it may be transient.
            $shouldRetry = true;
        }

        if (!$result && $shouldRetry) {
            throw new Exception('#NEED_TO_RETRY#');
        }
    }
}
