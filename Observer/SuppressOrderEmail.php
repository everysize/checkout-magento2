<?php

declare(strict_types=1);

namespace Everysize\Checkout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * The everysize import sends its order confirmation after its own commit
 * (or not at all) — Quote\Observer\SubmitObserver would send it earlier,
 * inside submit(), while the import transaction is still open (and a second
 * time after the commit). The flag the SubmitObserver honours is therefore
 * turned off as long as the quote carries the everysize import mark.
 */
class SuppressOrderEmail implements ObserverInterface
{
    public const QUOTE_FLAG = 'everysize_import';

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');
        if (!$quote instanceof Quote || !$order instanceof Order) {
            return;
        }
        if ($quote->getData(self::QUOTE_FLAG) !== true) {
            return;
        }
        $order->setCanSendNewEmailFlag(false);
    }
}
