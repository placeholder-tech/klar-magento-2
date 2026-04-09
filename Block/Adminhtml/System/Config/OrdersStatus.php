<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field as ConfigFormField;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class OrdersStatus extends ConfigFormField
{
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

    protected function _getElementHtml(AbstractElement $element): string
    {
        $connection = $this->resourceConnection->getConnection();

        $totalOrders = (int)$connection->fetchOne(
            $connection->select()->from($connection->getTableName('sales_order'), ['COUNT(*)'])
        );

        $syncedCount = (int)$connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('klar_order_attributes'), ['COUNT(*)'])
                ->where('sync = 1')
        );

        $failedCount = (int)$connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('klar_order_attributes'), ['COUNT(*)'])
                ->where('sync = 0')
        );

        $neverSynced = $totalOrders - $syncedCount - $failedCount;

        $html = '<div style="line-height:2;">';
        $html .= '<div><b>' . __('Total Orders in Magento') . ':</b> ' . $totalOrders . '</div>';
        $html .= '<div style="color:#006400;"><b>' . __('Synced Successfully') . ':</b> ' . $syncedCount . '</div>';

        if ($failedCount > 0) {
            $html .= '<div style="color:#cc0000;"><b>' . __('Failed') . ':</b> ' . $failedCount . '</div>';
        } else {
            $html .= '<div><b>' . __('Failed') . ':</b> 0</div>';
        }

        if ($neverSynced > 0) {
            $html .= '<div style="color:#996600;"><b>' . __('Never Synced') . ':</b> ' . $neverSynced . '</div>';
        } else {
            $html .= '<div><b>' . __('Never Synced') . ':</b> 0</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
