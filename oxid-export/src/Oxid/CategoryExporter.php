<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Doctrine\DBAL\Connection;

/**
 * Reads `oxcategories` and emits a flat, tree-ordered list.
 * Rows are returned ordered by OXLEFT (nested set), so parents always
 * precede their children — which the Shopware importer relies on.
 */
final class CategoryExporter
{
    public function __construct(private readonly Connection $db)
    {
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
            $parent = (string) ($r['OXPARENTID'] ?? '');
            if ($parent === 'oxrootid' || $parent === '') {
                $parent = null;
            }

            $out[] = [
                'oxid'            => (string) $r['OXID'],
                'parentOxid'      => $parent,
                'title'           => $this->str($r, 'OXTITLE') ?? ('Category ' . $r['OXID']),
                'description'     => $this->str($r, 'OXDESC'),
                'longDescription' => $this->str($r, 'OXLONGDESC'),
                'active'          => (int) ($r['OXACTIVE'] ?? 1) === 1,
                'hidden'          => (int) ($r['OXHIDDEN'] ?? 0) === 1,
                'sort'            => (int) ($r['OXSORT'] ?? 0),
            ];
        }

        return $out;
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
