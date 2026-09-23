<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Mojo\OxidExport\Db;

/**
 * Exports OXID orders (oxorder + oxorderarticles) as self-contained snapshots
 * with computed totals and a per-rate tax breakdown (PHP 7.2).
 */
final class OrderExporter
{
    /** @var Connection */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
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
            $lines = isset($lineItemsByOrder[$oxid]) ? $lineItemsByOrder[$oxid] : [];

            $shipGross = (float) (isset($r['OXDELCOST']) ? $r['OXDELCOST'] : 0.0);
            $shipRate  = (float) (isset($r['OXDELVAT']) ? $r['OXDELVAT'] : 0.0);
            $shipNet   = $shipRate > 0.0 ? round($shipGross / (1 + $shipRate / 100), 4) : $shipGross;

            $taxes      = $this->taxBreakdown($lines, $shipGross, $shipRate);
            $totalTax   = array_sum(array_column($taxes, 'tax'));
            $grossTotal = (float) (isset($r['OXTOTALORDERSUM']) ? $r['OXTOTALORDERSUM'] : 0.0);
            $netTotal   = round($grossTotal - $totalTax, 4);

            $currencyFactor = (float) (isset($r['OXCURRATE']) ? $r['OXCURRATE'] : 1.0);
            if ($currencyFactor <= 0.0) {
                $currencyFactor = 1.0;
            }

            $orders[] = [
                'oxid'            => $oxid,
                'orderNumber'     => (string) (isset($r['OXORDERNR']) ? $r['OXORDERNR'] : $oxid),
                'orderDate'       => $this->isoDate((string) (isset($r['OXORDERDATE']) ? $r['OXORDERDATE'] : '')),
                'orderDateValid'  => $this->isoDate((string) (isset($r['OXORDERDATE']) ? $r['OXORDERDATE'] : '')) !== null,
                'currency'        => (string) (isset($r['OXCURRENCY']) ? $r['OXCURRENCY'] : 'EUR'),
                'currencyFactor'  => $currencyFactor,
                'paid'            => $this->isRealDate((string) (isset($r['OXPAID']) ? $r['OXPAID'] : '')),
                'sentDate'        => $this->isRealDate((string) (isset($r['OXSENDDATE']) ? $r['OXSENDDATE'] : '')) ? $this->isoDate((string) $r['OXSENDDATE']) : null,
                'folder'          => $this->str($r, 'OXFOLDER'),
                'canceled'        => (int) (isset($r['OXSTORNO']) ? $r['OXSTORNO'] : 0) === 1,
                'email'           => $this->str($r, 'OXBILLEMAIL'),
                'paymentType'     => $this->str($r, 'OXPAYMENTTYPE'),
                'customerOxid'    => $this->str($r, 'OXUSERID'),
                'grossTotal'      => $grossTotal,
                'netTotal'        => $netTotal,
                'shippingGross'   => $shipGross,
                'shippingNet'     => $shipNet,
                'shippingTaxRate' => $shipRate,
                'taxes'           => $taxes,
                'billing'         => [
                    'salutation' => $this->str($r, 'OXBILLSAL'),
                    'firstName'  => $this->str($r, 'OXBILLFNAME'),
                    'lastName'   => $this->str($r, 'OXBILLLNAME'),
                    'company'    => $this->str($r, 'OXBILLCOMPANY'),
                    'street'     => trim((string) (isset($r['OXBILLSTREET']) ? $r['OXBILLSTREET'] : '') . ' ' . (string) (isset($r['OXBILLSTREETNR']) ? $r['OXBILLSTREETNR'] : '')),
                    'zipcode'    => $this->str($r, 'OXBILLZIP'),
                    'city'       => $this->str($r, 'OXBILLCITY'),
                    'countryIso' => $this->str($r, 'BILL_ISO'),
                    'phone'      => $this->str($r, 'OXBILLFON'),
                ],
                'shipping'        => $this->shippingAddress($r),
                'lineItems'       => $lines,
            ];
        }

        return $orders;
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function lineItems(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM oxorderarticles ORDER BY OXORDERID ASC, OXID ASC');

        $map = [];
        foreach ($rows as $r) {
            $orderId = (string) $r['OXORDERID'];
            $qty     = (float) (isset($r['OXAMOUNT']) ? $r['OXAMOUNT'] : 1.0);

            $map[$orderId][] = [
                'productNumber'   => $this->str($r, 'OXARTNUM'),
                'label'           => $this->str($r, 'OXTITLE') !== null ? $this->str($r, 'OXTITLE') : 'Artikel',
                'quantity'        => (int) round($qty),
                'unitPriceGross'  => (float) (isset($r['OXBPRICE']) ? $r['OXBPRICE'] : 0.0),
                'totalPriceGross' => (float) (isset($r['OXBRUTPRICE']) ? $r['OXBRUTPRICE'] : 0.0),
                'taxRate'         => (float) (isset($r['OXVAT']) ? $r['OXVAT'] : 0.0),
                'taxAmount'       => (float) (isset($r['OXVATPRICE']) ? $r['OXVATPRICE'] : 0.0),
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
            $key = (string) $rate;
            $byRate[$key]['taxRate'] = $rate;
            $byRate[$key]['tax']     = (isset($byRate[$key]['tax']) ? $byRate[$key]['tax'] : 0.0) + (float) $l['taxAmount'];
            $byRate[$key]['price']   = (isset($byRate[$key]['price']) ? $byRate[$key]['price'] : 0.0) + (float) $l['totalPriceGross'];
        }

        if ($shipGross > 0.0) {
            $shipNet = $shipRate > 0.0 ? $shipGross / (1 + $shipRate / 100) : $shipGross;
            $shipTax = round($shipGross - $shipNet, 4);
            $key = (string) $shipRate;
            $byRate[$key]['taxRate'] = $shipRate;
            $byRate[$key]['tax']     = (isset($byRate[$key]['tax']) ? $byRate[$key]['tax'] : 0.0) + $shipTax;
            $byRate[$key]['price']   = (isset($byRate[$key]['price']) ? $byRate[$key]['price'] : 0.0) + $shipGross;
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
    private function shippingAddress(array $r)
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
            'street'     => trim((string) (isset($r['OXDELSTREET']) ? $r['OXDELSTREET'] : '') . ' ' . (string) (isset($r['OXDELSTREETNR']) ? $r['OXDELSTREETNR'] : '')),
            'zipcode'    => $this->str($r, 'OXDELZIP'),
            'city'       => $this->str($r, 'OXDELCITY'),
            'countryIso' => $this->str($r, 'DEL_ISO'),
        ];
    }

    private function isRealDate(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && strncmp($value, '0000-00-00', 10) !== 0 && strtotime($value) !== false;
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
        $v = isset($row[$key]) ? $row[$key] : null;
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
