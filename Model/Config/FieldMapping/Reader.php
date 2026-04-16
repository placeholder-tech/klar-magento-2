<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Config\FieldMapping;

use Magento\Framework\Config\Reader\Filesystem;

class Reader extends Filesystem
{
    /**
     * @var array
     */
    protected $_idAttributes = [
        '/config/line_item/field' => 'name',
        '/config/customer/field' => 'name',
        '/config/optional_identifiers/field' => 'name',
        '/config/order/field' => 'name',
    ];
}
