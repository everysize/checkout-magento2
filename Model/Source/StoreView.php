<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Source;

use Magento\Store\Model\ResourceModel\Store\CollectionFactory;
use Magento\Store\Model\Store;

/**
 * Store views an imported order can be placed in. The default store view (0)
 * is the default: a shop with one store view does not need a setting.
 */
class StoreView implements \Magento\Framework\Option\ArrayInterface
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
        $options = [['value' => '0', 'label' => (string) __('Default Store View')]];
        foreach ($this->collectionFactory->create()->getItems() as $store) {
            // The admin store (id 0) is not a store view orders can be placed in.
            if ((int) $store->getId() === Store::DEFAULT_STORE_ID) {
                continue;
            }
            $label = $store->getWebsite()->getName().' / '.$store->getName();
            $options[] = ['value' => (string) $store->getId(), 'label' => $label];
        }

        return $options;
    }
}
