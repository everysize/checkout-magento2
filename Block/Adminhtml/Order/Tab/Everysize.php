<?php

declare(strict_types=1);

namespace Everysize\Checkout\Block\Adminhtml\Order\Tab;

use Everysize\Checkout\Model\Api\ApiException;
use Everysize\Checkout\Model\Api\Client;
use Everysize\Checkout\Model\ClientFactory;
use Everysize\Checkout\Model\OrderLinks;
use Everysize\Checkout\Model\Settings;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

/**
 * The "everysize" tab of the order view: everysize order number, PayPal
 * capture ID, the customer's original e-mail (information only), what was
 * reported back, and refunds through everysize. Hidden for orders that did
 * not come from the everysize checkout (canShowTab).
 *
 * A refund goes to everysize (External API), which refunds the PayPal capture
 * on the merchant's behalf — no PayPal credentials in the shop. Every form
 * carries a fresh idempotency key: a double click or a resent form refunds
 * once.
 */
class Everysize extends Template implements TabInterface
{
    private const REFUNDED_STATES = ['COMPLETED', 'PENDING'];

    private Registry $registry;
    private OrderLinks $links;
    private ClientFactory $clientFactory;
    private Settings $settings;
    private PriceCurrencyInterface $priceCurrency;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        OrderLinks $links,
        ClientFactory $clientFactory,
        Settings $settings,
        PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->links = $links;
        $this->clientFactory = $clientFactory;
        $this->settings = $settings;
        $this->priceCurrency = $priceCurrency;
        parent::__construct($context, $data);
    }

    public function getTabLabel(): string
    {        return (string) __('everysize');
    }

    public function getTabTitle(): string
    {
        return (string) __('everysize');
    }

    /** Only orders from the everysize checkout get the tab. */
    public function canShowTab(): bool
    {
        return $this->getLink() !== null;
    }

    public function isHidden(): bool
    {
        return false;
    }

    public function getTabKey(): string
    {
        return 'everysize';
    }

    private function getOrder(): ?Order
    {
        $order = $this->registry->registry('current_order') ?? $this->registry->registry('order');

        return $order instanceof Order ? $order : null;
    }

    /** @return array{id_order: int, everysize_order_id: string, paypal_capture_id: string, original_email: string, number_reported: ?string, created_at: string}|null */
    public function getLink(): ?array
    {
        $order = $this->getOrder();
        if ($order === null || $order->getId() === null) {
            return null;
        }

        return $this->links->getLink((int) $order->getId());
    }

    /** @return array<int, array{kind: string, sku: string, reason: string, result: string, created_at: string}> */
    public function getReports(): array
    {
        $link = $this->getLink();

        return $link === null ? [] : $this->links->getReports((int) $link['id_order']);
    }

    /**
     * Refund state from everysize: paid / refunded (COMPLETED + PENDING) /
     * open, plus the refunds list. The open amount is in the order's
     * currency; the everysize amounts are as the customer paid them.
     *
     * @return array{refunds: array<int, array{created: string, amount: string, status: string, refundId: string}>, error: string, paid: float, refunded: float, open: float}
     */
    public function getRefundInfo(): array
    {
        $info = ['refunds' => [], 'error' => '', 'paid' => 0.0, 'refunded' => 0.0, 'open' => 0.0];
        $link = $this->getLink();
        if ($link === null || $link['paypal_capture_id'] === '') {
            return $info;
        }

        $order = $this->getOrder();
        $info['paid'] = $order !== null ? (float) $order->getGrandTotal() : 0.0;

        try {
            $refunds = $this->clientFactory->create()->getRefunds($link['everysize_order_id']);
        } catch (ApiException $e) {
            $info['error'] = $e->getMessage();

            return $info;
        }

        foreach ($refunds as $refund) {
            $amount = (float) str_replace(',', '', (string) ($refund['amount'] ?? '0'));
            $status = strtoupper((string) ($refund['status'] ?? ''));
            if (\in_array($status, self::REFUNDED_STATES, true)) {
                $info['refunded'] += $amount;
            }
            $info['refunds'][] = [
                'created' => $this->localTime((string) ($refund['created'] ?? '')),
                'amount' => $this->formatMoney($amount),
                'status' => $status,
                'refundId' => (string) ($refund['refundId'] ?? ''),
            ];
        }
        $info['open'] = max(0.0, round($info['paid'] - $info['refunded'], 2));

        return $info;
    }

    public function canRefund(): bool
    {
        $info = $this->getRefundInfo();

        return $info['error'] !== '' || $info['open'] > 0.0;
    }

    /** Fresh per page view: a form sent twice refunds once. */
    public function getRefundKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function getRefundUrl(): string
    {
        return $this->getUrl('everysize/order/refund');
    }

    public function isAutoRefundEnabled(): bool
    {
        return $this->settings->isAutoRefundDecline();
    }

    public function formatMoney(float $amount): string
    {
        return (string) $this->priceCurrency->format($amount, false, 2, null, $this->getOrder()?->getOrderCurrencyCode());
    }

    public function formatDateTime(?string $dbValue): string
    {
        if ($dbValue === null || $dbValue === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($dbValue, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone($this->_localeDate->getConfigTimezone()))
                ->format('d.m.Y H:i');
        } catch (\Exception $e) {
            return $dbValue;
        }
    }

    /** PayPal's ISO 8601 time (e.g. -07:00) as shop time. */
    private function localTime(string $iso): string
    {
        try {
            return $iso === '' ? '' : (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone($this->_localeDate->getConfigTimezone()))
                ->format('d.m.Y H:i');
        } catch (\Exception $e) {
            return $iso;
        }
    }

    /**
     * Translated label for a report row: kind and special refund rows.
     */
    public function reportKindLabel(string $kind): string
    {
        return match ($kind) {
            OrderLinks::KIND_FULFILLMENT => (string) __('Shipping'),
            OrderLinks::KIND_TRACKING => (string) __('Tracking code'),
            OrderLinks::KIND_REVOCATION => (string) __('Cancellation'),
            OrderLinks::KIND_REFUND => (string) __('Refund'),
            default => $kind,
        };
    }

    public function reportResultLabel(string $result): string
    {
        return match ($result) {
            OrderLinks::RESULT_SENT => (string) __('sent'),
            OrderLinks::RESULT_KNOWN => (string) __('already known'),
            OrderLinks::RESULT_SKIPPED => (string) __('skipped'),
            default => $result,
        };
    }

    /** "auto-decline" refunds are the automatic one, show it as such. */
    public function reportSkuLabel(string $kind, string $sku): string
    {
        if ($kind === OrderLinks::KIND_REFUND && $sku === OrderLinks::AUTO_REFUND) {
            return (string) __('automatic, cancellation before shipping');
        }

        return $sku;
    }
}
