<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Config\Frontend;

use Everysize\Checkout\Model\Settings;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Text input for the carrier with the documented carriers as suggestions
 * (datalist). The API accepts any value, the input stays free text with the
 * 31-character validation from system.xml.
 */
class CarrierDatalist extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = $element->getElementHtml();
        if (preg_match('~<input\b[^>]*>~', $html, $match) === 1) {
            $html = str_replace(
                $match[0],
                substr($match[0], 0, 6) . ' list="everysize_carriers" ' . substr($match[0], 6),
                $html
            );
        }

        $options = '';
        foreach (Settings::CARRIERS as $carrier) {
            $options .= '<option value="' . $this->escapeHtml($carrier) . '"></option>';
        }

        return $html . '<datalist id="everysize_carriers">' . $options . '</datalist>';
    }
}
