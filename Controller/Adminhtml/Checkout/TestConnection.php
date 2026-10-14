<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Adminhtml\Checkout;

use Everysize\Checkout\Model\Api\ApiException;
use Everysize\Checkout\Model\ClientFactory;
use Everysize\Checkout\Model\Settings;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;

/**
 * AJAX endpoint of the "Test connection" button: requests a token with the
 * values posted from the fields — an empty secret field falls back to the
 * stored one. Answers {ok, message} or {ok:false, error}; nothing is written.
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Everysize_Checkout::settings';

    private JsonFactory $jsonFactory;
    private ClientFactory $clientFactory;
    private Settings $settings;
    private Validator $formKeyValidator;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        ClientFactory $clientFactory,
        Settings $settings,
        Validator $formKeyValidator
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->clientFactory = $clientFactory;
        $this->settings = $settings;
        $this->formKeyValidator = $formKeyValidator;
        parent::__construct($context);
    }

    public function execute(): Json
    {
        if (!$this->formKeyValidator->validate($this->getRequest())) {
            return $this->error((string) __('Session expired, please reload the page.'));
        }

        $clientId = trim((string) $this->getRequest()->getParam('clientId', ''));
        $clientSecret = trim((string) $this->getRequest()->getParam('clientSecret', ''));
        // The obscured field shows "******" for a stored secret; that is not a new value.
        if ($clientSecret === '' || preg_match('/^\*+$/', $clientSecret) === 1) {
            // An empty secret field keeps the stored secret — it is never sent back to the browser.
            $clientSecret = $this->settings->getClientSecret();
        }
        if ($clientId === '') {
            $clientId = $this->settings->getClientId();
        }
        if ($clientId === '' || $clientSecret === '') {
            return $this->error((string) __('Please enter Client ID and Client Secret.'));
        }

        try {
            $client = $this->clientFactory->create($clientId, $clientSecret);
            $token = $client->requestToken($clientId, $clientSecret);
        } catch (ApiException $e) {
            return $this->error((string) __('Connection failed:') . ' ' . $e->getMessage());
        }

        $result = $this->jsonFactory->create();
        $result->setData([
            'ok' => true,
            'message' => (string) __('Connection successful: everysize shop ID %1 (%2).', (string) ($token['shop_id'] ?? ''), $client->getApiUrl()),
        ]);

        return $result;
    }

    private function error(string $message): Json
    {
        $result = $this->jsonFactory->create();
        $result->setData(['ok' => false, 'error' => $message]);

        return $result;
    }
}
