<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Command;

use Mojo\OxidExport\Oxid\CategoryExporter;
use Mojo\OxidExport\Oxid\CustomerExporter;
use Mojo\OxidExport\Oxid\ExportMapping;
use Mojo\OxidExport\Oxid\ManufacturerExporter;
use Mojo\OxidExport\Oxid\OrderExporter;
use Mojo\OxidExport\Oxid\OxidConnectionFactory;
use Mojo\OxidExport\Oxid\ProductExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'oxid:export',
    description: 'Export OXID 6.3 categories, products, manufacturers & properties to JSON'
)]
final class ExportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Output JSON file path', 'oxid-export.json')
            ->addOption('mapping', 'm', InputOption::VALUE_REQUIRED, 'Path to mapping.yaml (images, manufacturers, properties)')
            ->addOption('oxid-config', null, InputOption::VALUE_REQUIRED, 'Path to OXID source/config.inc.php (reads DB credentials automatically)')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Database host (or env OXID_DB_HOST)')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'Database port', '3306')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Database name (or env OXID_DB_NAME)')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Database user (or env OXID_DB_USER)')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'Database password (or env OXID_DB_PASS)')
            ->addOption('default-vat', null, InputOption::VALUE_REQUIRED, 'Override fallback VAT % when OXVAT is null')
            ->addOption('skip-orders', null, InputOption::VALUE_NONE, 'Do not export orders')
            ->addOption('skip-customers', null, InputOption::VALUE_NONE, 'Do not export customers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --- mapping ---
        $mappingPath = $input->getOption('mapping');
        if (is_string($mappingPath) && $mappingPath !== '') {
            $mapping = ExportMapping::fromYaml($mappingPath);
            $io->note("Loaded mapping from {$mappingPath}");
        } else {
            $mapping = ExportMapping::defaults();
            $io->note('No --mapping given: images & custom properties disabled, manufacturers on.');
        }

        if (($vatOverride = $input->getOption('default-vat')) !== null) {
            $mapping = $mapping->withDefaultVat((float) $vatOverride);
        }

        // --- db credentials ---
        $configPath = $input->getOption('oxid-config');
        if (is_string($configPath) && $configPath !== '') {
            $params = OxidConnectionFactory::fromOxidConfig($configPath);
            $io->note("Loaded DB credentials from {$configPath}");
        } else {
            $params = [
                'host'     => $input->getOption('db-host') ?: (getenv('OXID_DB_HOST') ?: '127.0.0.1'),
                'port'     => $input->getOption('db-port') ?: '3306',
                'dbname'   => $input->getOption('db-name') ?: (getenv('OXID_DB_NAME') ?: ''),
                'user'     => $input->getOption('db-user') ?: (getenv('OXID_DB_USER') ?: ''),
                'password' => $input->getOption('db-pass') ?: (getenv('OXID_DB_PASS') ?: ''),
            ];
        }

        if (($params['dbname'] ?? '') === '') {
            $io->error('No database name given. Use --oxid-config or --db-name (or set OXID_DB_NAME).');

            return Command::FAILURE;
        }

        $io->title('OXID 6.3 → JSON export');
        $io->writeln(sprintf('Database: <info>%s</info> @ %s', $params['dbname'], $params['host']));

        try {
            $db = OxidConnectionFactory::create($params);
            $db->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            $io->error('Database connection failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // --- export ---
        $categories = (new CategoryExporter($db))->export();
        $io->writeln(sprintf(' • Categories:   <info>%d</info>', count($categories)));

        $manufacturers = $mapping->manufacturersEnabled ? (new ManufacturerExporter($db))->export() : [];
        $io->writeln(sprintf(' • Manufacturers:<info>%d</info>', count($manufacturers)));

        $productExporter = new ProductExporter($db, $mapping);
        $products       = $productExporter->export();
        $propertyGroups = $productExporter->propertyGroups();

        $imageCount = array_sum(array_map(static fn (array $p): int => count($p['images']), $products));
        $io->writeln(sprintf(' • Products:     <info>%d</info>', count($products)));
        $io->writeln(sprintf(' • Property grps:<info>%d</info>', count($propertyGroups)));
        $io->writeln(sprintf(' • Image refs:   <info>%d</info> (copied: %d)', $imageCount, $productExporter->copiedImageCount()));

        // --- image diagnostics: explain WHY the count is 0 instead of failing silently ---
        $collector = $productExporter->imageCollector();
        if ($imageCount === 0) {
            $io->writeln('');
            $io->writeln('   [image diagnosis]');
            if ($mapping->imagesBaseUrl === null && ($mapping->imagesSourceDir === null || $mapping->imagesCopyTo === null)) {
                $io->writeln('   -> Image export is DISABLED: neither images.base_url nor');
                $io->writeln('      images.source_dir + images.copy_to are set in the mapping.');
            } elseif ($collector->attempts() === 0) {
                $io->writeln('   -> No OXPIC values found on any article. Check with:');
                $io->writeln("      SELECT OXARTNUM, OXPIC1, OXPIC2 FROM oxarticles WHERE OXPIC1 <> '' LIMIT 5;");
                $io->writeln('      If this returns rows, extend images.pic_fields in the mapping.');
            } elseif ($mapping->imagesSourceDir !== null && $collector->missingFiles() === $collector->attempts()) {
                $io->writeln(sprintf('   -> %d OXPIC values found, but NO file existed under images.source_dir', $collector->attempts()));
                $io->writeln('      (' . $mapping->imagesSourceDir . ') and no base_url is set as fallback.');
                foreach ($collector->missingSamples() as $sample) {
                    $io->writeln('      missing: ' . $sample);
                }
                $io->writeln('      OXID masters usually live at <shop>/out/pictures/master/product');
            }
        } elseif ($collector->missingFiles() > 0) {
            $io->writeln(sprintf('   [note] %d image file(s) were not found under images.source_dir:', $collector->missingFiles()));
            foreach ($collector->missingSamples() as $sample) {
                $io->writeln('      missing: ' . $sample);
            }
        }
        if ($collector->fullUrls() > 0) {
            $io->writeln(sprintf('   [note] %d OXPIC value(s) were complete URLs and were exported as-is.', $collector->fullUrls()));
        }

        $orders = (!$input->getOption('skip-orders') && $mapping->ordersEnabled)
            ? (new OrderExporter($db))->export()
            : [];
        $io->writeln(sprintf(' • Orders:       <info>%d</info>', count($orders)));

        $customers = (!$input->getOption('skip-customers') && $mapping->customersEnabled)
            ? (new CustomerExporter($db))->export()
            : [];
        $addressCount = array_sum(array_map(static fn (array $c): int => 1 + count($c['addresses']), $customers));
        $io->writeln(sprintf(' • Customers:    <info>%d</info> (addresses: %d)', count($customers), $addressCount));

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

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $outFile = (string) $input->getOption('out');
        if (file_put_contents($outFile, $json) === false) {
            $io->error("Could not write to {$outFile}");

            return Command::FAILURE;
        }

        $io->success(sprintf('Wrote %s (%s)', $outFile, $this->humanBytes(strlen($json))));

        return Command::SUCCESS;
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
