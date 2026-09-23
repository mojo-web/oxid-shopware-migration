<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Doctrine\DBAL\Connection;

/**
 * Reads `oxmanufacturers` (the OXID "Hersteller" table referenced by
 * oxarticles.OXMANUFACTURERID).
 */
final class ManufacturerExporter
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
            $rows = $this->db->fetchAllAssociative('SELECT * FROM oxmanufacturers ORDER BY OXTITLE ASC');
        } catch (\Throwable $e) {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM oxmanufacturers');
        }

        $out = [];
        foreach ($rows as $r) {
            $title = trim((string) ($r['OXTITLE'] ?? ''));
            if ($title === '') {
                continue;
            }

            $out[] = [
                'oxid'   => (string) $r['OXID'],
                'title'  => $title,
                'active' => (int) ($r['OXACTIVE'] ?? 1) === 1,
            ];
        }

        return $out;
    }
}
