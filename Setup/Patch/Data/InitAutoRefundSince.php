<?php

declare(strict_types=1);

namespace Everysize\Checkout\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Sets the start of the automatic full refund ("Storno vor dem Versand wird
 * erstattet") to the installation time: older cancellations are never refunded
 * automatically — a shop installing the module in 2026 must not get refunds
 * for cancellations from before the module existed.
 */
class InitAutoRefundSince implements DataPatchInterface
{
    private \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup;
    private \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;
    private \Magento\Framework\App\Config\Storage\WriterInterface $writer;

    public function __construct(
        \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $writer
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->scopeConfig = $scopeConfig;
        $this->writer = $writer;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): InitAutoRefundSince
    {
        // UTC, like every timestamp the module stores.
        $now = gmdate('Y-m-d H:i:s');
        $this->moduleDataSetup->getConnection()->startSetup();
        $existing = $this->scopeConfig->getValue(
            \Everysize\Checkout\Model\Settings::CONFIG_PATH_REFUND_AUTO_REFUND_SINCE,
            \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        if ($existing === null || trim((string) $existing) === '') {
            $this->writer->save(
                \Everysize\Checkout\Model\Settings::CONFIG_PATH_REFUND_AUTO_REFUND_SINCE,
                $now,
                \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );
        }
        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
}
