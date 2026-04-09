<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Controller\Adminhtml\Sync;

use PlaceholderTech\Klar\Queue\OrderPublisher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class SyncOrder extends Action
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
            $orderId = $this->getRequest()->getParam('order_id');
            if (!$orderId || !is_numeric($orderId)) {
                return $result->setData(['success' => false, 'message' => __('Please provide a valid numeric order ID.')]);
            }

            $this->orderPublisher->publish([(int)$orderId]);
            return $result->setData(['success' => true, 'message' => __('Order #%1 scheduled for sync.', $orderId)]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
