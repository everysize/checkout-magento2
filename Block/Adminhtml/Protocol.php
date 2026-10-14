<?php

declare(strict_types=1);

namespace Everysize\Checkout\Block\Adminhtml;

use Everysize\Checkout\Model\Protocol as ModelProtocol;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Data for the protocol page: the entries of one page plus the filter and
 * pagination state. 50 rows per page, filter all / ok / error (query params
 * filter and page).
 */
class Protocol extends Template
{
    public const PAGE_SIZE = 50;

    private ModelProtocol $protocol;

    public function __construct(Context $context, ModelProtocol $protocol, array $data = [])
    {
        $this->protocol = $protocol;
        parent::__construct($context, $data);
    }

    /** @return array<int, array{created_at: string, state: string, title: string, message: string}> */
    public function getEntries(): array
    {
        return $this->protocol->getEntries($this->getPage() * self::PAGE_SIZE, self::PAGE_SIZE, $this->getState());
    }

    public function getTotal(): int
    {
        return $this->protocol->count($this->getState());
    }

    public function getPages(): int
    {
        return max(1, (int) ceil($this->getTotal() / self::PAGE_SIZE));
    }

    public function getPage(): int
    {
        $page = (int) $this->getRequest()->getParam('page', 0);

        return max(0, min($page, $this->getPages() - 1));
    }

    /** @return 'all'|'ok'|'error' */
    public function getFilter(): string
    {
        $filter = (string) $this->getRequest()->getParam('filter', 'all');

        return \in_array($filter, ['all', 'ok', 'error'], true) ? $filter : 'all';
    }

    private function getState(): ?bool
    {
        $filter = $this->getFilter();

        return $filter === 'ok' ? true : ($filter === 'error' ? false : null);
    }

    /** A UTC timestamp from the protocol table in the shop's time zone, like the order tab shows it. */
    public function formatDateTime(string $dbValue): string
    {
        try {
            return (new \DateTimeImmutable($dbValue, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone($this->_localeDate->getConfigTimezone()))
                ->format('d.m.Y H:i:s');
        } catch (\Exception $e) {
            return $dbValue;
        }
    }

    public function filterUrl(string $filter): string
    {
        return $this->getUrl('*/*/*', ['_query' => ['filter' => $filter, 'page' => null]]);
    }

    public function pageUrl(int $page): string
    {
        return $this->getUrl('*/*/*', ['_query' => ['filter' => $this->getFilter(), 'page' => $page]]);
    }
}
