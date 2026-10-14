<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Import;

use Everysize\Checkout\Model\Api\Amount;
use Everysize\Checkout\Model\Api\Client;
use Everysize\Checkout\Model\Db\ConnectionInterface;
use Everysize\Checkout\Model\OrderLinks;
use Everysize\Checkout\Model\Settings;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Address\RateFactory;
use Magento\Quote\Model\Quote\AddressFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Calculation;
use Psr\Log\LoggerInterface;

/**
 * Creates a Magento order from an everysize order.
 *
 * The order goes through Magento's own checkout service (QuoteManagement)
 * with the prices the customer paid at everysize as custom prices — not
 * through a recalculated basket. How Magento interprets a custom price
 * depends on its tax configuration (price/shipping includes tax, store
 * default tax rate vs. the address rate), so the line's gross amount is
 * converted so that the row total incl. tax comes out as the everysize
 * amount in every configuration; checkTotal catches the rest.
 *
 * Customer, order, items, stock and the everysize link are written in one
 * database transaction (the order resource's, so Magento's own commit
 * callbacks stay intact): a failure leaves nothing behind, no orphaned data
 * per failed attempt. The whole import is retried on the next run.
 */
class OrderImporter
{
    private Settings $settings;
    private OrderLinks $links;
    private ConnectionInterface $db;
    private ResourceConnection $resource;
    private StoreManagerInterface $storeManager;
    private QuoteFactory $quoteFactory;
    private CartRepositoryInterface $cartRepository;
    private QuoteManagement $quoteManagement;
    private CustomerRepositoryInterface $customerRepository;
    private GroupManagementInterface $groupManagement;
    private Calculation $taxCalculation;
    private ProductRepositoryInterface $productRepository;
    private CountryFactory $countryFactory;
    private InvoiceService $invoiceService;
    private BuilderInterface $transactionBuilder;
    private OrderSender $orderSender;
    private State $appState;
    private LoggerInterface $logger;
    private AddressFactory $quoteAddressFactory;
    private RateFactory $rateFactory;
    private ProductResource $productResource;
    private OrderResource $orderResource;
    private ConfigurableResource $configurableResource;
    private ScopeConfigInterface $scopeConfig;

    private ?int $statusAttributeId = null;

