<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Helper;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Filesystem\Driver\File;

class Config extends AbstractHelper
{
    public const WEIGHT_UNIT_LBS = 'lbs';
    public const WEIGHT_UNIT_KGS = 'kgs';
    private const CONFIG_PATH_ENABLED = 'klar/integration/enabled';
    private const CONFIG_PATH_API_URL = 'klar/integration/api_url';
    private const CONFIG_PATH_API_VERSION = 'klar/integration/api_version';
    private const CONFIG_PATH_API_TOKEN = 'klar/integration/api_token';

    private const CONFIG_PATH_SEND_EMAIL = 'klar/integration/send_email';
    private const CONFIG_PATH_PUBLIC_KEY = 'klar/integration/public_key';
    private const CONFIG_PATH_BATCH_SIZE = 'klar/integration/batch_size';
    private const CONFIG_PATH_WEIGHT_UNIT = 'general/locale/weight_unit';

    private Encrypted $encrypted;
    private File $file;

    /**
     * Config constructor.
     *
     * @param Context $context
     * @param Encrypted $encrypted
     */
    public function __construct(
        Context $context,
        Encrypted $encrypted,
        File $file
    ) {
        parent::__construct($context);
        $this->encrypted = $encrypted;
        $this->file = $file;
    }

    /**
     * Get the current version from composer.json.
     *
     * @return string|null
     */
    public function getCurrentVersion(): ?string
    {
        $composerJsonPath = __DIR__ . '/../composer.json';
        if ($this->file->isExists($composerJsonPath)) {
            $content = $this->file->fileGetContents($composerJsonPath);
            $data = json_decode($content, true);
            return $data['version'] ?? null;
        }
        return null;
    }

    /**
     * Get "Klar > Integration > Enabled" config value.
     *
     * @return bool
     */
    public function getIsEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::CONFIG_PATH_ENABLED);
    }

    /**
     * Get "Klar > Integration > API URL" config value.
     *
     * @return string|null
     */
    public function getApiUrl(): ?string
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_API_URL);
    }

    /**
     * Get "Klar > Integration > API Token" config value.
     *
     * @return string|null
     */
    public function getApiToken(): ?string
    {
        $tokenEncrypted = $this->scopeConfig->getValue(self::CONFIG_PATH_API_TOKEN);

        return $this->encrypted->processValue($tokenEncrypted);
    }

    /**
     * Get "Klar > Integration > Public key" config value.
     *
     * @return string|null
     */
    public function getPublicKey(): ?string
    {
        $publicKeyEncrypted = $this->scopeConfig->getValue(self::CONFIG_PATH_PUBLIC_KEY);

        return $this->encrypted->processValue($publicKeyEncrypted);
    }

    /**
     * Get "Klar > Integration > Send Email" config value.
     *
     * @return bool
     */
    public function getSendEmail(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::CONFIG_PATH_SEND_EMAIL);
    }

    /**
     * Get "Klar > Integration > API Version" config value.
     *
     * @return string|null
     */
    public function getApiVersion(): ?string
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_API_VERSION);
    }

    /**
     * Get batch size for API uploads.
     *
     * @return int
     */
    public function getBatchSize(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::CONFIG_PATH_BATCH_SIZE);
        if ($value < 1 || $value > 1000) {
            return 250;
        }
        return $value;
    }

    /**
     * Get weight unit.
     *
     * @return mixed
     */
    public function getWeightUnit()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_WEIGHT_UNIT, ScopeInterface::SCOPE_STORE);
    }
}
