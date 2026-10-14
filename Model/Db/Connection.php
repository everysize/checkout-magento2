<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Db;

use Magento\Framework\App\ResourceConnection;

/**
 * The default-connection adapter behind ConnectionInterface. Only this class
 * touches Magento — the pure core sees the interface.
 */
class Connection implements ConnectionInterface
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * @param array<string, mixed> $bind
     *
     * @return array<int, array<string, string|null>>
     */
    public function fetchAll(string $sql, array $bind = []): array
    {
        return $this->connection()->fetchAll($sql, $bind);
    }

    /**
     * @param array<string, mixed> $bind
     *
     * @return string|false|null false when the query returns no row
     */
    public function fetchOne(string $sql, array $bind = [])
    {
        return $this->connection()->fetchOne($sql, $bind);
    }

    /**
     * @param array<string, mixed> $bind
     *
     * @return array<string, string|null>|null
     */
    public function fetchRow(string $sql, array $bind = [])
    {
        $row = $this->connection()->fetchRow($sql, $bind);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $bind
     */
    public function execute(string $sql, array $bind = []): void
    {
        $this->connection()->query($sql, $bind);
    }

    public function beginTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection()->commit();
    }

    public function rollBack(): void
    {
        $this->connection()->rollBack();
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
