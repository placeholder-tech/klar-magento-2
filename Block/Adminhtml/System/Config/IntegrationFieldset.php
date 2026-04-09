<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Backend\Block\Context;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class IntegrationFieldset extends Fieldset
{
    private File $file;

    public function __construct(
        Context $context,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Magento\Framework\View\Helper\Js $jsHelper,
        File $file,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $authSession, $jsHelper, $data, $secureRenderer);
        $this->file = $file;
    }

    protected function _getHeaderTitleHtml($element): string
    {
        $logoUrl = $this->getViewFileUrl('PlaceholderTech_Klar::images/klar_icon_logo.png');
        $logoHtml = '<img src="' . $this->escapeUrl($logoUrl) . '" alt="Klar" '
            . 'style="height:28px;vertical-align:middle;margin-right:10px;border-radius:4px;" />';

        $version = $this->getModuleVersion();
        $versionHtml = $version
            ? '<span style="font-size:11px;color:#999;font-weight:normal;margin-left:8px;">v' . $this->escapeHtml($version) . '</span>'
            : '';

        $html = '<a id="' . $element->getHtmlId() . '-head" '
            . 'href="#' . $element->getHtmlId() . '-link" '
            . 'onclick="Fieldset.toggleCollapse(\'' . $element->getHtmlId() . '\', \''
            . $this->getUrl('*/*/state') . '\'); return false;">'
            . $logoHtml
            . $element->getLegend()
            . $versionHtml
            . '</a>';

        return $html;
    }

    private function getModuleVersion(): ?string
    {
        $composerJsonPath = dirname(__DIR__, 4) . '/composer.json';
        if ($this->file->isExists($composerJsonPath)) {
            $content = $this->file->fileGetContents($composerJsonPath);
            $data = json_decode($content, true);
            return $data['version'] ?? null;
        }
        return null;
    }
}
