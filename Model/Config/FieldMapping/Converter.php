<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Config\FieldMapping;

use Magento\Framework\Config\ConverterInterface;

class Converter implements ConverterInterface
{
    private const GROUPS = ['line_item', 'customer', 'optional_identifiers', 'order'];

    public function convert($source): array
    {
        $result = [];

        foreach (self::GROUPS as $group) {
            $result[$group] = [];
            $groupNodes = $source->getElementsByTagName($group);

            foreach ($groupNodes as $groupNode) {
                /** @var \DOMElement $groupNode */
                $fieldNodes = $groupNode->getElementsByTagName('field');
                foreach ($fieldNodes as $fieldNode) {
                    /** @var \DOMElement $fieldNode */
                    $name = $fieldNode->getAttribute('name');
                    $result[$group][$name] = [
                        'name' => $name,
                        'source' => $fieldNode->getAttribute('source'),
                        'code' => $fieldNode->getAttribute('code') ?: null,
                        'type' => $fieldNode->getAttribute('type') ?: 'text',
                        'prefix' => $fieldNode->getAttribute('prefix') ?: null,
                        'separator' => $fieldNode->hasAttribute('separator')
                            ? $fieldNode->getAttribute('separator')
                            : ', ',
                    ];
                }
            }
        }

        return $result;
    }
}
