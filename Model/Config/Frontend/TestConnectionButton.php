<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Config\Frontend;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Test connection" button for the credentials. The request goes to the
 * TestConnection controller with the values currently in the Client ID /
 * Client Secret fields — the stored ones are used for fields left empty,
 * the page is not reloaded.
 */
class TestConnectionButton extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->_urlBuilder->getUrl('everysize/checkout/testConnection');

        $html = sprintf(
            '<button type="button" class="scalable" id="everysize_test_connection_btn">%s</button>'
            . ' <span id="everysize_test_connection_result" style="white-space:pre-wrap"></span>',
            __('Test connection')
        );

        $html .= '<script>
require(["jquery"], function ($) {
    $("#everysize_test_connection_btn").on("click", function () {
        var button = $(this), result = $("#everysize_test_connection_result");
        button.prop("disabled", true);
        result.text("…").css("color", "");
        $.post(' . json_encode($url) . ', {
            form_key: window.FORM_KEY || "",
            clientId: $("#everysize_checkout_general_client_id").val() || "",
            clientSecret: $("#everysize_checkout_general_client_secret").val() || ""
        }).done(function (data) {
            button.prop("disabled", false);
            if (data.ok) {
                result.css("color", "#008000").text(data.message);
            } else {
                result.css("color", "#e02b27").text(data.error);
            }
        }).fail(function (xhr) {
            button.prop("disabled", false);
            result.css("color", "#e02b27").text("HTTP " + xhr.status);
        });
    });
});
</script>';

        return $html;
    }
}
