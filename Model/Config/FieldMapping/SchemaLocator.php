<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Config\FieldMapping;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;

class SchemaLocator implements SchemaLocatorInterface
{
    private string $schemaPath;
    private string $perFileSchemaPath;

    public function __construct(\Magento\Framework\Module\Dir\Reader $moduleReader)
    {
        $etcDir = $moduleReader->getModuleDir(Dir::MODULE_ETC_DIR, 'PlaceholderTech_Klar');
        $this->schemaPath = $etcDir . '/klar_field_mapping_merged.xsd';
        $this->perFileSchemaPath = $etcDir . '/klar_field_mapping.xsd';
    }

    public function getSchema(): string
    {
        return $this->schemaPath;
    }

    public function getPerFileSchema(): string
    {
        return $this->perFileSchemaPath;
    }
}
