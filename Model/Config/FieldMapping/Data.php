<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Config\FieldMapping;

use Magento\Framework\Config\Data as ConfigData;
use Magento\Framework\Config\ReaderInterface;
use Magento\Framework\Config\CacheInterface;

class Data extends ConfigData
{
    public function __construct(
        ReaderInterface $reader,
        CacheInterface $cache,
        string $cacheId = 'klar_field_mapping',
        ?\Magento\Framework\Serialize\SerializerInterface $serializer = null
    ) {
        parent::__construct($reader, $cache, $cacheId, $serializer);
    }

    /**
     * Get all field definitions for a given group (e.g. 'line_item').
     *
     * @param string $group
     * @return array<string, array{name:string,source:string,code:?string,type:string,prefix:?string}>
     */
    public function getFieldsForGroup(string $group): array
    {
        return $this->get($group, []);
    }
}
