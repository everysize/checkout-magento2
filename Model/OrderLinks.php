<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model;

use Everysize\Checkout\Model\Db\ConnectionInterface;

/**
 * Links Magento orders to everysize orders and records what was reported back.
 *
 * Every report is stored with a unique key (order, kind, SKU), so fulfillment
 * and revocation go out exactly once per order item — editing or re-saving an
 * order in the admin does not touch these rows. A tracking code is stored as
 * kind "tracking" with the code in the SKU column: each code goes out once,
 * so a code entered or corrected after shipping is reported once more.
 *
 * This class deliberately references no Magento type: the database adapter is
 * injected behind ConnectionInterface, so the unit tests run without a shop.
 */
class OrderLinks
{
    public const KIND_FULFILLMENT = 'fulfillment';
    public const KIND_REVOCATION = 'revocation';
    public const KIND_TRACKING = 'tracking';
    /** A refund through everysize from the "everysize" tab; SKU = PayPal refund ID, REASON = amount. */
    public const KIND_REFUND = 'refund';
    /** SKU of the automatic full refund after a cancellation before shipping (REASON = PayPal refund ID or why not). */
    public const AUTO_REFUND = 'auto-decline';

    /** Sent to everysize. */
    public const RESULT_SENT = 'sent';
    /** Not sent: the order was already revoked at everysize. */
    public const RESULT_SKIPPED = 'skipped';
    /** Not sent: everysize already had it (a tracking code reported before the module remembered codes). */
    public const RESULT_KNOWN = 'known';

