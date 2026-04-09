<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Controller\Adminhtml\Sync;

use PlaceholderTech\Klar\Queue\OrderPublisher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class SyncRange extends Action
{
    private OrderPublisher $orderPublisher;
    private JsonFactory $jsonFactory;

    public function __construct(
        Context $context,
        OrderPublisher $orderPublisher,
        JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
        $this->orderPublisher = $orderPublisher;
        $this->jsonFactory = $jsonFactory;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $from = $this->getRequest()->getParam('from');
            $to = $this->getRequest()->getParam('to');

            $fromDate = $from ? \DateTime::createFromFormat('Y-m-d', $from) : null;
            $toDate = $to ? \DateTime::createFromFormat('Y-m-d', $to) : null;

            $ids = $this->orderPublisher->getAllIds($fromDate ?: null, $toDate ?: null);
            $count = count($ids);

            if ($count === 0) {
                return $result->setData(['success' => true, 'message' => __('No orders found in the selected date range.')]);
            }

            $this->orderPublisher->publish($ids);
            return $result->setData(['success' => true, 'message' => __('%1 orders scheduled for sync.', $count)]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
