<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model;

use Everysize\Checkout\Model\Api\ApiException;
use Everysize\Checkout\Model\Api\Client;

/**
 * Builds the API client from the module settings. Values, never Magento
 * objects: the pure Client class stays testable.
 */
class ClientFactory
{
    private Settings $settings;
    private \Magento\Framework\App\ProductMetadataInterface $metadata;

    public function __construct(
        Settings $settings,
        \Magento\Framework\App\ProductMetadataInterface $metadata
    ) {
        $this->settings = $settings;
        $this->metadata = $metadata;
    }

    /**
     * @throws ApiException if EVERYSIZE_CHECKOUT_API_URL is set to an invalid value
     */
    public function create(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $apiUrl = null
    ): Client {
        return new Client(
            $clientId ?? $this->settings->getClientId(),
            $clientSecret ?? $this->settings->getClientSecret(),
            $apiUrl ?? $this->settings->getApiUrl(),
            $this->metadata->getVersion(),
            ModuleInfo::VERSION
        );
    }
}
