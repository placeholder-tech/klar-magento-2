<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Controller\Adminhtml\Sync;

use PlaceholderTech\Klar\Api\Data\ApiInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class TestConnection extends Action
{
    private ApiInterface $api;
    private JsonFactory $jsonFactory;

    public function __construct(
        Context $context,
        ApiInterface $api,
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
            $status = $this->api->getStatus();
            if (!empty($status) && isset($status['total'])) {
                return $result->setData([
                    'success' => true,
                    'message' => __('Connected. %1 orders uploaded to Klar.', $status['total'])
                ]);
            }

            return $result->setData([
                'success' => false,
                'message' => __('Could not connect. Check API URL and token.')
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
