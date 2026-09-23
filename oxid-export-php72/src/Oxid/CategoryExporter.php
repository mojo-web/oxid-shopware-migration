<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Doctrine\DBAL\Connection;

/**
 * Reads `oxcategories` ordered by OXLEFT so parents precede children (PHP 7.2).
 */
final class CategoryExporter
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function export(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM oxcategories ORDER BY OXLEFT ASC');
        } catch (\Throwable $e) {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM oxcategories');
        }

        $out = [];
        foreach ($rows as $r) {
            $parent = (string) (isset($r['OXPARENTID']) ? $r['OXPARENTID'] : '');
            if ($parent === 'oxrootid' || $parent === '') {
                $parent = null;
            }

            $out[] = [
                'oxid'            => (string) $r['OXID'],
                'parentOxid'      => $parent,
                'title'           => $this->str($r, 'OXTITLE') !== null ? $this->str($r, 'OXTITLE') : ('Category ' . $r['OXID']),
                'description'     => $this->str($r, 'OXDESC'),
                'longDescription' => $this->str($r, 'OXLONGDESC'),
                'active'          => (int) (isset($r['OXACTIVE']) ? $r['OXACTIVE'] : 1) === 1,
                'hidden'          => (int) (isset($r['OXHIDDEN']) ? $r['OXHIDDEN'] : 0) === 1,
                'sort'            => (int) (isset($r['OXSORT']) ? $r['OXSORT'] : 0),
            ];
        }

        return $out;
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
