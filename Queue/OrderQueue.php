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
        } catch (\Throwable $exception) {
            // Catch \Throwable (not just Exception) so PHP 8 TypeError / Error
            // from a buggy builder does not silently kill the consumer and
            // leave every order in the batch with sync=0 and NULL
            // error_message. Record the failure per order before re-throwing
            // so operators can see what went wrong.
            $result = false;
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
                // Fall through — at worst the row keeps its existing state,
                // but we must not swallow the original problem.
            }
        }

        if (!$result) {
            throw new Exception('#NEED_TO_RETRY#');
        }
    }
}
