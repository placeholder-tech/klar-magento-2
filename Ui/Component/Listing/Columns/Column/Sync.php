<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Ui\Component\Listing\Columns\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class Sync extends Column
{
    /**
     * {@inheritDoc}
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                if (isset($item['klar_sync'])) {
                    if ($item['klar_sync']) {
                        $item['klar_sync'] = __('Yes');
                    } else {
                        $item['klar_sync'] = __('Failed');
                    }
                } else {
                    $item['klar_sync'] = __('No');
                }
            }
        }

        return $dataSource;
    }
}
