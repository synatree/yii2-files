<?php

namespace thyseus\files\services\preview;

use Yii;

/**
 * Renders PDF page 1 via PHP Imagick (requires Ghostscript delegate).
 */
class ImagickPdfDriver implements PreviewDriverInterface
{
    public static function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    public function getName(): string
    {
        return 'imagick';
    }

    public function generateFirstPagePreview(string $sourcePath, string $outputPath, ?int $maxWidth, ?int $maxHeight): bool
    {
        if (!self::isAvailable() || !is_readable($sourcePath)) {
            return false;
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) && !@mkdir($outputDir, 0755, true)) {
            return false;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(144, 144);
            $imagick->readImage($sourcePath . '[0]');
            $imagick->setImageBackgroundColor('white');
            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

            $maxDim = max(64, (int) ($maxWidth ?? $maxHeight ?? 256));
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            if ($width > 0 && $height > 0) {
                $scale = min($maxDim / $width, $maxDim / $height, 1.0);
                if ($scale < 1.0) {
                    $imagick->resizeImage(
                        max(1, (int) round($width * $scale)),
                        max(1, (int) round($height * $scale)),
                        \Imagick::FILTER_LANCZOS,
                        1
                    );
                }
            }

            $imagick->setImageFormat('png');
            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            return is_file($outputPath);
        } catch (\Throwable $e) {
            Yii::warning('ImagickPdfDriver failed: ' . $e->getMessage(), __METHOD__);

            return false;
        }
    }
}
