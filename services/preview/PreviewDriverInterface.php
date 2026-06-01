<?php

namespace thyseus\files\services\preview;

/**
 * Generates a raster preview image from a source document (e.g. PDF page 1).
 */
interface PreviewDriverInterface
{
    /**
     * @param string $sourcePath Absolute path to source file
     * @param string $outputPath Absolute path for PNG/JPEG output
     * @param int|null $maxWidth Max dimension for scaling
     * @param int|null $maxHeight Max dimension for scaling
     * @return bool True on success
     */
    public function generateFirstPagePreview(string $sourcePath, string $outputPath, ?int $maxWidth, ?int $maxHeight): bool;

    /**
     * Human-readable driver name for logging.
     */
    public function getName(): string;
}
