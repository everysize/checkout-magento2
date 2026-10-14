<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Method;

use Everysize\Checkout\Model\Api\ApiException;
use Everysize\Checkout\Model\ClientFactory;
use Everysize\Checkout\Model\OrderLinks;
use Everysize\Checkout\Model\RefundMessages;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\Logger;

/**
 * The payment method imported everysize orders carry: "everysize (PayPal)".
 *
 * Paid at everysize (PayPal, on the merchant's behalf): the import creates a
 * paid invoice with the PayPal capture ID as the transaction id. It is not
 * selectable in the storefront checkout and not in the admin order creation —
 * the flags live on the class, they are not configuration.
 *
 * Refunds: a credit memo created from the invoice offers "Refund" (online) —
 * that refunds the credit memo's amount through everysize (External API →
 * PayPal), like the form in the order tab. "Refund Offline" only books the
 * credit memo and moves no money (for refunds made directly in PayPal).
 * Magento calls refund() for both because the method is offline; the credit
 * memo's do_transaction flag tells them apart.
 */
class Everysize extends \Magento\Payment\Model\Method\AbstractMethod
{
    /** @var string */
    protected $_code = 'everysize';

    /** @var bool */
    protected $_isOffline = true;

    /** @var bool */
    protected $_isGateway = false;

    /** @var bool */
    protected $_canUseCheckout = false;

    /** @var bool */
    protected $_canUseInternal = false;

    /** @var bool */
    protected $_canOrder = false;

    /** @var bool */
    protected $_canAuthorize = false;

    /** @var bool */
    protected $_canCapture = false;

    /** @var bool */
    protected $_canCapturePartial = false;

    /** @var bool */
    protected $_canCaptureOnce = false;

    /** @var bool */
    protected $_canVoid = false;

    /** @var bool */
    protected $_canRefund = true;

    /** @var bool */
    protected $_canRefundInvoicePartial = true;

    private ClientFactory $clientFactory;
    private OrderLinks $links;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentHelper $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ClientFactory $clientFactory,
        OrderLinks $links,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?DirectoryHelper $directory = null
    ) {
        $this->clientFactory = $clientFactory;
        $this->links = $links;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data,
            $directory
        );
    }

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amount base currency amount of the credit memo
     * @return $this
     * @throws LocalizedException everysize/PayPal refused — Magento then does not create the credit memo
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $creditmemo = $payment->getCreditmemo();
        if ($creditmemo === null || !$creditmemo->getDoTransaction()) {
            // "Refund Offline": the credit memo is booked, no money moves.
            return $this;
        }

        $order = $payment->getOrder();
        $orderId = (int) $order->getId();
        $link = $this->links->getLink($orderId);
        if ($link === null || $link['paypal_capture_id'] === '') {
            throw new LocalizedException(__('No refund via everysize is possible for this order.'));
        }

        $value = number_format((float) $amount, 2, '.', '');
        if ((float) $value <= 0.0) {
            return $this;
        }
        // A fresh key per credit memo: one credit memo is one refund, a retry after an error is a new attempt.
        $key = 'creditmemo-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $order->getIncrementId()) . '-' . bin2hex(random_bytes(8));

        try {
            $refund = $this->clientFactory->create()->refund($link['everysize_order_id'], $value, $key);
        } catch (ApiException $e) {
            throw new LocalizedException(__('%1', RefundMessages::fromException($e)));
        }

        if (strtoupper((string) ($refund['status'] ?? '')) === 'ALREADY_REFUNDED') {
            throw new LocalizedException(__('The payment is already refunded completely at everysize. If the money went back another way, use "Refund Offline".'));
        }

        $refundId = (string) ($refund['refundId'] ?? '');
        $this->links->addReport($orderId, OrderLinks::KIND_REFUND, $refundId !== '' ? $refundId : $key, $value, OrderLinks::RESULT_SENT);
        if ($refundId !== '') {
            $payment->setTransactionId($refundId);
        }
        $payment->setIsTransactionClosed(true);

        return $this;
    }
}
