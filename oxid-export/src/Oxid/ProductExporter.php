<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Doctrine\DBAL\Connection;

/**
 * Reads `oxarticles` (+ long description, category refs, attributes, images,
 * manufacturer) and emits product detail records.
 *
 * Prices: OXPRICE is the GROSS price in a default (gross-price) shop config;
 * the net price is derived from the article VAT (OXVAT), falling back to the
 * mapping default when null.
 *
 * Properties: resolved from the YAML mapping (oxattribute values or oxarticles
 * columns). Used group/option combinations are accumulated and exposed via
 * propertyGroups() so the importer can pre-create the property tree.
 *
 * Images: OXPIC1..OXPIC12 filenames are turned into absolute URLs using
 * images.base_url from the mapping (".../out/pictures/master/product").
 */
final class ProductExporter
{
    /** @var array<string,array<string,bool>> group => ordered set of options */
    private array $propertyGroups = [];

    private readonly ImageCollector $images;

    public function __construct(
        private readonly Connection $db,
        private readonly ExportMapping $mapping,
    ) {
        $this->images = new ImageCollector(
            $mapping->imagesBaseUrl,
            $mapping->imagesSourceDir,
            $mapping->imagesCopyTo,
        );
    }

    public function copiedImageCount(): int
    {
        return $this->images->copiedCount();
    }

    public function imageCollector(): ImageCollector
    {
        return $this->images;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function export(): array
    {
        $categoryMap = $this->categoryAssignments();
        $longDesc    = $this->longDescriptions();
        $attributes  = $this->attributeValues();

        $rows = $this->db->fetchAllAssociative('SELECT * FROM oxarticles ORDER BY OXID');

        $out = [];
        foreach ($rows as $r) {
            $oxid  = (string) $r['OXID'];
            $vat   = $this->mapping->resolveVat($r['OXVAT'] ?? null);
            $gross = (float) ($r['OXPRICE'] ?? 0.0);
            $net   = $vat > 0.0 ? round($gross / (1 + $vat / 100), 4) : $gross;

            $parent = (string) ($r['OXPARENTID'] ?? '');
            $parent = $parent !== '' ? $parent : null;

            $artNum        = trim((string) ($r['OXARTNUM'] ?? ''));
            $productNumber = $artNum !== '' ? $artNum : 'OX-' . $oxid;

            $manufacturerOxid = trim((string) ($r['OXMANUFACTURERID'] ?? ''));

            $out[] = [
                'oxid'               => $oxid,
                'parentOxid'         => $parent,
                'productNumber'      => $productNumber,
                'name'               => $this->str($r, 'OXTITLE') ?? $productNumber,
                'shortDescription'   => $this->str($r, 'OXSHORTDESC'),
                'longDescription'    => $longDesc[$oxid] ?? null,
                'ean'                => $this->str($r, 'OXEAN'),
                'manufacturerNumber' => $this->str($r, 'OXMPN'),
                'manufacturerOxid'   => $manufacturerOxid !== '' ? $manufacturerOxid : null,
                'stock'              => (int) ($r['OXSTOCK'] ?? 0),
                'priceGross'         => $gross,
                'priceNet'           => $net,
                'vat'                => $vat,
                'weight'             => $this->floatOrNull($r, 'OXWEIGHT'),
                'width'              => $this->floatOrNull($r, 'OXWIDTH'),
                'height'             => $this->floatOrNull($r, 'OXHEIGHT'),
                'length'             => $this->floatOrNull($r, 'OXLENGTH'),
                'active'             => (int) ($r['OXACTIVE'] ?? 1) === 1,
                'categoryOxids'      => $categoryMap[$oxid] ?? [],
                'images'             => $this->resolveImages($productNumber, $r),
                'properties'         => $this->resolveProperties($r, $attributes[$oxid] ?? []),
            ];
        }

        return $out;
    }

    /**
     * Distinct property groups + options discovered during export().
     *
     * @return array<int,array{name:string,options:array<int,string>}>
     */
    public function propertyGroups(): array
    {
        $groups = [];
        foreach ($this->propertyGroups as $group => $options) {
            $groups[] = ['name' => $group, 'options' => array_keys($options)];
        }

        return $groups;
    }

    /**
     * @param array<string,mixed>                 $row
     * @param array<int,array{attr:string,val:string}> $attrRows
     * @return array<int,array{group:string,option:string}>
     */
    private function resolveProperties(array $row, array $attrRows): array
    {
        $result = [];

        foreach ($this->mapping->properties as $pm) {
            $rawValues = [];

            if ($pm->source === 'field' && $pm->oxidField !== null) {
                $rawValues[] = (string) ($row[$pm->oxidField] ?? '');
            } elseif ($pm->source === 'attribute' && $pm->oxidAttribute !== null) {
                foreach ($attrRows as $ar) {
                    if (strcasecmp($ar['attr'], $pm->oxidAttribute) === 0) {
                        $rawValues[] = $ar['val'];
                    }
                }
            }

            foreach ($rawValues as $raw) {
                $label = $pm->label($raw);
                if ($label === null) {
                    continue;
                }
                $result[] = ['group' => $pm->group, 'option' => $label];
                $this->propertyGroups[$pm->group][$label] = true;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,array<string,mixed>>
     */
    private function resolveImages(string $productNumber, array $row): array
    {
        if (!$this->images->enabled()) {
            return [];
        }

        $images = [];
        $position = 0;
        foreach ($this->mapping->picFields as $i) {
            $file = trim((string) ($row['OXPIC' . $i] ?? ''));
            if ($file === '') {
                continue;
            }

            $entry = $this->images->collect($productNumber, $i, $file, $position);
            if ($entry !== null) {
                $images[] = $entry;
                $position++;
            }
        }

        return $images;
    }

    /**
     * @return array<string,array<int,string>> productOxid => [categoryOxid, ...]
     */
    private function categoryAssignments(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT OXOBJECTID, OXCATNID FROM oxobject2category ORDER BY OXPOS ASC'
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['OXOBJECTID']][] = (string) $r['OXCATNID'];
        }

        return $map;
    }

    /**
     * @return array<string,string> productOxid => longDescription
     */
    private function longDescriptions(): array
    {
        $map = [];
        foreach ($this->db->fetchAllAssociative('SELECT OXID, OXLONGDESC FROM oxartextends') as $r) {
            $value = trim((string) ($r['OXLONGDESC'] ?? ''));
            if ($value !== '') {
                $map[(string) $r['OXID']] = $value;
            }
        }

        return $map;
    }

    /**
     * @return array<string,array<int,array{attr:string,val:string}>>
     */
    private function attributeValues(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT o2a.OXOBJECTID AS PID, a.OXTITLE AS ATTR, o2a.OXVALUE AS VAL
             FROM oxobject2attribute o2a
             INNER JOIN oxattribute a ON a.OXID = o2a.OXATTRID
             ORDER BY o2a.OXPOS ASC'
        );

        $map = [];
        foreach ($rows as $r) {
            $attr = trim((string) ($r['ATTR'] ?? ''));
            $val  = trim((string) ($r['VAL'] ?? ''));
            if ($attr === '' || $val === '') {
                continue;
            }
            $map[(string) $r['PID']][] = ['attr' => $attr, 'val' => $val];
        }

        return $map;
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

    /**
     * @param array<string,mixed> $row
     */
    private function floatOrNull(array $row, string $key): ?float
    {
        $v = $row[$key] ?? null;
        if ($v === null || $v === '' || (float) $v === 0.0) {
            return null;
        }

        return (float) $v;
    }
}
