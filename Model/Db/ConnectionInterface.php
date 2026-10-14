<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Db;

/**
 * The small part of a database adapter the module needs. Kept as an own
 * interface so the pure core (OrderLinks, StatusExporter) references no
 * Magento class and the unit tests can run without a shop.
 */
interface ConnectionInterface
{
    /**
     * @param array<string, mixed> $bind
     *
     * @return array<int, array<string, string|null>>
     */
    public function fetchAll(string $sql, array $bind = []): array;

    /**
     * @param array<string, mixed> $bind
     *
     * @return string|false|null false when the query returns no row
     */
    public function fetchOne(string $sql, array $bind = []);

    /**
     * The (prefixed) table name: raw names in SQL break with a table prefix.
     *
     * @param string $name table name without prefix
     */
    public function table(string $name): string;

    /**
     * @param array<string, mixed> $bind
     *
     * @return array<string, string|null>|null
     */
    public function fetchRow(string $sql, array $bind = []);

    /**
     * @param array<string, mixed> $bind
     */
    public function execute(string $sql, array $bind = []): void;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
