<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Controller\Adminhtml\Sync;

use PlaceholderTech\Klar\Queue\OrderPublisher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;

class SyncFailed extends Action
{
    private OrderPublisher $orderPublisher;
    private JsonFactory $jsonFactory;
    private ResourceConnection $resourceConnection;

    public function __construct(
        Context $context,
        OrderPublisher $orderPublisher,
        JsonFactory $jsonFactory,
        ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
        $this->orderPublisher = $orderPublisher;
        $this->jsonFactory = $jsonFactory;
        $this->resourceConnection = $resourceConnection;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($connection->getTableName('klar_order_attributes'), ['order_id'])
                ->where('sync = 0');

            $failedIds = $connection->fetchCol($select);

            if (empty($failedIds)) {
                return $result->setData(['success' => true, 'message' => __('No failed orders to re-sync.')]);
            }

            $this->orderPublisher->publish(array_map('intval', $failedIds));
            return $result->setData([
                'success' => true,
                'message' => __('%1 failed orders scheduled for re-sync.', count($failedIds))
            ]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
