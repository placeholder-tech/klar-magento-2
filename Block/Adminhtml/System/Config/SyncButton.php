<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class SyncButton extends Field
{
    protected $_template = 'PlaceholderTech_Klar::system/config/sync_panel.phtml';

    private ResourceConnection $resourceConnection;

    public function __construct(
        Context $context,
        ResourceConnection $resourceConnection,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
        $this->resourceConnection = $resourceConnection;
    }

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getSyncAllUrl(): string
    {
        return $this->getUrl('klar/sync/ajax');
    }

    public function getSyncRangeUrl(): string
    {
        return $this->getUrl('klar/sync/syncRange');
    }

    public function getSyncOrderUrl(): string
    {
        return $this->getUrl('klar/sync/syncOrder');
    }

    public function getSyncFailedUrl(): string
    {
        return $this->getUrl('klar/sync/syncFailed');
    }

    public function getPreviewUrl(): string
    {
        return $this->getUrl('klar/sync/preview');
    }

    public function getTotalOrderCount(): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()->from($connection->getTableName('sales_order'), ['COUNT(*)'])
        );
    }

    public function getFailedOrderCount(): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('klar_order_attributes'), ['COUNT(*)'])
                ->where('sync = 0')
        );
    }
}
