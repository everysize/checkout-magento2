<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model;

use Everysize\Checkout\Model\Api\ApiException;

/**
 * Plain-language messages for a refund everysize rejected — shared by the
 * refund form in the order tab and the online credit memo, so both say the
 * same thing for the same reason.
 */
class RefundMessages
{
    /** Known problem reasons of the refund endpoint in plain words, the API's own text behind it. */
    public static function fromException(ApiException $e): string
    {
        $base = match ($e->getReason()) {
            'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS' => (string) __('No refund via everysize is possible for this order.'),
            'REFUND_AMOUNT_INVALID' => (string) __('everysize rejected the amount, e.g. because it is higher than the not yet refunded amount.'),
            'PAYPAL_REFUND_FAILED' => (string) __('PayPal rejected the refund.'),
            default => (string) __('Refund failed:'),
        };

        return $base . ' (' . $e->getMessage() . ')';
    }
}
