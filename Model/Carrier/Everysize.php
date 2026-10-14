<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Carrier;

/**
 * Shipping method of imported everysize orders: the price is the
 * shippingCosts the customer paid at everysize, set on the quote during the
 * import. The carrier is never offered in the storefront (active = 0 and
 * isAvailable false) — collectRates only exists to satisfy the interface.
 */
class Everysize extends \Magento\Shipping\Model\Carrier\AbstractCarrier implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /** @var string */
    protected $_code = 'everysize';

    /** @var bool */
    protected $_isFixed = true;

    /** @var string */
    protected $_defaultConditionName = 'package_weight';

    /**
     * The carrier is never used for shipping rates — the import assigns the
     * rate itself. Never offered in the checkout.
     */
    public function isAvailable($countryId = null): bool
    {
        return false;
    }

    /**
     * @return false never: the module assigns the rate itself
     */
    public function collectRates(\Magento\Quote\Model\Quote\Address\RateRequest $request)
    {
        return false;
    }

    /**
     * @return string[] everysize => everysize
     */
    public function getAllowedMethods(): array
    {
        return ['everysize' => $this->getConfigData('name')];
    }
}
