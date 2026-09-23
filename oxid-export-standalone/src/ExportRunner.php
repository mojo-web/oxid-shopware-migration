<?php

declare(strict_types=1);

namespace Mojo\OxidExport;

use Mojo\OxidExport\Oxid\CategoryExporter;
use Mojo\OxidExport\Oxid\CustomerExporter;
use Mojo\OxidExport\Oxid\ExportMapping;
use Mojo\OxidExport\Oxid\ManufacturerExporter;
use Mojo\OxidExport\Oxid\OrderExporter;
use Mojo\OxidExport\Oxid\ProductExporter;

/**
 * Dependency-free orchestrator (no Symfony Console). Mirrors the Symfony
 * command but prints plain lines and uses the PDO-based Db wrapper.
 */
final class ExportRunner
{
    /**
     * @param array<string,mixed> $opts
     */
    public function run(array $opts): int
    {
        // --- mapping ---
        $mappingPath = isset($opts['mapping']) ? (string) $opts['mapping'] : '';
        if ($mappingPath !== '') {
            $mapping = ExportMapping::fromYaml($mappingPath);
            $this->line("Loaded mapping from {$mappingPath}");
        } else {
            $mapping = ExportMapping::defaults();
            $this->line('No --mapping given: images & custom properties disabled, manufacturers/orders/customers on.');
        }

        if (isset($opts['default-vat']) && $opts['default-vat'] !== '') {
            $mapping = $mapping->withDefaultVat((float) $opts['default-vat']);
        }

        // --- db credentials ---
        $configPath = isset($opts['oxid-config']) ? (string) $opts['oxid-config'] : '';
        if ($configPath !== '') {
            $params = Db::fromOxidConfig($configPath);
            $this->line("Loaded DB credentials from {$configPath}");
        } else {
            $params = [
                'host'     => $this->opt($opts, 'db-host', getenv('OXID_DB_HOST') ?: '127.0.0.1'),
                'port'     => $this->opt($opts, 'db-port', '3306'),
                'dbname'   => $this->opt($opts, 'db-name', getenv('OXID_DB_NAME') ?: ''),
                'user'     => $this->opt($opts, 'db-user', getenv('OXID_DB_USER') ?: ''),
                'password' => $this->opt($opts, 'db-pass', getenv('OXID_DB_PASS') ?: ''),
            ];
        }

        if ((string) $params['dbname'] === '') {
            fwrite(STDERR, "ERROR: No database name. Use --oxid-config or --db-name (or set OXID_DB_NAME).\n");

            return 1;
        }

        $this->line('');
        $this->line('OXID 6.3 -> JSON export (standalone, no dependencies)');
        $this->line('=====================================================');
        $this->line(sprintf('Database: %s @ %s', $params['dbname'], $params['host']));

        try {
            $db = Db::fromParams($params);
            $db->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            fwrite(STDERR, 'ERROR: Database connection failed: ' . $e->getMessage() . "\n");

            return 1;
        }

        // --- export ---
        $categories = (new CategoryExporter($db))->export();
        $this->line(sprintf(' . Categories:   %d', count($categories)));

        $manufacturers = $mapping->manufacturersEnabled ? (new ManufacturerExporter($db))->export() : [];
        $this->line(sprintf(' . Manufacturers:%d', count($manufacturers)));

        $productExporter = new ProductExporter($db, $mapping);
        $products       = $productExporter->export();
        $propertyGroups = $productExporter->propertyGroups();

        $imageCount = 0;
        foreach ($products as $p) {
            $imageCount += count($p['images']);
        }
        $this->line(sprintf(' . Products:     %d', count($products)));
        $this->line(sprintf(' . Property grps:%d', count($propertyGroups)));
        $this->line(sprintf(' . Image refs:   %d (copied: %d)', $imageCount, $productExporter->copiedImageCount()));

        // --- image diagnostics: explain WHY the count is 0 instead of failing silently ---
        $collector = $productExporter->imageCollector();
        if ($imageCount === 0) {
            $this->line('');
            $this->line('   [image diagnosis]');
            if ($mapping->imagesBaseUrl === null && ($mapping->imagesSourceDir === null || $mapping->imagesCopyTo === null)) {
                $this->line('   -> Image export is DISABLED: neither images.base_url nor');
                $this->line('      images.source_dir + images.copy_to are set in the mapping');
                $this->line(($mappingPath !== '' ? "      ({$mappingPath})" : '      (no --mapping was given at all)') . '.');
            } elseif ($collector->attempts() === 0) {
                $this->line('   -> No OXPIC values found on any article: the configured pic_fields');
                $this->line('      are empty in oxarticles. Check with:');
                $this->line("      SELECT OXARTNUM, OXPIC1, OXPIC2 FROM oxarticles WHERE OXPIC1 <> '' LIMIT 5;");
                $this->line('      If this returns rows, extend images.pic_fields in the mapping.');
            } elseif ($mapping->imagesSourceDir !== null && $collector->missingFiles() === $collector->attempts()) {
                $this->line(sprintf('   -> %d OXPIC values found, but NO file existed under images.source_dir', $collector->attempts()));
                $this->line('      (' . $mapping->imagesSourceDir . ') — and no base_url is set as fallback.');
                foreach ($collector->missingSamples() as $sample) {
                    $this->line('      missing: ' . $sample);
                }
                $this->line('      OXID masters usually live at <shop>/out/pictures/master/product');
            }
        } elseif ($collector->missingFiles() > 0) {
            $this->line(sprintf('   [note] %d image file(s) were not found under images.source_dir:', $collector->missingFiles()));
            foreach ($collector->missingSamples() as $sample) {
                $this->line('      missing: ' . $sample);
            }
        }
        if ($collector->fullUrls() > 0) {
            $this->line(sprintf('   [note] %d OXPIC value(s) were complete URLs and were exported as-is.', $collector->fullUrls()));
        }

        $orders = (empty($opts['skip-orders']) && $mapping->ordersEnabled)
            ? (new OrderExporter($db))->export()
            : [];
        $this->line(sprintf(' . Orders:       %d', count($orders)));

        $customers = (empty($opts['skip-customers']) && $mapping->customersEnabled)
            ? (new CustomerExporter($db))->export()
            : [];
        $addressCount = 0;
        foreach ($customers as $c) {
            $addressCount += 1 + count($c['addresses']);
        }
        $this->line(sprintf(' . Customers:    %d (addresses: %d)', count($customers), $addressCount));

        $payload = [
            'meta' => [
                'source'     => 'oxid',
                'version'    => '6.3',
                'exportedAt' => date('c'),
                'counts'     => [
                    'categories'     => count($categories),
                    'manufacturers'  => count($manufacturers),
                    'products'       => count($products),
                    'propertyGroups' => count($propertyGroups),
                    'images'         => $imageCount,
                    'orders'         => count($orders),
                    'customers'      => count($customers),
                ],
            ],
            'categories'     => $categories,
            'manufacturers'  => $manufacturers,
            'propertyGroups' => $propertyGroups,
            'products'       => $products,
            'orders'         => $orders,
            'customers'      => $customers,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            fwrite(STDERR, 'ERROR: Failed to encode JSON: ' . json_last_error_msg() . "\n");

            return 1;
        }

        $outFile = isset($opts['out']) && $opts['out'] !== '' ? (string) $opts['out'] : 'oxid-export.json';
        if (file_put_contents($outFile, $json) === false) {
            fwrite(STDERR, "ERROR: Could not write to {$outFile}\n");

            return 1;
        }

        $this->line('');
        $this->line(sprintf('[OK] Wrote %s (%s)', $outFile, $this->humanBytes(strlen($json))));

        return 0;
    }

    /**
     * @param array<string,mixed> $opts
     * @param mixed               $default
     * @return mixed
     */
    private function opt(array $opts, string $key, $default)
    {
        return (isset($opts[$key]) && $opts[$key] !== '') ? $opts[$key] : $default;
    }

    private function line(string $text): void
    {
        echo $text . "\n";
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $val = (float) $bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $val, $units[$i]);
    }
}
