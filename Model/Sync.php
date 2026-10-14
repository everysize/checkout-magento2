<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model;

use Everysize\Checkout\Model\Api\ApiException;
use Everysize\Checkout\Model\Api\Client;
use Everysize\Checkout\Model\Db\ConnectionInterface;
use Everysize\Checkout\Model\Export\StatusExporter;
use Everysize\Checkout\Model\Import\ImportException;
use Everysize\Checkout\Model\Import\OrderImporter;

/**
 * One sync run: import new everysize orders, report their order numbers,
 * then report shipping/cancellations/returns. Run by the console command
 * (cron or manually).
 *
 * This is the composition root: it builds the API client once per run and
 * the StatusExporter around it — never as singletons, the token must not
 * outlive the run.
 */
class Sync
{
    private const LOCK_NAME = 'everysize_checkout_sync';

    private Settings $settings;
    private ClientFactory $clientFactory;
    private OrderImporter $importer;
    private OrderLinks $links;
    private Protocol $protocol;
    private ConnectionInterface $db;

    public function __construct(
        Settings $settings,
        ClientFactory $clientFactory,
        OrderImporter $importer,
        OrderLinks $links,
        Protocol $protocol,
        ConnectionInterface $db
    ) {
        $this->settings = $settings;
        $this->clientFactory = $clientFactory;
        $this->importer = $importer;
        $this->links = $links;
        $this->protocol = $protocol;
        $this->db = $db;
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    public function run(bool $import = true, bool $export = true): array
    {
        if (!$this->settings->isConfigured()) {
            return ['messages' => ['everysize credentials not configured, nothing to do'], 'errors' => []];
        }

        if ((int) $this->db->fetchOne('SELECT GET_LOCK(:name, 0)', [':name' => self::LOCK_NAME]) !== 1) {
            return ['messages' => ['another everysize sync is still running, skipped'], 'errors' => []];
        }

        try {
            try {
                $client = $this->clientFactory->create();
                $apiUrl = $client->getApiUrl();
            } catch (ApiException $e) {
                $this->protocol->add('everysize API URL invalid', $e->getMessage(), false);

                return ['messages' => [], 'errors' => [$e->getMessage()]];
            }

            $result = ['messages' => [sprintf('everysize API: %s', $apiUrl)], 'errors' => []];
            if ($import) {
                $result = $this->merge($result, $this->import($client));
            }
            if ($export) {
                $result = $this->merge($result, $this->export($client));
            }
            $this->protocol->cleanup();

            return $result;
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(:name)', [':name' => self::LOCK_NAME]);
        }
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    private function import(Client $client): array
    {
        try {
            $orders = $client->getOpenOrders();
        } catch (ApiException $e) {
            $this->protocol->add('Import from everysize failed', $e->getMessage(), false);

            return ['messages' => [], 'errors' => [$e->getMessage()]];
        }

        $imported = 0;
        $errors = [];
        $warnings = [];
        foreach ($orders as $esOrder) {
            $everysizeOrderId = trim((string) ($esOrder['everysizeOrderId'] ?? ''));
            if ($everysizeOrderId === '') {
                $errors[] = 'everysize order without everysizeOrderId skipped';
                continue;
            }

            try {
                $orderId = $this->links->findOrderId($everysizeOrderId);
                if ($orderId === null) {
                    $result = $this->importer->import($esOrder);
                    $orderId = $result['orderId'];
                    $warnings = array_merge($warnings, $result['warnings']);
                    ++$imported;
                }

                if (!$this->links->isNumberReported($orderId)) {
                    $client->sendMerchantOrderNumber($everysizeOrderId, $this->links->getIncrementId($orderId));
                    $this->links->markNumberReported($orderId);
                }
            } catch (ImportException|ApiException $e) {
                $errors[] = sprintf('%s: %s', $everysizeOrderId, $e->getMessage());
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s: %s', $everysizeOrderId, \get_class($e), $e->getMessage());
            }
        }

        $title = sprintf('Imported %d of %d orders from everysize', $imported, \count($orders));
        if ($orders !== [] || $errors !== []) {
            $this->protocol->add($title, implode("\n", array_merge($errors, $warnings)), $errors === []);
        }

        return ['messages' => [$title], 'errors' => $errors];
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    private function export(Client $client): array
    {
        $exporter = new StatusExporter($client, $this->settings, $this->links);

        try {
            $result = $exporter->export();
        } catch (ApiException $e) {
            $this->protocol->add('Report to everysize failed', $e->getMessage(), false);

            return ['messages' => [], 'errors' => [$e->getMessage()]];
        }

        $title = sprintf(
            'Reported to everysize: %d shipped, %d tracking codes added later, %d cancelled, %d returned, %d refunded automatically, %d skipped (revoked at everysize)',
            $result['fulfillments'],
            $result['trackingCodes'],
            $result['revocations'],
            $result['returns'],
            $result['autoRefunds'],
            $result['skipped']
        );
        if ($result['pending'] > 0 || $result['errors'] !== []) {
            $this->protocol->add($title, implode("\n", $result['errors']), $result['errors'] === []);
        }

        return ['messages' => [$title], 'errors' => $result['errors']];
    }

    /**
     * @param array{messages: string[], errors: string[]} $a
     * @param array{messages: string[], errors: string[]} $b
     *
     * @return array{messages: string[], errors: string[]}
     */
    private function merge(array $a, array $b): array
    {
        return [
            'messages' => array_merge($a['messages'], $b['messages']),
            'errors' => array_merge($a['errors'], $b['errors']),
        ];
    }
}
