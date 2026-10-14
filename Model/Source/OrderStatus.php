<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Source;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * Statuses assignable to imported orders: only statuses that belong to the
 * processing state — anything else would fight the invoice (which puts the
 * order into processing).
 */
class OrderStatus implements \Magento\Framework\Option\ArrayInterface
{
    private CollectionFactory $collectionFactory;

    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addStateFilter(Order::STATE_PROCESSING);
        $options = [];
        foreach ($collection->getItems() as $status) {
            $label = (string) $status->getData('frontend_label');
            if ($label === '') {
                $label = (string) $status->getData('label');
            }
            $options[] = ['value' => (string) $status->getData('status'), 'label' => $label];
        }

        return $options;
    }
}