    public function __construct(
        Settings $settings,
        OrderLinks $links,
        ConnectionInterface $db,
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        QuoteFactory $quoteFactory,
        CartRepositoryInterface $cartRepository,
        QuoteManagement $quoteManagement,
        CustomerRepositoryInterface $customerRepository,
        GroupManagementInterface $groupManagement,
        Calculation $taxCalculation,
        ProductRepositoryInterface $productRepository,
        CountryFactory $countryFactory,
        InvoiceService $invoiceService,
        BuilderInterface $transactionBuilder,
        OrderSender $orderSender,
        LoggerInterface $logger,
        State $appState,
        AddressFactory $quoteAddressFactory,
        RateFactory $rateFactory,
        ProductResource $productResource,
        OrderResource $orderResource,
        ConfigurableResource $configurableResource,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->settings = $settings;
        $this->links = $links;
        $this->db = $db;
        $this->resource = $resource;
        $this->storeManager = $storeManager;
        $this->quoteFactory = $quoteFactory;
        $this->cartRepository = $cartRepository;
        $this->quoteManagement = $quoteManagement;
        $this->customerRepository = $customerRepository;
        $this->groupManagement = $groupManagement;
        $this->taxCalculation = $taxCalculation;
        $this->productRepository = $productRepository;
        $this->countryFactory = $countryFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionBuilder = $transactionBuilder;
        $this->orderSender = $orderSender;
        $this->appState = $appState;
        $this->logger = $logger;
        $this->quoteAddressFactory = $quoteAddressFactory;
        $this->rateFactory = $rateFactory;
        $this->productResource = $productResource;
        $this->orderResource = $orderResource;
        $this->configurableResource = $configurableResource;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param array<string, mixed> $esOrder order as returned by the everysize API
     *
     * @return array{orderId: int, orderNumber: string, warnings: string[]}
     */
    public function import(array $esOrder): array
    {
        // ---- Validation, before anything is written
        $everysizeOrderId = $this->requireString($esOrder, 'everysizeOrderId');
        $customer = \is_array($esOrder['customer'] ?? null) ? $esOrder['customer'] : [];
        $email = $this->requireString($customer, 'email', 'customer.email');
        // Information only: the Magento customer keeps the forwarding address above.
        $originalEmail = $this->validEmail($customer['originalEmail'] ?? '');
        $billing = $this->address($esOrder['billingAddress'] ?? null, 'billingAddress');
        $shipping = \is_array($esOrder['shippingAddress'] ?? null) && $esOrder['shippingAddress'] !== []
            ? $this->address($esOrder['shippingAddress'], 'shippingAddress')
            : $billing;
        $revoked = strtoupper((string) ($esOrder['status'] ?? '')) === Client::STATUS_REVOKED;
        $currency = strtoupper((string) ($esOrder['currency'] ?? 'EUR'));
        $payment = \is_array($esOrder['payment'] ?? null) ? $esOrder['payment'] : [];
        $captureId = (string) ($payment['resourceId'] ?? $payment['transactionId'] ?? '');
        $orderDate = $this->utcDate($esOrder['created'] ?? null);
        $grossPrice = isset($esOrder['grossPrice']) ? (string) Amount::parse($esOrder['grossPrice'], 'grossPrice') : null;

        $customerPhone = trim((string) ($customer['phone'] ?? ''));

        $store = $this->resolveStore();
        $baseCurrency = strtoupper((string) $store->getBaseCurrencyCode());
        if ($currency !== $baseCurrency) {
            throw new ImportException(sprintf(
                '%s: everysize order currency %s does not match the store base currency %s',
                $everysizeOrderId,
                $currency,
                $baseCurrency
            ));
        }

        $customerId = $this->findCustomerId($email, $store);
        // The shop's tax rules for the customer's group decide the rates —
        // everysize's own rates are not used.
        $customerTaxClassId = $this->customerTaxClassId($customerId);
        // The telephone falls back to the everysize customer's phone before
        // the "-" placeholder fills the required field.
        if ($customerPhone !== '') {
            if ($billing['telephone'] === '') {
                $billing['telephone'] = $customerPhone;
            }
            if ($shipping['telephone'] === '') {
                $shipping['telephone'] = $customerPhone;
            }
        }
        $this->validateCountry($store, $billing);
        $this->validateCountry($store, $shipping);

        $lines = $this->resolveLines($esOrder['lineItems'] ?? null, $store, $billing, $shipping, $customerTaxClassId);
        $shippingCosts = (float) Amount::parse($esOrder['shippingCosts'] ?? 0, 'shippingCosts');
        $ratePrice = $this->shippingRatePrice($store, $billing, $shipping, $customerTaxClassId, $shippingCosts);
        $prices = PriceCalculator::calculate(\array_map(static function (array $line): array {
            return ['gross' => $line['gross'], 'quantity' => $line['quantity'], 'vat' => $line['vat']];
        }, $lines), $shippingCosts);

        $warnings = [];
        foreach ($lines as $line) {
            if ($line['disabled'] ?? false) {
                $warnings[] = sprintf('%s: product %s is disabled, imported anyway', $everysizeOrderId, $line['sku']);
            }
            if ($line['outOfStock'] ?? false) {
                $warnings[] = sprintf('%s: product %s is out of stock, imported anyway (stock may go negative)', $everysizeOrderId, $line['sku']);
            }
        }
        $sumWarning = PriceCalculator::checkTotal($grossPrice, $prices['totalOrderSum']);
        if ($sumWarning !== null) {
            $warnings[] = sprintf('%s: %s (sum of lines)', $everysizeOrderId, $sumWarning);
        }

        // ---- The one write: quote -> order -> invoice/cancel -> links
        // The order resource's transaction so Magento's own after-commit
        // callbacks (grid, ...) stay intact; nested transactions are savepoints.
        $this->orderResource->beginTransaction();
        try {
            [$order, $quoteItemIds] = $this->createOrder($store, $everysizeOrderId, $email, $billing, $shipping, $lines, $prices, $ratePrice, $revoked, $orderDate, $captureId, $customerId);
            $orderId = (int) $order->getId();
            foreach ($lines as $key => $line) {
                $this->linkOrderItems($order, $orderId, $line['sku'], $quoteItemIds[$key] ?? []);
            }
            $this->links->addOrder($orderId, $everysizeOrderId, $captureId, $originalEmail);
            $this->orderResource->commit();
        } catch (\Throwable $e) {
            $this->orderResource->rollBack();
            throw $e;
        }

        $totalWarning = PriceCalculator::checkTotal($grossPrice, (float) $order->getGrandTotal());
        if ($totalWarning !== null) {
            $warnings[] = sprintf('%s: %s (Magento grand total)', $everysizeOrderId, $totalWarning);
        }

        if (!$revoked && $this->settings->isSendOrderEmail()) {
            try {
                // The template lookup needs a theme; without an area with a
                // design package (the sync runs in crontab) it fails with
                // "Required parameter 'theme_dir' was not passed". The mail
                // renders with the store design from the frontend area.
                $this->appState->emulateAreaCode(Area::AREA_FRONTEND, function () use ($order): void {
                    $this->orderSender->send($order);
                });
            } catch (\Throwable $e) {
                // The order exists; a mail problem must not fail the import.
                $this->logger->error(sprintf(
                    'everysize: order confirmation for %s could not be sent: %s',
                    $order->getIncrementId(),
                    $e->getMessage()
                ));
            }
        }

        return ['orderId' => $orderId, 'orderNumber' => $order->getIncrementId(), 'warnings' => $warnings];
    }

    /**
     * Store view imported orders are placed in. 0 = the shop's default store view.
     */
    private function resolveStore(): StoreInterface
    {
        $storeId = $this->settings->getStoreId();
        $store = $storeId > 0
            ? $this->storeManager->getStore($storeId)
            : ($this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore());
        $this->storeManager->setCurrentStore((string) $store->getId());

        return $store;
    }

    /**
     * @param array<int, array{sku: string, title: string, quantity: int, gross: float, vat: float, unitCustomPrice: float, product: ProductInterface, parent: ?ProductInterface, superAttribute: array<int, int>, disabled: bool, outOfStock: bool}> $lines
     * @param array<string, string> $billing
     * @param array<string, string> $shipping
     *
     * @return array{0: Order, 1: array<int, array<int, int>>} order and the per-line quote item ids
     */
    private function createOrder(
        StoreInterface $store,
        string $everysizeOrderId,
        string $email,
        array $billing,
        array $shipping,
        array $lines,
        array $prices,
        float $ratePrice,
        bool $revoked,
        string $orderDate,
        string $captureId,
        ?int $customerId
    ): array {
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        // The order must carry the base currency: everysize's amounts are the
        // store base currency, not the display currency of a store view.
        $quote->setBaseCurrencyCode($baseCurrency = strtoupper((string) $store->getBaseCurrencyCode()));
        $quote->setQuoteCurrencyCode($baseCurrency);

        if ($customerId !== null) {
            // The customer keeps their account data; the order carries its own addresses.
            $customer = $this->customerRepository->getById($customerId);
            $quote->setCustomer($customer);
            $quote->setCustomerIsGuest(false);
        } else {
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerGroupId(Group::NOT_LOGGED_IN_ID);
        }
        $quote->setCustomerEmail($email);
        if ($quote->getCustomerFirstname() === null || $quote->getCustomerFirstname() === '') {
            $quote->setCustomerFirstname($billing['firstName']);
        }
        if ($quote->getCustomerLastname() === null || $quote->getCustomerLastname() === '') {
            $quote->setCustomerLastname($billing['lastName']);
        }

        // Via getBillingAddress()/getShippingAddress(): addAddress() without an
        // address_type leaves both lookups empty and the shipping rate is lost.
        $billingAddress = $quote->getBillingAddress();
        $billingAddress->addData($this->quoteAddressData($billing, $email));
        if ($customerId !== null) {
            $billingAddress->setCustomerId($customerId);
            $billingAddress->setSaveInAddressBook(false);
        }

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->addData($this->quoteAddressData($shipping, $email));
        $shippingAddress->setSameAsBilling($billing === $shipping ? 1 : 0);
        if ($customerId !== null) {
            $shippingAddress->setCustomerId($customerId);
            $shippingAddress->setSaveInAddressBook(false);
        }
        $this->injectShippingRate($shippingAddress, $ratePrice);

        // Custom prices: the everysize gross converted so the row total incl.
        // tax matches, whatever the shop's tax configuration (see the class doc).
        $quoteItems = [];
        foreach ($lines as $key => $line) {
            $unitPrice = $line['unitCustomPrice'];
            $buyRequest = ['qty' => $line['quantity']];
            if ($line['superAttribute'] !== []) {
                $buyRequest['super_attribute'] = $line['superAttribute'];
            }
            $product = $line['parent'] ?? $line['product'];
            // A paid order must import even when the product is out of stock
            // or disabled: salable in memory, the stock may go negative
            // (warning goes to the protocol).
            $product->setIsSalable(1);
            $item = $quote->addProduct($product, new \Magento\Framework\DataObject($buyRequest));
            if (\is_string($item)) {
                throw new ImportException(sprintf('%s: %s', $everysizeOrderId, $item));
            }
            $item->setNoDiscount(true);
            $item->setCustomPrice($unitPrice);
            $item->setOriginalCustomPrice($unitPrice);
            $item->getProduct()->setIsSuperMode(true);
            $quoteItems[$key] = [$item];
            foreach ((array) $item->getChildren() as $childItem) {
                $childItem->setNoDiscount(true);
                $childItem->setCustomPrice($unitPrice);
                $childItem->setOriginalCustomPrice($unitPrice);
                if ($childItem->getProduct()) {
                    $childItem->getProduct()->setIsSuperMode(true);
                }
                $quoteItems[$key][] = $childItem;
            }
        }

        // The stock validators mark the quote with errors for salability
        // checks; a paid order is imported regardless.
        foreach ($quote->getAllItems() as $item) {
            $item->setHasError(false);
        }
        $quote->setHasError(false);

        // The everysize import: the confirmation mail is sent after the
        // commit (or not at all), never by the quote submit observers.
        $quote->setData('everysize_import', true);

        $quote->getPayment()->setQuote($quote);
        $quote->getPayment()->setMethod('everysize');

        // Persist the quote first: unsaved quote items have no IDs and
        // QuoteManagement::resolveItems would collapse parent/child into one item.
        $this->cartRepository->save($quote);
        // Only now do the quote items have IDs: they map order items to lines.
        foreach ($quoteItems as $key => $items) {
            $quoteItems[$key] = array_map(
                static function ($item): int {
                    return (int) $item->getId();
                },
                $items
            );
        }
        $quote->collectTotals();

        $order = $this->quoteManagement->submit($quote);
        if (!$order instanceof Order || $order->getId() === null) {
            throw new ImportException(sprintf('%s: the order could not be created', $everysizeOrderId));
        }

        if ($revoked) {
            // Cancelled at everysize before the shop shipped: no invoice, stock back.
            $order->cancel();
        } else {
            $this->captureOffline($order, $captureId);
            $order->setStatus($this->settings->getOrderStatus());
            $order->setState(Order::STATE_PROCESSING);
        }
        $order->setCreatedAt($orderDate);
        $order->save();

        return [$order, $quoteItems];
    }

    /**
     * Offline capture: paid via everysize (PayPal), the shop owes nothing to
     * the payment provider. The PayPal capture ID becomes the transaction.
     */
    private function captureOffline(Order $order, string $captureId): void
    {
        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        if ($captureId !== '') {
            $invoice->setTransactionId($captureId);
        }
        $invoice->register();

        if ($captureId !== '') {
            $this->transactionBuilder
                ->setPayment($order->getPayment())
                ->setOrder($order)
                ->setTransactionId($captureId)
                ->build(Transaction::TYPE_CAPTURE);
            $order->getPayment()->setLastTransId($captureId);
        }
        $invoice->save();
    }

    /**
     * The link rows cover every order item that came from the line's quote
     * items (matched via quote_item_id, also the children of a configurable);
     * the SKU column is always the SKU everysize sent. Strict SKU matching on
     * the order items would miss case-insensitive matches and then never
     * report cancellations/returns.
     *
     * @param array<int, int> $quoteItemIds
     */
    private function linkOrderItems(Order $order, int $orderId, string $sku, array $quoteItemIds): void
    {
        if ($quoteItemIds === []) {
            return;
        }
        foreach ($order->getItems() as $orderItem) {
            if (!\in_array((int) $orderItem->getQuoteItemId(), $quoteItemIds, true)) {
                continue;
            }
            $this->links->addOrderItem((int) $orderItem->getItemId(), $orderId, $sku);
        }
    }

    /**
     * @param array<string, string> $billing
     * @param array<string, string> $shipping
     *
     * @return array<int, array{sku: string, title: string, quantity: int, gross: float, vat: float, unitCustomPrice: float, product: ProductInterface, parent: ?ProductInterface, superAttribute: array<int, int>, disabled: bool, outOfStock: bool}>
     */
    private function resolveLines($lineItems, StoreInterface $store, array $billing, array $shipping, int $customerTaxClassId): array
    {
        if (!\is_array($lineItems) || $lineItems === []) {
            throw new ImportException('everysize order has no line items');
        }

        $priceIncludesTax = (bool) $store->getConfig('tax/calculation/price_includes_tax');
        $lines = [];
        foreach (\array_values($lineItems) as $item) {
            if (!\is_array($item)) {
                throw new ImportException('invalid line item in everysize order');
            }
            $sku = $this->requireString($item, 'sku', 'lineItems.sku');
            ['product' => $product, 'parent' => $parent, 'superAttribute' => $superAttribute, 'disabled' => $disabled, 'outOfStock' => $outOfStock] = $this->findProduct($sku, $store);
            ['rate' => $rate, 'storeRate' => $storeRate] = $this->taxRates($store, $billing, $shipping, $customerTaxClassId, (int) $product->getTaxClassId());
            $gross = (float) Amount::parse($item['price'] ?? null, 'lineItems.price');
            $lines[] = [
                'sku' => $sku,
                'title' => trim((string) ($item['title'] ?? '')),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'gross' => $gross,
                'vat' => $rate,
                // Row total incl. tax must come out as the everysize gross:
                // with tax included in the price Magento converts the custom
                // price from the store rate to the address rate, with tax
                // excluded the custom price is net. See the class doc.
                'unitCustomPrice' => $priceIncludesTax
                    ? round($gross * (1 + $storeRate / 100) / (1 + $rate / 100), 2)
                    : round($gross / (1 + $rate / 100), 2),
                'product' => $product,
                'parent' => $parent,
                'superAttribute' => $superAttribute,
                'disabled' => $disabled,
                'outOfStock' => $outOfStock,
            ];
        }

        return $lines;
    }

    /**
     * The tax rate for the addresses of the everysize order (the shop's "tax
     * based on") and the store's default rate — Magento converts a custom
     * price from the store rate to the address rate when tax is included in
     * the price, so both are needed (AbstractAggregateCalculator::
     * calculateWithTaxInPrice / calculatePriceInclTax).
     *
     * @param array<string, string> $billing
     * @param array<string, string> $shipping
     *
     * @return array{rate: float, storeRate: float}
     */
    private function taxRates(StoreInterface $store, array $billing, array $shipping, int $customerTaxClassId, int $productTaxClassId): array
    {
        $request = $this->taxCalculation->getRateRequest(
            $this->requestAddress($shipping),
            $this->requestAddress($billing),
            $customerTaxClassId,
            (int) $store->getId()
        )->setProductClassId($productTaxClassId);
        $rate = (float) $this->taxCalculation->getRate($request);
        $storeRate = (float) $this->taxCalculation->getStoreRate($request, (int) $store->getId());
        // Cross-border trade means the store rate is irrelevant: Magento then
        // treats prices as tax-included at the customer rate (isSameRateAsStore).
        if (abs($rate - $storeRate) < 0.00001 || (bool) $store->getConfig('tax/calculation/cross_border_trade_enabled')) {
            $storeRate = $rate;
        }

        return ['rate' => $rate, 'storeRate' => $storeRate];
    }

    /**
     * The price the shipping rate is injected with so the grand total comes
     * out as the everysize sum: when the shop taxes shipping "included" the
     * price is converted from the store rate to the address rate (like the
     * line items), with tax excluded the everysize gross is the net price.
     * A shop without a shipping tax class gets no VAT on shipping at all.
     *
     * @param array<string, string> $billing
     * @param array<string, string> $shipping
     */
    private function shippingRatePrice(StoreInterface $store, array $billing, array $shipping, int $customerTaxClassId, float $shippingCosts): float
    {
        $shippingTaxClassId = (int) $store->getConfig('tax/classes/shipping_tax_class');
        if ($shippingTaxClassId === 0) {
            return round($shippingCosts, 2);
        }
        ['rate' => $rate, 'storeRate' => $storeRate] = $this->taxRates($store, $billing, $shipping, $customerTaxClassId, $shippingTaxClassId);
        if (!(bool) $store->getConfig('tax/shipping/shipping_includes_tax')) {
            return round($shippingCosts / (1 + $rate / 100), 2);
        }

        return round($shippingCosts * (1 + $storeRate / 100) / (1 + $rate / 100), 2);
    }

    /**
     * The SKU everysize sends is the SKU of the variant the customer bought.
     * Magento SKUs are not unique under case-insensitive collation: an exact
     * (case-sensitive) match beats a case-insensitive one, an enabled
     * product a disabled one; two equally good candidates are an error. A
     * simple child of a configurable is returned with its parent and the
     * super-attribute map so the order looks like a regular checkout order.
     * The parent is resolved via the Configurable resource (row_id-safe on
     * Adobe Commerce); a disabled parent or several parents fall back to
     * the simple product alone.
     *
     * @return array{product: ProductInterface, parent: ?ProductInterface, superAttribute: array<int, int>, disabled: bool, outOfStock: bool}
     */
    private function findProduct(string $sku, StoreInterface $store): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            'SELECT p.entity_id, (BINARY p.sku = BINARY :sku) AS exact, COALESCE(st.value, 1) AS status'
            . ' FROM ' . $this->resource->getTableName('catalog_product_entity') . ' p'
            . ' LEFT JOIN ' . $this->resource->getTableName('catalog_product_entity_int') . ' st'
            . ' ON st.entity_id = p.entity_id AND st.attribute_id = :statusAttr AND st.store_id = 0'
            . ' WHERE p.sku = :sku'
            . ' ORDER BY exact DESC, COALESCE(st.value, 1) ASC',
            [':sku' => $sku, ':statusAttr' => $this->statusAttributeId()]
        );
        if ($rows === []) {
            throw new ImportException(sprintf('No product with SKU %s found in the shop', $sku));
        }
        if (\count($rows) > 1
            && (int) $rows[0]['exact'] === (int) $rows[1]['exact']
            && (int) $rows[0]['status'] === (int) $rows[1]['status']
        ) {
            throw new ImportException(sprintf('SKU %s is not unique in the shop', $sku));
        }

