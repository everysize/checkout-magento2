<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Config\Frontend;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Remove credentials" button with a confirm dialog. After removal the module
 * makes no API calls at all (Sync reports "not configured"), so the confirm
 * must say exactly that.
 */
class RemoveCredentialsButton extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->_urlBuilder->getUrl('everysize/checkout/removeCredentials');
        $confirm = __('Really remove the credentials? Afterwards no orders are picked up any more.');

        return sprintf(
            '<button type="button" class="scalable" onclick="if (confirm(%s)) { everysizeRemoveCredentials(%s); }">%s</button>',
            json_encode((string) $confirm),
            json_encode($url),
            __('Remove credentials')
        );
    }
}
