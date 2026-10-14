<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Config\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Every time the automatic full refund is switched ON, the start time is
 * reset to now — cancellations older than that are never refunded
 * automatically ("switched on now" semantics). Only an explicit
 * save to ON counts; the data patch sets the installation time initially.
 */
class AutoRefundSince extends Value
{
    private WriterInterface $configWriter;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        WriterInterface $configWriter,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->configWriter = $configWriter;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave(): AutoRefundSince
    {
        if ($this->getValue() !== '1') {
            return $this;
        }
        // Switched from off to on (or on again): start from now, not from a
        // stale timestamp that would auto-refund older cancellations.
        $isSwitchedOn = $this->isValueChanged() || $this->getOldValue() !== '1';
        $since = $this->_config->getValue(
            \Everysize\Checkout\Model\Settings::CONFIG_PATH_REFUND_AUTO_REFUND_SINCE,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        if ($isSwitchedOn || $since === null || trim((string) $since) === '') {
            $this->configWriter->save(
                \Everysize\Checkout\Model\Settings::CONFIG_PATH_REFUND_AUTO_REFUND_SINCE,
                gmdate('Y-m-d H:i:s'),
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );
        }

        return $this;
    }
}
