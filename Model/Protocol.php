<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model;

use Everysize\Checkout\Model\Db\ConnectionInterface;

/**
 * Protocol of sync runs, shown in the admin. Only runs that moved something
 * or failed are recorded — with a run every 5 minutes, "nothing to do"
 * entries would bury the relevant ones.
 *
 * References no Magento type: the adapter is injected behind
 * ConnectionInterface, so unit tests run without a shop.
 */
class Protocol
{
    public const RETENTION_DAYS = 31;

    private ConnectionInterface $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }

    public function add(string $title, string $message, bool $success): void
    {
        $this->db->execute(
            "INSERT INTO {$this->db->table("everysize_protocol")} (title, message, state, created_at)
             VALUES (:title, :message, :state, :created)",
            [
                ':title' => mb_substr($title, 0, 255),
                ':message' => $message,
                ':state' => $success ? 1 : 0,
                ':created' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * @param bool|null $success null = all entries, true = only successful, false = only errors
     *
     * @return array<int, array{entity_id: string, created_at: string, title: string, message: string, state: string}>
     */
    public function getEntries(int $offset = 0, int $limit = 50, ?bool $success = null): array
    {
        [$where, $params] = $this->filter($success);

        return $this->db->fetchAll(
            sprintf(
                "SELECT entity_id, created_at, title, message, state FROM {$this->db->table("everysize_protocol")} WHERE %s ORDER BY entity_id DESC LIMIT %d, %d",
                $where,
                max(0, $offset),
                max(1, $limit)
            ),
            $params
        );
    }

    public function count(?bool $success = null): int
    {
        [$where, $params] = $this->filter($success);

        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM {$this->db->table("everysize_protocol")} WHERE " . $where, $params);
    }

    public function cleanup(int $days = self::RETENTION_DAYS): void
    {
        $this->db->execute(
            "DELETE FROM {$this->db->table("everysize_protocol")} WHERE created_at < :before LIMIT 1000",
            [':before' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private function filter(?bool $success): array
    {
        $where = '1 = 1';
        $params = [];
        if ($success !== null) {
            $where .= ' AND state = :state';
            $params[':state'] = $success ? 1 : 0;
        }

        return [$where, $params];
    }
}
