<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Config\Frontend;

use Everysize\Checkout\Model\Api\ApiUrl;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Shows which everysize environment the module talks to: production, test
 * (EVERYSIZE_CHECKOUT_API_URL set) or invalid — with the base URL behind it.
 * An invalid configuration must never look like a working setup: nothing is
 * transmitted in that case (ApiUrl never falls back to production).
 */
class Environment extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $env = ApiUrl::describe();

        if ($env['error'] !== '') {
            return sprintf(
                '<span style="color:#e02b27;font-weight:bold">%s</span><br/><span style="color:#e02b27">%s</span>',
                __('Invalid — no data is transmitted'),
                $this->escapeHtml($env['error'])
            );
        }

        $state = $env['environment'] === 'production'
            ? '<span style="color:#008000;font-weight:bold">' . __('Production') . '</span>'
            : '<span style="color:#b26a00;font-weight:bold">' . __('Test environment') . '</span>';

        return $state . '<br/><code style="background:#f5f5f5;padding:1px 4px">' . $this->escapeHtml($env['url']) . '</code>';
    }
}
