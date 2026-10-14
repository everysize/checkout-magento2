<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model;

use Everysize\Checkout\Model\Api\ApiUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Module configuration. Credentials are stored encrypted in core_config_data
 * (they are never readable from the database alone); all settings live in the
 * default scope only — one shop, one everysize checkout connection.
 */
class Settings implements SettingsInterface
{
    public const CONFIG_PATH_CLIENT_ID = 'everysize_checkout/general/client_id';
    public const CONFIG_PATH_CLIENT_SECRET = 'everysize_checkout/general/client_secret';
    public const CONFIG_PATH_IMPORT_STORE_ID = 'everysize_checkout/import/store_id';
    public const CONFIG_PATH_IMPORT_ORDER_STATUS = 'everysize_checkout/import/order_status';
    public const CONFIG_PATH_IMPORT_SEND_ORDER_EMAIL = 'everysize_checkout/import/send_order_email';
    public const CONFIG_PATH_EXPORT_CARRIER = 'everysize_checkout/export/carrier';
    public const CONFIG_PATH_REFUND_AUTO_REFUND_DECLINE = 'everysize_checkout/refund/auto_refund_decline';
    public const CONFIG_PATH_REFUND_AUTO_REFUND_SINCE = 'everysize_checkout/refund/auto_refund_since';
    public const CONFIG_PATH_SYNC_ENABLED = 'everysize_checkout/sync/enabled';

    /** Carriers listed in the everysize API documentation; the API accepts any value. */
    public const CARRIERS = [
        'Cargo', 'Der Courier', 'Deutsche Post', 'DHL', 'DPD', 'eparcel', 'FedEx', 'GdSK', 'GLS', 'GO!',
        'Hermes', 'iloxx', 'Midway', 'Noxxs Logistic', 'paket.ag', 'primeMail', 'TOMBA', 'UPS',
    ];

    private const CARRIER_MAX_LENGTH = 31;

    private ScopeConfigInterface $scopeConfig;
    private WriterInterface $writer;
    private EncryptorInterface $encryptor;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $writer,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->writer = $writer;
        $this->encryptor = $encryptor;
    }

    public function getClientId(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::CONFIG_PATH_CLIENT_ID, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));
    }

    public function getClientSecret(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::CONFIG_PATH_CLIENT_SECRET, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));

        return $value === '' ? '' : $this->encryptor->decrypt($value);
    }

    public function getCarrier(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::CONFIG_PATH_EXPORT_CARRIER, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));
    }

    public function isAutoRefundDecline(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_REFUND_AUTO_REFUND_DECLINE, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function getAutoRefundSince(): ?string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::CONFIG_PATH_REFUND_AUTO_REFUND_SINCE, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));

        return $value !== '' ? $value : null;
    }

    /** Without credentials the module makes no API calls at all. */
    public function isConfigured(): bool
    {
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    /** Store view imported orders are placed in (0 = default store view). */
    public function getStoreId(): int
    {
        return (int) $this->scopeConfig->getValue(self::CONFIG_PATH_IMPORT_STORE_ID, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /** Status of imported orders (of the processing state); default "processing". */
    public function getOrderStatus(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::CONFIG_PATH_IMPORT_ORDER_STATUS, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));

        return $value !== '' ? $value : 'processing';
    }

    public function isSendOrderEmail(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_IMPORT_SEND_ORDER_EMAIL, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /** Automatic sync (cron) on or off. */
    public function isSyncEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_SYNC_ENABLED, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /**
     * Base URL of the API. Without EVERYSIZE_CHECKOUT_API_URL this is always
     * production; an invalid value is an error, never a fallback to production.
     *
     * @throws Api\ApiException
     */
    public function getApiUrl(): string
    {
        return ApiUrl::resolve();
    }

    public function setCredentials(string $clientId, string $clientSecret): void
    {
        $this->writer->save(self::CONFIG_PATH_CLIENT_ID, $clientId, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $this->writer->save(self::CONFIG_PATH_CLIENT_SECRET, $this->encryptor->encrypt($clientSecret), ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /** Removes client ID and secret — the module then makes no API calls at all. */
    public function removeCredentials(): void
    {
        $this->writer->delete(self::CONFIG_PATH_CLIENT_ID, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $this->writer->delete(self::CONFIG_PATH_CLIENT_SECRET, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function setCarrier(string $carrier): void
    {
        if ($carrier === '' || mb_strlen($carrier) > self::CARRIER_MAX_LENGTH) {
            throw new \InvalidArgumentException('carrier must be 1-31 characters');
        }
        $this->writer->save(self::CONFIG_PATH_EXPORT_CARRIER, $carrier, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function setAutoRefundSince(string $since): void
    {
        $this->writer->save(self::CONFIG_PATH_REFUND_AUTO_REFUND_SINCE, $since, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }
}
