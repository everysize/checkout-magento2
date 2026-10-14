<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model;

/**
 * Module configuration, reduced to what the unit-tested core needs. The
 * Magento implementation (Model/Settings.php) wraps ScopeConfig + Encryptor +
 * the config writer; the tests use a fake.
 */
interface SettingsInterface
{
    public function getClientId(): string;

    public function getClientSecret(): string;

    public function getCarrier(): string;

    /** Refund the full amount when an order is cancelled before shipping. */
    public function isAutoRefundDecline(): bool;

    /** Cancellations reported from this time on are refunded automatically (null = never set). */
    public function getAutoRefundSince(): ?string;

    /** Without credentials the module makes no API calls at all. */
    public function isConfigured(): bool;
}