    private ConnectionInterface $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }

    public function findOrderId(string $everysizeOrderId): ?int
    {
        $orderId = $this->db->fetchOne(
            "SELECT id_order FROM {$this->db->table("everysize_order")} WHERE everysize_order_id = :esId LIMIT 1",
            [':esId' => $everysizeOrderId]
        );

        return $orderId !== false && $orderId !== null && $orderId !== '' ? (int) $orderId : null;
    }

    /**
     * @return array{id_order: int, everysize_order_id: string, paypal_capture_id: string, original_email: string, number_reported: ?string, created_at: string}|null
     */
    public function getLink(int $orderId): ?array
    {
        $row = $this->db->fetchRow(
            "SELECT id_order, everysize_order_id, paypal_capture_id, original_email, number_reported, created_at
             FROM {$this->db->table("everysize_order")} WHERE id_order = :id",
            [':id' => $orderId]
        );

        return $row ?: null;
    }

    /**
     * The Magento increment id of an order, for reporting the merchant order number.
     */
    public function getIncrementId(int $orderId): string
    {
        return (string) $this->db->fetchOne(
            "SELECT increment_id FROM {$this->db->table("sales_order")} WHERE entity_id = :id",
            [':id' => $orderId]
        );
    }

    public function addOrder(int $orderId, string $everysizeOrderId, string $payPalCaptureId, string $originalEmail = ''): void
    {
        $this->db->execute(
            "INSERT INTO {$this->db->table("everysize_order")} (id_order, everysize_order_id, paypal_capture_id, original_email, created_at)
             VALUES (:id, :esId, :captureId, :originalEmail, :created)",
            [
                ':id' => $orderId,
                ':esId' => $everysizeOrderId,
                ':captureId' => $payPalCaptureId,
                ':originalEmail' => mb_substr($originalEmail, 0, 255),
                ':created' => self::now(),
            ]
        );
        // The orders grid has its own table; the join (di.xml) refreshes it, this keeps the
        // new order from showing an empty column until the next grid refresh.
        $this->db->execute(
            "UPDATE {$this->db->table("sales_order_grid")} SET everysize_order_id = :esId WHERE entity_id = :id",
            [':esId' => $everysizeOrderId, ':id' => $orderId]
        );
    }

    public function addOrderItem(int $orderItemId, int $orderId, string $sku): void
    {
        $this->db->execute(
            "INSERT INTO {$this->db->table("everysize_order_item")} (id_order, order_item_id, sku) VALUES (:orderId, :orderItemId, :sku)",
            [':orderId' => $orderId, ':orderItemId' => $orderItemId, ':sku' => $sku]
        );
    }

    public function markNumberReported(int $orderId): void
    {
        $this->db->execute(
            "UPDATE {$this->db->table("everysize_order")} SET number_reported = :now WHERE id_order = :id",
            [':now' => self::now(), ':id' => $orderId]
        );
    }

    public function isNumberReported(int $orderId): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1 FROM {$this->db->table("everysize_order")} WHERE id_order = :id AND number_reported IS NOT NULL",
            [':id' => $orderId]
        );
    }

    public function addReport(int $orderId, string $kind, string $sku, string $reason, string $result): void
    {
        $this->db->execute(
            "INSERT IGNORE INTO {$this->db->table("everysize_report")} (id_order, kind, sku, reason, result, created_at)
             VALUES (:orderId, :kind, :sku, :reason, :result, :created)",
            [
                ':orderId' => $orderId,
                ':kind' => $kind,
                ':sku' => mb_substr($sku, 0, 255),
                ':reason' => mb_substr($reason, 0, 255),
                ':result' => $result,
                ':created' => self::now(),
            ]
        );
    }

    public function hasSentFulfillment(int $orderId): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1 FROM {$this->db->table("everysize_report")} WHERE id_order = :orderId AND kind = :kind AND result = :result",
            [':orderId' => $orderId, ':kind' => self::KIND_FULFILLMENT, ':result' => self::RESULT_SENT]
        );
    }

    /**
     * @return array<int, array{kind: string, sku: string, reason: string, result: string, created_at: string}>
     */
    public function getReports(int $orderId): array
    {
        return $this->db->fetchAll(
            "SELECT kind, sku, reason, result, created_at FROM {$this->db->table("everysize_report")} WHERE id_order = :orderId ORDER BY entity_id",
            [':orderId' => $orderId]
        );
    }

    /**
     * Shipped orders (at least one shipment) whose fulfillment has not been
     * reported. Deliberately also when the items were credited afterwards: a
     * shipment that was returned before the next run is still a shipment —
     * reported first, the credit memo then counts as RETOUR, not as a
     * cancellation before shipping. The tracking code is the first shipment
     * track of the order.
     *
     * @return array<int, array{order_id: string, everysize_order_id: string, increment_id: string, track_code: string}>
     */
    public function getPendingFulfillments(): array
    {
        return $this->db->fetchAll(
            "SELECT o.entity_id AS order_id, l.everysize_order_id, o.increment_id,
                    (SELECT TRIM(st.track_number) FROM {$this->db->table("sales_shipment_track")} st
                       JOIN {$this->db->table("sales_shipment")} s ON s.entity_id = st.parent_id
                      WHERE s.order_id = o.entity_id
                      ORDER BY s.entity_id, st.entity_id LIMIT 1) AS track_code
               FROM {$this->db->table("everysize_order")} l
               JOIN {$this->db->table("sales_order")} o ON o.entity_id = l.id_order
              WHERE EXISTS (SELECT 1 FROM {$this->db->table("sales_shipment")} s WHERE s.order_id = o.entity_id)
                AND NOT EXISTS (SELECT 1 FROM {$this->db->table("everysize_report")} r WHERE r.id_order = o.entity_id AND r.kind = :kind)
              ORDER BY o.increment_id",
            [':kind' => self::KIND_FULFILLMENT]
        );
    }

    /**
     * Orders whose fulfillment was reported since $since and that now carry a
     * tracking code that was never reported: entered after shipping or
     * corrected. Cancelled orders are left out.
     *
     * @return array<int, array{order_id: string, everysize_order_id: string, increment_id: string, track_code: string}>
     */
    public function getPendingTrackingCodes(string $since): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT o.entity_id AS order_id, l.everysize_order_id, o.increment_id, TRIM(st.track_number) AS track_code
               FROM {$this->db->table("everysize_order")} l
               JOIN {$this->db->table("sales_order")} o ON o.entity_id = l.id_order
               JOIN {$this->db->table("everysize_report")} f ON f.id_order = o.entity_id AND f.kind = :fulfillment AND f.result = :sent
               JOIN {$this->db->table("sales_shipment")} s ON s.order_id = o.entity_id
               JOIN {$this->db->table("sales_shipment_track")} st ON st.parent_id = s.entity_id
              WHERE f.created_at >= :since
                AND o.state NOT IN ('canceled', 'closed')
                AND TRIM(st.track_number) <> ''
                AND EXISTS (SELECT 1 FROM {$this->db->table("sales_order_item")} i
                             WHERE i.order_id = o.entity_id AND i.parent_item_id IS NULL
                               AND i.qty_ordered > i.qty_canceled + i.qty_refunded)
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->db->table("everysize_report")} t
                     WHERE t.id_order = o.entity_id AND t.kind = :tracking AND t.sku = TRIM(st.track_number)
                )
              ORDER BY o.increment_id, st.entity_id",
            [
                ':since' => $since,
                ':fulfillment' => self::KIND_FULFILLMENT,
                ':sent' => self::RESULT_SENT,
                ':tracking' => self::KIND_TRACKING,
            ]
        );
    }

    /**
     * Orders whose cancellation before shipping (MERCHANT_DECLINE) was reported
     * since $since, with no item left uncancelled, and that have no refund
     * through everysize yet — automatic (done or given up), from the order tab or
     * an online credit memo: once the merchant refunded an amount, the rest is
     * not refunded on top. An item the merchant added in the admin keeps the
     * order from being refunded in full.
     *
     * @return array<int, array{order_id: string, everysize_order_id: string, increment_id: string}>
     */
    public function getPendingAutoRefunds(string $since): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT o.entity_id AS order_id, l.everysize_order_id, o.increment_id
               FROM {$this->db->table("everysize_report")} r
               JOIN {$this->db->table("sales_order")} o ON o.entity_id = r.id_order
               JOIN {$this->db->table("everysize_order")} l ON l.id_order = o.entity_id
              WHERE l.paypal_capture_id <> ''
                AND r.kind = :revocation AND r.reason = :decline AND r.result = :sent
                AND r.created_at >= :since
                AND NOT EXISTS (SELECT 1 FROM {$this->db->table("sales_order_item")} i
                                 WHERE i.order_id = o.entity_id AND i.parent_item_id IS NULL
                                   AND i.qty_ordered > i.qty_canceled + i.qty_refunded)
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->db->table("everysize_report")} a
                     WHERE a.id_order = o.entity_id AND a.kind = :refund
                )
              ORDER BY o.increment_id",
            [
                ':revocation' => self::KIND_REVOCATION,
                ':decline' => Api\Client::REASON_MERCHANT_DECLINE,
                ':sent' => self::RESULT_SENT,
                ':since' => $since,
                ':refund' => self::KIND_REFUND,
            ]
        );
    }

    /**
     * Cancelled items (item fully cancelled or fully credited, or the whole
     * order cancelled/closed) whose revocation has not been reported.
     *
     * @return array<int, array{order_id: string, everysize_order_id: string, increment_id: string, sku: string}>
     */
    public function getPendingRevocations(): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT o.entity_id AS order_id, l.everysize_order_id, o.increment_id, li.sku
               FROM {$this->db->table("everysize_order_item")} li
               JOIN {$this->db->table("sales_order_item")} oi ON oi.item_id = li.order_item_id
               JOIN {$this->db->table("sales_order")} o ON o.entity_id = li.id_order
               JOIN {$this->db->table("everysize_order")} l ON l.id_order = o.entity_id
              WHERE oi.qty_ordered > 0
                AND (oi.qty_canceled >= oi.qty_ordered OR oi.qty_refunded >= oi.qty_ordered OR o.state IN ('canceled', 'closed'))
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->db->table("everysize_report")} r
                     WHERE r.id_order = o.entity_id AND r.kind = :kind AND r.sku = li.sku
                )
              ORDER BY o.increment_id",
            [':kind' => self::KIND_REVOCATION]
        );
    }

    private static function now(): string
    {
        // Magento expects UTC timestamps in the database.
        return gmdate('Y-m-d H:i:s');
    }
}
