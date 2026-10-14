<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Adminhtml\Protocol;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * The sync protocol page: what the runs transmitted, newest first, 50 rows
 * per page, filtered by all / ok / error.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Everysize_Checkout::protocol';

    private PageFactory $resultPageFactory;

    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Everysize_Checkout::protocol');
        $resultPage->getConfig()->getTitle()->prepend(__('everysize protocol'));

        return $resultPage;
    }
}
