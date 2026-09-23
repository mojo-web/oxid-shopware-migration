<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Doctrine\DBAL\Connection;

/**
 * Reads oxarticles (+ long desc, categories, attributes, images, manufacturer)
 * and emits product detail records (PHP 7.2).
 */
final class ProductExporter
{
    /** @var array<string,array<string,bool>> */
    private $propertyGroups = [];
    /** @var Connection */
    private $db;
    /** @var ExportMapping */
    private $mapping;
    /** @var ImageCollector */
    private $images;

    public function __construct(Connection $db, ExportMapping $mapping)
    {
        $this->db = $db;
        $this->mapping = $mapping;
        $this->images = new ImageCollector(
            $mapping->imagesBaseUrl,
            $mapping->imagesSourceDir,
            $mapping->imagesCopyTo
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
            $vat   = $this->mapping->resolveVat(isset($r['OXVAT']) ? $r['OXVAT'] : null);
            $gross = (float) (isset($r['OXPRICE']) ? $r['OXPRICE'] : 0.0);
            $net   = $vat > 0.0 ? round($gross / (1 + $vat / 100), 4) : $gross;

            $parent = (string) (isset($r['OXPARENTID']) ? $r['OXPARENTID'] : '');
            $parent = $parent !== '' ? $parent : null;

            $artNum        = trim((string) (isset($r['OXARTNUM']) ? $r['OXARTNUM'] : ''));
            $productNumber = $artNum !== '' ? $artNum : 'OX-' . $oxid;

            $manufacturerOxid = trim((string) (isset($r['OXMANUFACTURERID']) ? $r['OXMANUFACTURERID'] : ''));

            $attrRows = isset($attributes[$oxid]) ? $attributes[$oxid] : [];

            $out[] = [
                'oxid'               => $oxid,
                'parentOxid'         => $parent,
                'productNumber'      => $productNumber,
                'name'               => $this->str($r, 'OXTITLE') !== null ? $this->str($r, 'OXTITLE') : $productNumber,
                'shortDescription'   => $this->str($r, 'OXSHORTDESC'),
                'longDescription'    => isset($longDesc[$oxid]) ? $longDesc[$oxid] : null,
                'ean'                => $this->str($r, 'OXEAN'),
                'manufacturerNumber' => $this->str($r, 'OXMPN'),
                'manufacturerOxid'   => $manufacturerOxid !== '' ? $manufacturerOxid : null,
                'stock'              => (int) (isset($r['OXSTOCK']) ? $r['OXSTOCK'] : 0),
                'priceGross'         => $gross,
                'priceNet'           => $net,
                'vat'                => $vat,
                'weight'             => $this->floatOrNull($r, 'OXWEIGHT'),
                'width'              => $this->floatOrNull($r, 'OXWIDTH'),
                'height'             => $this->floatOrNull($r, 'OXHEIGHT'),
                'length'             => $this->floatOrNull($r, 'OXLENGTH'),
                'active'             => (int) (isset($r['OXACTIVE']) ? $r['OXACTIVE'] : 1) === 1,
                'categoryOxids'      => isset($categoryMap[$oxid]) ? $categoryMap[$oxid] : [],
                'images'             => $this->resolveImages($productNumber, $r),
                'properties'         => $this->resolveProperties($r, $attrRows),
            ];
        }

        return $out;
    }

    /**
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
     * @param array<string,mixed> $row
     * @param array<int,array{attr:string,val:string}> $attrRows
     * @return array<int,array{group:string,option:string}>
     */
    private function resolveProperties(array $row, array $attrRows): array
    {
        $result = [];

        foreach ($this->mapping->properties as $pm) {
            $rawValues = [];

            if ($pm->source === 'field' && $pm->oxidField !== null) {
                $rawValues[] = (string) (isset($row[$pm->oxidField]) ? $row[$pm->oxidField] : '');
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
            $file = trim((string) (isset($row['OXPIC' . $i]) ? $row['OXPIC' . $i] : ''));
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
     * @return array<string,array<int,string>>
     */
    private function categoryAssignments(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT OXOBJECTID, OXCATNID FROM oxobject2category ORDER BY OXPOS ASC');

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['OXOBJECTID']][] = (string) $r['OXCATNID'];
        }

        return $map;
    }

    /**
     * @return array<string,string>
     */
    private function longDescriptions(): array
    {
        $map = [];
        foreach ($this->db->fetchAllAssociative('SELECT OXID, OXLONGDESC FROM oxartextends') as $r) {
            $value = trim((string) (isset($r['OXLONGDESC']) ? $r['OXLONGDESC'] : ''));
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
            $attr = trim((string) (isset($r['ATTR']) ? $r['ATTR'] : ''));
            $val  = trim((string) (isset($r['VAL']) ? $r['VAL'] : ''));
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
        $v = isset($row[$key]) ? $row[$key] : null;
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
        $v = isset($row[$key]) ? $row[$key] : null;
        if ($v === null || $v === '' || (float) $v === 0.0) {
            return null;
        }

        return (float) $v;
    }
}
