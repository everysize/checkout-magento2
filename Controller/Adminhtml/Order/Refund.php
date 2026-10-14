<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Adminhtml\Order;

use Everysize\Checkout\Model\Api\ApiException;
use Everysize\Checkout\Model\ClientFactory;
use Everysize\Checkout\Model\OrderLinks;
use Everysize\Checkout\Model\RefundMessages;
use Everysize\Checkout\Model\RefundAmount;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Refund through everysize from the order tab: the External API refunds the
 * PayPal capture on the merchant's behalf. Validates the amount and a fresh
 * idempotency key per page view, reports the refund in everysize_report and
 * redirects back to the order with a message. A duplicate (same refund id or
 * key) answers with "already triggered", nothing is refunded twice.
 */
class Refund extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Everysize_Checkout::refund';

    private ClientFactory $clientFactory;
    private OrderLinks $links;
    private Validator $formKeyValidator;
    private OrderRepositoryInterface $orderRepository;

    public function __construct(
        Context $context,
        ClientFactory $clientFactory,
        OrderLinks $links,
        Validator $formKeyValidator,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->clientFactory = $clientFactory;
        $this->links = $links;
        $this->formKeyValidator = $formKeyValidator;
        $this->orderRepository = $orderRepository;
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id', 0);
        if ($orderId > 0) {
            $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
        } else {
            $redirect->setPath('sales/order/index');
        }

        if (!$this->formKeyValidator->validate($this->getRequest())) {
            $this->messageManager->addErrorMessage((string) __('Session expired, please reload the page.'));

            return $redirect;
        }

        $link = $orderId > 0 ? $this->links->getLink($orderId) : null;
        if ($link === null || $link['paypal_capture_id'] === '') {
            $this->messageManager->addErrorMessage((string) __('No refund via everysize is possible for this order.'));

            return $redirect;
        }

        $amount = RefundAmount::normalize((string) $this->getRequest()->getParam('refund_amount', ''));
        $key = (string) $this->getRequest()->getParam('refund_key', '');
        if ($amount === null) {
            $this->messageManager->addErrorMessage((string) __('Please enter an amount greater 0, e.g. 10.00. Thousands separators only together with decimals (1,234.00).'));

            return $redirect;
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) !== 1) {
            $this->messageManager->addErrorMessage((string) __('Session expired, please reload the page.'));

            return $redirect;
        }

        try {
            $refund = $this->clientFactory->create()->refund($link['everysize_order_id'], $amount, $key);
        } catch (ApiException $e) {
            $this->messageManager->addErrorMessage(RefundMessages::fromException($e));

            return $redirect;
        }

        // everysize answers a capture that is refunded completely already with 201 and this status, no refund ID.
        if (strtoupper((string) ($refund['status'] ?? '')) === 'ALREADY_REFUNDED') {
            $this->messageManager->addErrorMessage((string) __('The payment is already refunded completely.'));

            return $redirect;
        }

        $refundId = (string) ($refund['refundId'] ?? '');
        $reference = $refundId !== '' ? $refundId : $key;
        // The same idempotency key again (form sent twice) returns the same refund: record and announce it once.
        if (\in_array($reference, array_column($this->links->getReports($orderId), 'sku'), true)) {
            $this->messageManager->addSuccessMessage((string) __('This refund was already triggered (form sent twice). Nothing was refunded again.'));

            return $redirect;
        }

        $this->links->addReport($orderId, OrderLinks::KIND_REFUND, $reference, $amount, OrderLinks::RESULT_SENT);
        try {
            $currency = $this->orderRepository->get($orderId)->getOrderCurrencyCode();
        } catch (NoSuchEntityException $e) {
            $currency = '';
        }
        $this->messageManager->addSuccessMessage((string) __(
            'Refund of %1 triggered (status at PayPal: %2).',
            $this->formatAmount((float) $amount, $currency),
            (string) ($refund['status'] ?? '')
        ));

        return $redirect;
    }

    private function formatAmount(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '') . ($currency !== '' ? ' ' . $currency : '');
    }
}
