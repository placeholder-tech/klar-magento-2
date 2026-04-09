<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class TestConnectionButton extends Field
{
    protected $_template = 'PlaceholderTech_Klar::system/config/test_connection.phtml';

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('klar/sync/testConnection');
    }

    public function getButtonHtml(): string
    {
        $button = $this->getLayout()
            ->createBlock(Button::class)
            ->setData([
                'id' => 'klar_test_connection',
                'label' => __('Test Connection'),
            ]);

        return $button->toHtml();
    }
}
