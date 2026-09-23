<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Symfony\Component\Yaml\Yaml;

/**
 * Parsed representation of the export mapping.yaml.
 */
final class ExportMapping
{
    /** @var 'oxid'|'standard'|'reduced' */
    public string $vatMode = 'oxid';
    public float $vatStandardRate = 19.0;
    public float $vatReducedRate = 7.0;
    /** @var array<string,string> rounded raw rate => oxid|standard|reduced */
    public array $vatMap = [];

    /**
     * @param int[]             $picFields
     * @param PropertyMapping[] $properties
     */
    public function __construct(
        public readonly float $defaultVat,
        public readonly ?string $imagesBaseUrl,
        public readonly ?string $imagesSourceDir,
        public readonly ?string $imagesCopyTo,
        public readonly array $picFields,
        public readonly bool $manufacturersEnabled,
        public readonly bool $ordersEnabled,
        public readonly bool $customersEnabled,
        public readonly array $properties,
    ) {
    }

    public static function defaults(): self
    {
        return new self(19.0, null, null, null, range(1, 12), true, true, true, []);
    }

    public function withDefaultVat(float $vat): self
    {
        return new self(
            $vat,
            $this->imagesBaseUrl,
            $this->imagesSourceDir,
            $this->imagesCopyTo,
            $this->picFields,
            $this->manufacturersEnabled,
            $this->ordersEnabled,
            $this->customersEnabled,
            $this->properties,
        );
    }

    public static function fromYaml(string $path): self
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Cannot read mapping file: {$path}");
        }

        $cfg = Yaml::parseFile($path) ?: [];

        $defaultVat = (float) ($cfg['default_vat'] ?? 19.0);

        $images  = is_array($cfg['images'] ?? null) ? $cfg['images'] : [];
        $baseUrl = self::nonEmpty($images['base_url'] ?? null);
        $baseUrl = $baseUrl !== null ? rtrim($baseUrl, '/') : null;

        $sourceDir = self::nonEmpty($images['source_dir'] ?? null);
        $sourceDir = $sourceDir !== null ? rtrim($sourceDir, '/') : null;

        $copyTo = self::nonEmpty($images['copy_to'] ?? null);
        $copyTo = $copyTo !== null ? rtrim($copyTo, '/') : null;

        $picFields = [];
        foreach (($images['pic_fields'] ?? range(1, 12)) as $n) {
            $n = (int) $n;
            if ($n >= 1 && $n <= 12) {
                $picFields[] = $n;
            }
        }
        if ($picFields === []) {
            $picFields = range(1, 12);
        }

        $manufacturers = (bool) ($cfg['manufacturers']['enabled'] ?? true);
        $orders        = (bool) ($cfg['orders']['enabled'] ?? true);
        $customers     = (bool) ($cfg['customers']['enabled'] ?? true);

        $properties = [];
        foreach (($cfg['properties'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $group = trim((string) ($p['group'] ?? ''));
            if ($group === '') {
                continue;
            }

            $source = ($p['source'] ?? 'attribute') === 'field' ? 'field' : 'attribute';

            $optionMap = null;
            if (isset($p['options']) && is_array($p['options'])) {
                $optionMap = [];
                foreach ($p['options'] as $raw => $label) {
                    $optionMap[mb_strtolower(trim((string) $raw))] = (string) $label;
                }
            }

            $properties[] = new PropertyMapping(
                $group,
                $source,
                isset($p['oxid_attribute']) ? (string) $p['oxid_attribute'] : null,
                isset($p['oxid_field']) ? (string) $p['oxid_field'] : null,
                $optionMap,
            );
        }

        $self = new self($defaultVat, $baseUrl, $sourceDir, $copyTo, $picFields, $manufacturers, $orders, $customers, $properties);

        $vat = is_array($cfg['vat'] ?? null) ? $cfg['vat'] : [];
        $mode = strtolower((string) ($vat['mode'] ?? 'oxid'));
        $self->vatMode = in_array($mode, ['oxid', 'standard', 'reduced'], true) ? $mode : 'oxid';
        $self->vatStandardRate = (float) ($vat['standard_rate'] ?? 19.0);
        $self->vatReducedRate = (float) ($vat['reduced_rate'] ?? 7.0);
        foreach ((array) ($vat['map'] ?? []) as $raw => $target) {
            $target = strtolower(trim((string) $target));
            if (in_array($target, ['oxid', 'standard', 'reduced'], true)) {
                $self->vatMap[(string) (int) round((float) $raw)] = $target;
            }
        }

        return $self;
    }

    /**
     * Resolves the effective VAT rate for one article: base = OXVAT (or
     * default_vat), vat.map can redirect a specific raw rate, vat.mode applies
     * globally.
     */
    public function resolveVat(mixed $oxidVat): float
    {
        $base = ($oxidVat === null || $oxidVat === '') ? $this->defaultVat : (float) $oxidVat;

        $mode = $this->vatMap[(string) (int) round($base)] ?? $this->vatMode;

        return match ($mode) {
            'standard' => $this->vatStandardRate,
            'reduced'  => $this->vatReducedRate,
            default    => $base,
        };
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