        $product = $this->productRepository->getById((int) $rows[0]['entity_id'], false, (int) $store->getId(), true);
        $disabled = (int) $rows[0]['status'] !== 1;
        $outOfStock = $this->isOutOfStock($product);

        // Adobe Commerce has entity_id and row_id; the link tables reference
        // the entity, so resolve the parent over the resource model.
        $parentIds = array_map('intval', (array) $this->configurableResource->getParentIdsByChild((int) $product->getId()));
        $parent = null;
        $superAttribute = [];
        if ($parentIds === []) {
            if ($product->getTypeId() === 'configurable') {
                throw new ImportException(sprintf(
                    'SKU %s is a configurable product; everysize orders need the bought variant',
                    $sku
                ));
            }
        } else {
            // Several parents or a disabled one: only an enabled parent is
            // used, otherwise the simple product is imported alone.
            foreach ($parentIds as $parentId) {
                $candidate = $this->productRepository->getById($parentId, false, (int) $store->getId(), true);
                if ((int) $candidate->getStatus() === 1) {
                    $parent = $candidate;
                    break;
                }
            }
        }

        if ($parent !== null) {
            $attributes = $connection->fetchAll(
                'SELECT ea.attribute_id, ea.attribute_code FROM ' . $this->resource->getTableName('catalog_product_super_attribute') . ' psa'
                . ' JOIN ' . $this->resource->getTableName('eav_attribute') . ' ea ON ea.attribute_id = psa.attribute_id'
                . ' WHERE psa.product_id = :parentId',
                [':parentId' => (int) $parent->getId()]
            );
            $productResource = $this->productResource;
            foreach ($attributes as $attribute) {
                $code = (string) $attribute['attribute_code'];
                $value = $productResource->getAttributeRawValue((int) $product->getId(), $code, 0);
                if ($value === null || $value === false || $value === '') {
                    throw new ImportException(sprintf(
                        'Product %s has no value for the variant attribute %s',
                        $sku,
                        $code
                    ));
                }
                $superAttribute[(int) $attribute['attribute_id']] = (int) $value;
            }
        }

