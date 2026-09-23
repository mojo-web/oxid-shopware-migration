<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

/**
 * One property-group mapping rule from the YAML config (PHP 7.2 compatible).
 */
final class PropertyMapping
{
    /** @var string */
    public $group;
    /** @var string */
    public $source;
    /** @var string|null */
    public $oxidAttribute;
    /** @var string|null */
    public $oxidField;
    /** @var array<string,string>|null */
    private $optionMap;

    /**
     * @param array<string,string>|null $optionMap
     */
    public function __construct($group, $source, $oxidAttribute, $oxidField, $optionMap)
    {
        $this->group = $group;
        $this->source = $source;
        $this->oxidAttribute = $oxidAttribute;
        $this->oxidField = $oxidField;
        $this->optionMap = $optionMap;
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
        $key = mb_strtolower($raw);

        return isset($this->optionMap[$key]) ? $this->optionMap[$key] : null;
    }
}
