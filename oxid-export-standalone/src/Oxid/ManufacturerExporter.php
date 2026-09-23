<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Mojo\OxidExport\Db;

/**
 * Reads `oxmanufacturers` (PHP 7.2).
 */
final class ManufacturerExporter
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
        try {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM oxmanufacturers ORDER BY OXTITLE ASC');
        } catch (\Throwable $e) {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM oxmanufacturers');
        }

        $out = [];
        foreach ($rows as $r) {
            $title = trim((string) (isset($r['OXTITLE']) ? $r['OXTITLE'] : ''));
            if ($title === '') {
                continue;
            }

            $out[] = [
                'oxid'   => (string) $r['OXID'],
                'title'  => $title,
                'active' => (int) (isset($r['OXACTIVE']) ? $r['OXACTIVE'] : 1) === 1,
            ];
        }

        return $out;
    }
}
