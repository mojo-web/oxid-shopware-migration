<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

use Symfony\Component\Yaml\Yaml;

/**
 * Parsed representation of mapping.yaml (PHP 7.2 compatible).
 */
final class ExportMapping
{
    /** @var float */
    public $defaultVat;
    /** @var string oxid|standard|reduced */
    public $vatMode = 'oxid';
    /** @var float */
    public $vatStandardRate = 19.0;
    /** @var float */
    public $vatReducedRate = 7.0;
    /** @var array<string,string> rounded raw rate => oxid|standard|reduced */
    public $vatMap = array();
    /** @var string|null */
    public $imagesBaseUrl;
    /** @var string|null */
    public $imagesSourceDir;
    /** @var string|null */
    public $imagesCopyTo;
    /** @var int[] */
    public $picFields;
    /** @var bool */
    public $manufacturersEnabled;
    /** @var bool */
    public $ordersEnabled;
    /** @var bool */
    public $customersEnabled;
    /** @var PropertyMapping[] */
    public $properties;

    /**
     * @param int[]             $picFields
     * @param PropertyMapping[] $properties
     */
    public function __construct(
        $defaultVat,
        $imagesBaseUrl,
        $imagesSourceDir,
        $imagesCopyTo,
        array $picFields,
        $manufacturersEnabled,
        $ordersEnabled,
        $customersEnabled,
        array $properties
    ) {
        $this->defaultVat = $defaultVat;
        $this->imagesBaseUrl = $imagesBaseUrl;
        $this->imagesSourceDir = $imagesSourceDir;
        $this->imagesCopyTo = $imagesCopyTo;
        $this->picFields = $picFields;
        $this->manufacturersEnabled = $manufacturersEnabled;
        $this->ordersEnabled = $ordersEnabled;
        $this->customersEnabled = $customersEnabled;
        $this->properties = $properties;
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
            $this->properties
        );
    }

    public static function fromYaml(string $path): self
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Cannot read mapping file: {$path}");
        }

        $cfg = Yaml::parseFile($path);
        if (!is_array($cfg)) {
            $cfg = [];
        }

        $defaultVat = (float) (isset($cfg['default_vat']) ? $cfg['default_vat'] : 19.0);

        $images = (isset($cfg['images']) && is_array($cfg['images'])) ? $cfg['images'] : [];

        $baseUrl = self::nonEmpty(isset($images['base_url']) ? $images['base_url'] : null);
        $baseUrl = $baseUrl !== null ? rtrim($baseUrl, '/') : null;

        $sourceDir = self::nonEmpty(isset($images['source_dir']) ? $images['source_dir'] : null);
        $sourceDir = $sourceDir !== null ? rtrim($sourceDir, '/') : null;

        $copyTo = self::nonEmpty(isset($images['copy_to']) ? $images['copy_to'] : null);
        $copyTo = $copyTo !== null ? rtrim($copyTo, '/') : null;

        $picFields = [];
        $rawPics = isset($images['pic_fields']) ? $images['pic_fields'] : range(1, 12);
        foreach ($rawPics as $n) {
            $n = (int) $n;
            if ($n >= 1 && $n <= 12) {
                $picFields[] = $n;
            }
        }
        if ($picFields === []) {
            $picFields = range(1, 12);
        }

        $manufacturers = (bool) (isset($cfg['manufacturers']['enabled']) ? $cfg['manufacturers']['enabled'] : true);
        $orders        = (bool) (isset($cfg['orders']['enabled']) ? $cfg['orders']['enabled'] : true);
        $customers     = (bool) (isset($cfg['customers']['enabled']) ? $cfg['customers']['enabled'] : true);

        $vat = (isset($cfg['vat']) && is_array($cfg['vat'])) ? $cfg['vat'] : [];
        $vatMode = isset($vat['mode']) ? strtolower((string) $vat['mode']) : 'oxid';
        if (!in_array($vatMode, ['oxid', 'standard', 'reduced'], true)) {
            $vatMode = 'oxid';
        }
        $vatStandard = (float) (isset($vat['standard_rate']) ? $vat['standard_rate'] : 19.0);
        $vatReduced  = (float) (isset($vat['reduced_rate']) ? $vat['reduced_rate'] : 7.0);
        $vatMap = [];
        if (isset($vat['map']) && is_array($vat['map'])) {
            foreach ($vat['map'] as $raw => $target) {
                $target = strtolower(trim((string) $target));
                if (in_array($target, ['oxid', 'standard', 'reduced'], true)) {
                    $vatMap[(string) (int) round((float) $raw)] = $target;
                }
            }
        }

        $properties = [];
        $rawProps = isset($cfg['properties']) ? $cfg['properties'] : [];
        foreach ($rawProps as $p) {
            if (!is_array($p)) {
                continue;
            }
            $group = trim((string) (isset($p['group']) ? $p['group'] : ''));
            if ($group === '') {
                continue;
            }

            $source = (isset($p['source']) && $p['source'] === 'field') ? 'field' : 'attribute';

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
                $optionMap
            );
        }

        $self = new self($defaultVat, $baseUrl, $sourceDir, $copyTo, $picFields, $manufacturers, $orders, $customers, $properties);
        $self->vatMode = $vatMode;
        $self->vatStandardRate = $vatStandard;
        $self->vatReducedRate = $vatReduced;
        $self->vatMap = $vatMap;

        return $self;
    }

    /**
     * Resolves the effective VAT rate for one article.
     * Base = OXVAT (or default_vat when empty); then vat.map can redirect a
     * specific raw rate, and vat.mode applies globally.
     */
    public function resolveVat($oxidVat): float
    {
        $base = ($oxidVat === null || $oxidVat === '') ? $this->defaultVat : (float) $oxidVat;

        $mode = $this->vatMode;
        $key = (string) (int) round($base);
        if (isset($this->vatMap[$key])) {
            $mode = $this->vatMap[$key];
        }

        if ($mode === 'standard') {
            return $this->vatStandardRate;
        }
        if ($mode === 'reduced') {
            return $this->vatReducedRate;
        }

        return $base;
    }

    private static function nonEmpty($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
