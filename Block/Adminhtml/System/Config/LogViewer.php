<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field as ConfigFormField;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class LogViewer extends ConfigFormField
{
    private const LOG_FILE = 'klar/klar.log';
    private const MAX_LINES = 100;

    private DirectoryList $directoryList;

    public function __construct(
        Context $context,
        DirectoryList $directoryList,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
        $this->directoryList = $directoryList;
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $logPath = $this->directoryList->getPath('log') . '/' . self::LOG_FILE;

        if (!file_exists($logPath)) {
            return '<div style="color:#666;font-style:italic;">' . __('No log file found.') . '</div>';
        }

        $lines = $this->tailFile($logPath, self::MAX_LINES);

        if (empty($lines)) {
            return '<div style="color:#666;font-style:italic;">' . __('Log file is empty.') . '</div>';
        }

        $content = $this->escapeHtml(implode("\n", $lines));

        return '<div style="max-height:400px;overflow:auto;background:#1e1e1e;color:#d4d4d4;'
            . 'border:1px solid #333;border-radius:4px;padding:12px;font-family:monospace;font-size:12px;'
            . 'white-space:pre-wrap;word-break:break-all;line-height:1.5;">'
            . $content
            . '</div>'
            . '<div style="margin-top:4px;color:#666;font-size:11px;">'
            . __('Showing last %1 lines from %2', self::MAX_LINES, self::LOG_FILE)
            . '</div>';
    }

    private function tailFile(string $filePath, int $lines): array
    {
        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $result = [];

        $file->seek($startLine);
        while (!$file->eof()) {
            $line = rtrim($file->current(), "\n\r");
            if ($line !== '') {
                $result[] = $line;
            }
            $file->next();
        }

        return $result;
    }
}
