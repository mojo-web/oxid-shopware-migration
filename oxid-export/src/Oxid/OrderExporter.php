<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Doctrine\DBAL\Connection;

/**
 * Exports OXID orders (oxorder + oxorderarticles) as self-contained snapshots.
 *
 * Totals and a per-rate tax breakdown are computed here so the Shopware
 * importer only has to map values into the order/cart-price structures.
 *
 * OXID price conventions assumed (default gross-price shop):
 *   - oxorder.OXTOTALORDERSUM = final gross total incl. shipping
 *   - oxorder.OXDELCOST       = gross shipping cost, OXDELVAT = its VAT %
 *   - oxorderarticles.OXBPRICE/OXBRUTPRICE = gross unit/line totals,
 *     OXVAT = line VAT %, OXVATPRICE = line tax amount
 */
final class OrderExporter
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function export(): array
    {
        $lineItemsByOrder = $this->lineItems();

        $rows = $this->db->fetchAllAssociative(
            'SELECT o.*,
                    bc.OXISOALPHA2 AS BILL_ISO,
                    dc.OXISOALPHA2 AS DEL_ISO
             FROM oxorder o
             LEFT JOIN oxcountry bc ON bc.OXID = o.OXBILLCOUNTRYID
             LEFT JOIN oxcountry dc ON dc.OXID = o.OXDELCOUNTRYID
             ORDER BY o.OXORDERNR ASC'
        );

        $orders = [];
        foreach ($rows as $r) {
            $oxid  = (string) $r['OXID'];
            $lines = $lineItemsByOrder[$oxid] ?? [];

            $shipGross = (float) ($r['OXDELCOST'] ?? 0.0);
            $shipRate  = (float) ($r['OXDELVAT'] ?? 0.0);
            $shipNet   = $shipRate > 0.0 ? round($shipGross / (1 + $shipRate / 100), 4) : $shipGross;

            $taxes      = $this->taxBreakdown($lines, $shipGross, $shipRate);
            $totalTax   = array_sum(array_column($taxes, 'tax'));
            $grossTotal = (float) ($r['OXTOTALORDERSUM'] ?? 0.0);
            $netTotal   = round($grossTotal - $totalTax, 4);

            $paid     = $this->isRealDate((string) ($r['OXPAID'] ?? ''));
            $canceled = (int) ($r['OXSTORNO'] ?? 0) === 1;

            $orders[] = [
                'oxid'           => $oxid,
                'orderNumber'    => (string) ($r['OXORDERNR'] ?? $oxid),
                'orderDate'      => $this->isoDate((string) ($r['OXORDERDATE'] ?? '')),
                'orderDateValid' => $this->isoDate((string) ($r['OXORDERDATE'] ?? '')) !== null,
                'currency'       => (string) ($r['OXCURRENCY'] ?? 'EUR'),
                'currencyFactor' => (float) ($r['OXCURRATE'] ?? 1.0) ?: 1.0,
                'paid'           => $paid,
                'sentDate'       => $this->isRealDate((string) ($r['OXSENDDATE'] ?? '')) ? $this->isoDate((string) $r['OXSENDDATE']) : null,
                'folder'          => $this->str($r, 'OXFOLDER'),
                'canceled'       => $canceled,
                'email'          => $this->str($r, 'OXBILLEMAIL'),
                'paymentType'     => $this->str($r, 'OXPAYMENTTYPE'),
                'grossTotal'     => $grossTotal,
                'netTotal'       => $netTotal,
                'shippingGross'  => $shipGross,
                'shippingNet'    => $shipNet,
                'shippingTaxRate' => $shipRate,
                'taxes'          => $taxes,
                'billing'        => [
                    'salutation' => $this->str($r, 'OXBILLSAL'),
                    'firstName'  => $this->str($r, 'OXBILLFNAME'),
                    'lastName'   => $this->str($r, 'OXBILLLNAME'),
                    'company'    => $this->str($r, 'OXBILLCOMPANY'),
                    'street'     => trim((string) ($r['OXBILLSTREET'] ?? '') . ' ' . (string) ($r['OXBILLSTREETNR'] ?? '')),
                    'zipcode'    => $this->str($r, 'OXBILLZIP'),
                    'city'       => $this->str($r, 'OXBILLCITY'),
                    'countryIso' => $this->str($r, 'BILL_ISO'),
                    'phone'      => $this->str($r, 'OXBILLFON'),
                ],
                'shipping'       => $this->shippingAddress($r),
                'lineItems'      => $lines,
            ];
        }

        return $orders;
    }

    /**
     * @return array<string,array<int,array<string,mixed>>> orderOxid => line items
     */
    private function lineItems(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM oxorderarticles ORDER BY OXORDERID ASC, OXID ASC');

        $map = [];
        foreach ($rows as $r) {
            $orderId = (string) $r['OXORDERID'];
            $qty     = (float) ($r['OXAMOUNT'] ?? 1.0);

            $map[$orderId][] = [
                'productNumber'   => $this->str($r, 'OXARTNUM'),
                'label'           => $this->str($r, 'OXTITLE') ?? 'Artikel',
                'quantity'        => (int) round($qty),
                'unitPriceGross'  => (float) ($r['OXBPRICE'] ?? 0.0),
                'totalPriceGross' => (float) ($r['OXBRUTPRICE'] ?? 0.0),
                'taxRate'         => (float) ($r['OXVAT'] ?? 0.0),
                'taxAmount'       => (float) ($r['OXVATPRICE'] ?? 0.0),
            ];
        }

        return $map;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array{taxRate:float,tax:float,price:float}>
     */
    private function taxBreakdown(array $lines, float $shipGross, float $shipRate): array
    {
        $byRate = [];
        foreach ($lines as $l) {
            $rate = (float) $l['taxRate'];
            $byRate[(string) $rate]['taxRate'] = $rate;
            $byRate[(string) $rate]['tax']     = ($byRate[(string) $rate]['tax'] ?? 0.0) + (float) $l['taxAmount'];
            $byRate[(string) $rate]['price']   = ($byRate[(string) $rate]['price'] ?? 0.0) + (float) $l['totalPriceGross'];
        }

        if ($shipGross > 0.0) {
            $shipNet = $shipRate > 0.0 ? $shipGross / (1 + $shipRate / 100) : $shipGross;
            $shipTax = round($shipGross - $shipNet, 4);
            $byRate[(string) $shipRate]['taxRate'] = $shipRate;
            $byRate[(string) $shipRate]['tax']     = ($byRate[(string) $shipRate]['tax'] ?? 0.0) + $shipTax;
            $byRate[(string) $shipRate]['price']   = ($byRate[(string) $shipRate]['price'] ?? 0.0) + $shipGross;
        }

        $out = [];
        foreach ($byRate as $entry) {
            $out[] = [
                'taxRate' => (float) $entry['taxRate'],
                'tax'     => round((float) $entry['tax'], 4),
                'price'   => round((float) $entry['price'], 4),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>|null
     */
    private function shippingAddress(array $r): ?array
    {
        $fname = $this->str($r, 'OXDELFNAME');
        $lname = $this->str($r, 'OXDELLNAME');
        if ($fname === null && $lname === null) {
            return null;
        }

        return [
            'salutation' => $this->str($r, 'OXDELSAL'),
            'firstName'  => $fname,
            'lastName'   => $lname,
            'company'    => $this->str($r, 'OXDELCOMPANY'),
            'street'     => trim((string) ($r['OXDELSTREET'] ?? '') . ' ' . (string) ($r['OXDELSTREETNR'] ?? '')),
            'zipcode'    => $this->str($r, 'OXDELZIP'),
            'city'       => $this->str($r, 'OXDELCITY'),
            'countryIso' => $this->str($r, 'DEL_ISO'),
        ];
    }

    private function isRealDate(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && !str_starts_with($value, '0000-00-00') && strtotime($value) !== false;
    }

    private function isoDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strncmp($value, '0000-00-00', 10) === 0) {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : date('c', $ts);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function str(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
