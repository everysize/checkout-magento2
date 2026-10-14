<?php

declare(strict_types=1);

namespace Everysize\Checkout\Cron;

use Everysize\Checkout\Model\Settings;
use Everysize\Checkout\Model\Sync as ModelSync;
use Psr\Log\LoggerInterface;

/**
 * The 5-minute cron job. Silent when "automatic sync" is off —
 * bin/magento everysize:sync works regardless.
 */
class Sync
{
    private Settings $settings;
    private ModelSync $sync;
    private LoggerInterface $logger;

    public function __construct(
        Settings $settings,
        ModelSync $sync,
        LoggerInterface $logger
    ) {
        $this->settings = $settings;
        $this->sync = $sync;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->settings->isSyncEnabled()) {
            return;
        }

        $result = $this->sync->run();
        foreach ($result['errors'] as $error) {
            $this->logger->error(sprintf('everysize sync: %s', $error));
        }
    }
}
