<?php

declare(strict_types=1);

namespace Everysize\Checkout\Model\Import;

/**
 * Turns the gross amounts everysize charged into the net/VAT figures Magento
 * stores on an order. everysize sends gross prices only; the VAT rate comes
 * from the product's tax class / the store's tax rules.
 */
final class PriceCalculator
{
    private function __construct()
    {
    }

    /**
     * @param array<int, array{gross: float, quantity: int, vat: float}> $items unit gross price, quantity, VAT rate
     *
     * @return array{
     *     items: array<int, array{unitGross: float, unitNet: float, totalGross: float, totalNet: float, totalVat: float, vat: float}>,
     *     totalGross: float,
     *     totalNet: float,
     *     vatGroups: array<string, float>,
     *     deliveryCost: float,
     *     deliveryVat: float,
     *     totalOrderSum: float
     * }
     */
    public static function calculate(array $items, float $shippingGross): array
    {
        $result = [
            'items' => [],
            'totalGross' => 0.0,
            'totalNet' => 0.0,
            'vatGroups' => [],
            'deliveryCost' => round($shippingGross, 2),
            'deliveryVat' => 0.0,
            'totalOrderSum' => 0.0,
        ];

        foreach ($items as $key => $item) {
            $vat = (float) $item['vat'];
            $unitGross = round($item['gross'], 2);
            $totalGross = round($unitGross * $item['quantity'], 2);
            $totalNet = self::net($totalGross, $vat);
            $totalVat = round($totalGross - $totalNet, 2);

            $result['items'][$key] = [
                'unitGross' => $unitGross,
                'unitNet' => self::net($unitGross, $vat),
                'totalGross' => $totalGross,
                'totalNet' => $totalNet,
                'totalVat' => $totalVat,
                'vat' => $vat,
            ];

            $result['totalGross'] += $totalGross;
            $result['totalNet'] += $totalNet;
            $rate = self::rateKey($vat);
            $result['vatGroups'][$rate] = round(($result['vatGroups'][$rate] ?? 0.0) + $totalVat, 2);
            // Shipping carries the VAT rate of the items; an everysize order has one item, with several the highest.
            $result['deliveryVat'] = max($result['deliveryVat'], $vat);
        }

        $result['totalGross'] = round($result['totalGross'], 2);
        $result['totalNet'] = round($result['totalNet'], 2);
        $result['totalOrderSum'] = round($result['totalGross'] + $result['deliveryCost'], 2);
        krsort($result['vatGroups'], \SORT_NUMERIC);

        return $result;
    }

    /**
     * Warns when the per-item + shipping sum differs from the everysize order
     * total (more than a cent); null means consistent or not given.
     */
    public static function checkTotal(?string $grossPrice, float $totalOrderSum): ?string
    {
        if ($grossPrice === null || $grossPrice === '') {
            return null;
        }

        $expected = round((float) $grossPrice, 2);
        if (abs($expected - $totalOrderSum) <= 0.009) {
            return null;
        }

        return sprintf(
            'total %.2f differs from everysize grossPrice %.2f',
            $totalOrderSum,
            $expected
        );
    }

    private static function net(float $gross, float $vat): float
    {
        return round($gross / (1 + $vat / 100), 2);
    }

    private static function rateKey(float $vat): string
    {
        return rtrim(rtrim(number_format($vat, 2, '.', ''), '0'), '.');
    }
}
