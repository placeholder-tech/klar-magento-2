<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Controller\Adminhtml\Sync;

use PlaceholderTech\Klar\Model\Api;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Preview extends Action
{
    private Api $api;
    private JsonFactory $jsonFactory;

    public function __construct(
        Context $context,
        Api $api,
        JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
        $this->api = $api;
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

            $json = $this->api->getJsonDataForOrders([(int)$orderId]);
            return $result->setData(['success' => true, 'json' => $json]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
