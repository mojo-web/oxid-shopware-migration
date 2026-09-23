<?php

declare(strict_types=1);

namespace Mojo\OxidExport\Oxid;

/**
 * Turns OXID OXPICn filenames into image descriptors (URL and/or copied file)
 * for the export JSON (PHP 7.2).
 */
final class ImageCollector
{
    /** @var string|null */
    private $baseUrl;
    /** @var string|null */
    private $sourceDir;
    /** @var string|null */
    private $copyTo;
    /** @var bool */
    private $copyDirReady = false;
    /** @var int */
    private $copied = 0;
    /** @var int */
    private $attempts = 0;
    /** @var int */
    private $missingFiles = 0;
    /** @var array<int,string> */
    private $missingSamples = array();
    /** @var int */
    private $fullUrls = 0;

    public function __construct($baseUrl, $sourceDir, $copyTo)
    {
        $this->baseUrl = $baseUrl;
        $this->sourceDir = $sourceDir;
        $this->copyTo = $copyTo;
    }

    public function enabled(): bool
    {
        return $this->baseUrl !== null || ($this->sourceDir !== null && $this->copyTo !== null);
    }

    public function copiedCount(): int
    {
        return $this->copied;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function collect(string $productNumber, int $picIndex, string $fileName, int $position)
    {
        $this->attempts++;
        $entry = ['position' => $position, 'cover' => $position === 0];

        // Some OXID installations store complete URLs in OXPIC fields.
        if (preg_match('#^https?://#i', $fileName) === 1) {
            $entry['url'] = $fileName;
            $this->fullUrls++;

            return $entry;
        }
        $usable = false;

        if ($this->baseUrl !== null) {
            $entry['url'] = sprintf('%s/%d/%s', $this->baseUrl, $picIndex, rawurlencode($fileName));
            $usable = true;
        }

        if ($this->sourceDir !== null && $this->copyTo !== null) {
            $src = sprintf('%s/%d/%s', $this->sourceDir, $picIndex, $fileName);
            if (is_file($src)) {
                $this->ensureCopyDir();
                $temp = $this->tempName($productNumber, $picIndex, $fileName);
                if (@copy($src, $this->copyTo . '/' . $temp)) {
                    $entry['file'] = $temp;
                    $this->copied++;
                    $usable = true;
                }
            } else {
                $this->missingFiles++;
                if (count($this->missingSamples) < 5) {
                    $this->missingSamples[] = $src;
                }
            }
        }

        return $usable ? $entry : null;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function missingFiles(): int
    {
        return $this->missingFiles;
    }

    /**
     * @return array<int,string>
     */
    public function missingSamples(): array
    {
        return $this->missingSamples;
    }

    public function fullUrls(): int
    {
        return $this->fullUrls;
    }

    private function tempName(string $productNumber, int $picIndex, string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'jpg';
        }
        $hash = substr(md5($productNumber . '|' . $picIndex . '|' . $fileName), 0, 8);

        return sprintf('%s_%d_%s.%s', $this->slug($productNumber), $picIndex, $hash, $ext);
    }

    private function slug(string $value): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
        $value = trim($value, '_');

        return $value !== '' ? $value : 'img';
    }

    private function ensureCopyDir(): void
    {
        if ($this->copyDirReady) {
            return;
        }
        if ($this->copyTo !== null && !is_dir($this->copyTo)) {
            @mkdir($this->copyTo, 0775, true);
        }
        $this->copyDirReady = true;
    }
}
