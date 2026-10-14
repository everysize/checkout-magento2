<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Adminhtml\Checkout;

use Everysize\Checkout\Model\Settings;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Data\Form\FormKey\Validator;

/**
 * Removes Client ID and Secret: the module then makes no API calls at all
 * (the sync answers "not configured" and picks up nothing). Redirects back
 * to the configuration section with a message.
 */
class RemoveCredentials extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Everysize_Checkout::settings';

    private Settings $settings;
    private Validator $formKeyValidator;

    public function __construct(Context $context, Settings $settings, Validator $formKeyValidator)
    {
        $this->settings = $settings;
        $this->formKeyValidator = $formKeyValidator;
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        if (!$this->formKeyValidator->validate($this->getRequest())) {
            $this->messageManager->addErrorMessage((string) __('Session expired, please reload the page.'));
        } else {
            $this->settings->removeCredentials();
            $this->messageManager->addSuccessMessage((string) __('Credentials removed. The module no longer transmits data.'));
        }

        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', ['section' => 'everysize_checkout']);
    }
}