        return ['product' => $product, 'parent' => $parent, 'superAttribute' => $superAttribute, 'disabled' => $disabled, 'outOfStock' => $outOfStock];
    }

    /**
     * Whether the stock status index marks the product as out of stock
     * (cataloginventory_stock_status is kept by the index on CE and Adobe).
     */
    private function isOutOfStock(ProductInterface $product): bool
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['s' => $this->resource->getTableName('cataloginventory_stock_status')], ['stock_status'])
            ->where('product_id = :id AND stock_id = 1')
            ->limit(1);
        $status = $connection->fetchOne($select, [':id' => (int) $product->getId()]);

        return $status !== false && $status !== null && (int) $status !== 1;
    }

    private function statusAttributeId(): int
    {
        if ($this->statusAttributeId === null) {
            $this->statusAttributeId = (int) $this->resource->getConnection()->fetchOne(
                'SELECT ea.attribute_id FROM ' . $this->resource->getTableName('eav_attribute') . ' ea'
                . ' JOIN ' . $this->resource->getTableName('eav_entity_type') . ' et ON et.entity_type_id = ea.entity_type_id'
                . " WHERE et.entity_type_code = 'catalog_product' AND ea.attribute_code = 'status'"
            );
        }

        return $this->statusAttributeId;
    }

    /**
     * Customers are identified by the e-mail everysize sends — a forwarding
     * alias, stable per customer. A returning customer keeps the existing
     * account untouched; everyone else becomes a guest order.
     */
    private function findCustomerId(string $email, StoreInterface $store): ?int
    {
        try {
            $customer = $this->customerRepository->get($email, (int) $store->getWebsiteId());
        } catch (NoSuchEntityException $e) {
            return null;
        }

        return (int) $customer->getId();
    }

    /**
     * The customer group's tax class — the shop's tax rules decide the VAT
     * per group, guests use the not-logged-in class.
     */
    private function customerTaxClassId(?int $customerId): int
    {
        if ($customerId === null) {
            return (int) $this->groupManagement->getNotLoggedInGroup()->getTaxClassId();
        }
        $customer = $this->customerRepository->getById($customerId);

        return (int) $this->groupManagement->getGroupById($customer->getGroupId())->getTaxClassId();
    }

    /**
     * The quote-address fields for an everysize address. The telephone falls
     * back to the everysize customer's phone earlier; "-" fills the required
     * field when nothing is known.
     *
     * @param array<string, string> $data
     *
     * @return array<string, mixed>
     */
    private function quoteAddressData(array $data, string $email): array
    {
        return [
            'firstname' => $data['firstName'],
            'lastname' => $data['lastName'],
            'email' => $email !== '' ? $email : '-',
            'street' => $data['addInfo'] !== '' ? [$data['street'], $data['addInfo']] : [$data['street']],
            'city' => $data['city'],
            'postcode' => $data['zip'],
            'country_id' => $data['countryId'],
            'telephone' => $data['telephone'] !== '' ? $data['telephone'] : '-',
            'save_in_address_book' => 0,
            'should_ignore_validation' => true,
        ];
    }

    /**
     * A detached address for the tax rate request: the shop's "tax based on"
     * setting decides which parts count (usually country/region/postcode).
     *
     * @param array<string, string> $data
     */
    private function requestAddress(array $data): QuoteAddress
    {
        $address = $this->quoteAddressFactory->create();
        $address->setData([
            'country_id' => $data['countryId'],
            'postcode' => $data['zip'],
            'city' => $data['city'],
        ]);

        return $address;
    }

    /**
     * The shipping rate of the imported order: the everysize carrier with
     * the shipping costs the customer paid. collectShippingRates is left off
     * so the injected rate survives the submit. The price is the converted
     * rate price from shippingRatePrice().
     */
    private function injectShippingRate(QuoteAddress $address, float $ratePrice): void
    {
        $address->setCollectShippingRates(false);
        $rate = $this->rateFactory->create();
        $rate->setCode('everysize_everysize');
        $rate->setCarrier('everysize');
        $rate->setCarrierTitle('everysize');
        $rate->setMethod('everysize');
        $rate->setMethodTitle('everysize');
        $rate->setPrice($ratePrice);
        $address->addShippingRate($rate);
        $address->setShippingMethod('everysize_everysize');
        $address->setShippingDescription('everysize - everysize');
    }

    /**
     * @param array<string, mixed>|null $address
     *
     * @return array{firstName: string, lastName: string, street: string, addInfo: string, zip: string, city: string, countryId: string, telephone: string}
     */
    private function address($address, string $field): array
    {
        if (!\is_array($address)) {
            throw new ImportException(sprintf('everysize order has no %s', $field));
        }

        $street = $this->requireString($address, 'addressLine1', $field . '.addressLine1');
        $countryCode = strtoupper($this->requireString($address, 'countryCode', $field . '.countryCode'));
        $country = $this->countryFactory->create()->loadByCode($countryCode);
        if ($country->getId() === null) {
            throw new ImportException(sprintf('Unknown country %s in %s', $countryCode, $field));
        }

        $telephone = trim((string) ($address['phone'] ?? $address['telephone'] ?? ''));

        return [
            'firstName' => $this->sanitizeName((string) ($address['firstName'] ?? ''), $field . '.firstName', false),
            'lastName' => $this->sanitizeName($this->requireString($address, 'lastName', $field . '.lastName'), $field . '.lastName', true),
            'street' => $street,
            'addInfo' => trim((string) ($address['addressLine2'] ?? '')),
            'zip' => $this->requireString($address, 'postalCode', $field . '.postalCode'),
            'city' => $this->requireString($address, 'city', $field . '.city'),
            'countryId' => $countryCode,
            'telephone' => $telephone,
        ];
    }

    /**
     * A country the store does not ship to, or a country whose region is
     * mandatory (Magento rejects such addresses on submit) is rejected
     * before anything is written — the import retries until the order data
     * fits the shop.
     *
     * @param array<string, string> $data
     */
    private function validateCountry(StoreInterface $store, array $data): void
    {
        $allowed = array_map(
            static function (string $code): string {
                return strtoupper(trim($code));
            },
            explode(',', (string) $store->getConfig('general/country/allow'))
        );
        if ($allowed !== [''] && !\in_array($data['countryId'], $allowed, true)) {
            throw new ImportException(sprintf(
                'Country %s is not allowed in the shop (allowed: %s)',
                $data['countryId'],
                implode(', ', $allowed)
            ));
        }
        $statesRequired = explode(',', (string) $store->getConfig('general/region/state_required'));
        if (\in_array($data['countryId'], $statesRequired, true) && trim($data['region'] ?? '') === '') {
            throw new ImportException(sprintf(
                'Country %s requires a region, the everysize order has none',
                $data['countryId']
            ));
        }
    }

    /**
     * Magento rejects customer names with characters outside its name
     * pattern (e.g. parentheses) — guest order addresses are validated as
     * customer addresses on submit. Characters Magento does not accept are
     * removed instead of failing the whole import. A last name that cleans
     * to nothing is a data error; an empty first name becomes "-" so the
     * required field passes validation.
     */
    private function sanitizeName(string $name, string $field, bool $required): string
    {
        $clean = trim((string) preg_replace('/[^\p{L}\p{M},\-_.\'’`&\s\d]/u', '', trim($name)));
        if ($clean === '') {
            if ($required) {
                throw new ImportException(sprintf('everysize order has no %s', $field));
            }

            return '-';
        }

        return $clean;
    }

    private function validEmail($email): string
    {
        $value = trim((string) ($email ?? ''));

        return filter_var($value, \FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    /**
     * everysize sends ISO 8601 with offset; Magento stores UTC.
     */
    private function utcDate($value): string
    {
        $timestamp = \is_string($value) ? strtotime($value) : false;

        return gmdate('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key, ?string $label = null): string
    {
        $value = $data[$key] ?? null;
        if (!\is_scalar($value) || trim((string) $value) === '') {
            throw new ImportException(sprintf('everysize order has no %s', $label ?? $key));
        }

        return trim((string) $value);
    }
}
