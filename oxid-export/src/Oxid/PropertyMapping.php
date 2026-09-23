<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

/**
 * One property-group mapping rule from the YAML config.
 *
 * source = 'attribute' → values come from oxobject2attribute joined to
 *                         oxattribute by title (oxidAttribute).
 * source = 'field'     → value comes from a column on oxarticles (oxidField).
 *
 * $optionMap (optional) acts as a case-insensitive whitelist + rename:
 * lowercased raw OXID value => clean Shopware label. When present, values not
 * listed are skipped. When null, raw values pass through unchanged.
 */
final class PropertyMapping
{
    /**
     * @param array<string,string>|null $optionMap
     */
    public function __construct(
        public readonly string $group,
        public readonly string $source,
        public readonly ?string $oxidAttribute,
        public readonly ?string $oxidField,
        public readonly ?array $optionMap,
    ) {
    }

    public function label(string $rawValue): ?string
    {
        $raw = trim($rawValue);
        if ($raw === '') {
            return null;
        }

        if ($this->optionMap === null) {
            return $raw;
        }

        return $this->optionMap[mb_strtolower($raw)] ?? null;
    }
}
