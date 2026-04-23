<?php

namespace thyseus\files\services;



use \Yii;
/**
 * Handles image thumbnailing, trimming and inline data URI generation.
 * Uses VIPS when available, falls back to GD.
 */
class ImageProcessor
{
    /** @var string */
    protected $sourcePath;

    /** @var string|null */
    protected $mimetype;

    public function __construct(string $sourcePath, ?string $mimetype = null)
    {
        $this->sourcePath = $sourcePath;
        $this->mimetype = $mimetype ?? $this->detectMimetype();
    }

    protected function detectMimetype(): ?string
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($this->sourcePath) ?: null;
        }
        return null;
    }

    public function isImage(): bool
    {
        return $this->mimetype && strpos($this->mimetype, 'image') !== false;
    }

    public function isSvg(): bool
    {
        return $this->mimetype && strpos($this->mimetype, 'svg') !== false;
    }

    /**
     * Generate thumbnail and save to file. Returns true on success.
     */
    public function generateToFile(?int $width, ?int $height, string $format, bool $trim, string $outputPath): bool
    {
        $vipsLoaded = extension_loaded('vips') && class_exists('\Jcupitt\Vips\Image');
        $gdLoaded = extension_loaded('gd');

        try {
            if (!$this->isImage() || $this->isSvg()) {
                return false;
            }

            if ($vipsLoaded) {
                try {
                    $image = \Jcupitt\Vips\Image::newFromFile($this->sourcePath);
                    if ($trim) {
                        $image = $this->trimWithVIPS($image);
                    }
                    if ($width !== null && $height !== null) {
                        $args = [$width, ['height' => $height]];
                        $thumb = $image->thumbnail(...$args);
                    } elseif ($width !== null) {
                        $thumb = $image->thumbnail($width);
                    } elseif ($height !== null) {
                        $scale = $height / (float) $image->height;
                        $thumb = $image->resize($scale);
                    } else {
                        $thumb = $image->thumbnail(480);
                    }
                    $thumb->writeToFile($outputPath);
                    if (file_exists($outputPath)) {
                        return true;
                    }
                } catch (\Exception $e) {
                    Yii::error('VIPS thumbnail failed: ' . $e->getMessage());
                    if ($gdLoaded) {
                        return $this->generateWithGD($width, $height, $format, $trim, $outputPath);
                    }
                }
            }
            if ($gdLoaded) {
                return $this->generateWithGD($width, $height, $format, $trim, $outputPath);
            }
            return false;
        } catch (\Exception $e) {
            Yii::error('Thumbnail generation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create inline data URI for the image (optionally resized/trimmed). Returns null on failure.
     */
    public function createDataUri(?int $w, ?int $h, string $format, string $mime, bool $trim): ?string
    {
        if (!file_exists($this->sourcePath) || !is_readable($this->sourcePath)) {
            return null;
        }

        if ($this->isImage()) {
            if ($this->isSvg()) {
                $blob = file_get_contents($this->sourcePath);
                $mime = $this->mimetype ?: 'image/svg+xml';
                return 'data:' . $mime . ';base64,' . base64_encode($blob);
            }

            if (extension_loaded('vips') && class_exists('\Jcupitt\Vips\Image')) {
                try {
                    $image = \Jcupitt\Vips\Image::newFromFile($this->sourcePath);
                    if ($trim) {
                        $image = $this->trimWithVIPS($image);
                    }
                    if ($w !== null && $h !== null) {
                        $args = [$w, ['height' => $h]];
                        $thumb = $image->thumbnail(...$args);
                    } elseif ($w !== null) {
                        $thumb = $image->thumbnail($w);
                    } elseif ($h !== null) {
                        $scale = $h / (float) $image->height;
                        $thumb = $image->resize($scale);
                    } else {
                        $thumb = $image->thumbnail(480);
                    }
                    $blob = $thumb->writeToBuffer($format);
                    return 'data:' . $mime . ';base64,' . base64_encode($blob);
                } catch (\Exception $e) {
                    if (extension_loaded('gd')) {
                        $blob = $this->createThumbnailBlobWithGD($w, $h, $format, $trim);
                        return $blob !== null ? 'data:' . $mime . ';base64,' . base64_encode($blob) : null;
                    }
                    $blob = file_get_contents($this->sourcePath);
                    return 'data:' . $this->mimetype . ';base64,' . base64_encode($blob);
                }
            }
            if (extension_loaded('gd')) {
                try {
                    $blob = $this->createThumbnailBlobWithGD($w, $h, $format, $trim);
                    return $blob !== null ? 'data:' . $mime . ';base64,' . base64_encode($blob) : null;
                } catch (\Exception $e) {
                    Yii::error('GD thumbnail failed: ' . $e->getMessage());
                    return null;
                }
            }
            $blob = file_get_contents($this->sourcePath);
            return 'data:' . $this->mimetype . ';base64,' . base64_encode($blob);
        }

        $blob = file_get_contents($this->sourcePath);
        return 'data:' . $this->mimetype . ';base64,' . base64_encode($blob);
    }

    /**
     * Create thumbnail binary blob with GD. Returns null on failure.
     * When only height is set, width is derived from aspect ratio.
     */
    protected function createThumbnailBlobWithGD(?int $width, ?int $height, string $format, bool $trim): ?string
    {
        return $this->createThumbnailWithGD($width, $height, $format, $trim);
    }

    protected function generateWithGD(?int $width, ?int $height, string $format, bool $trim, string $outputPath): bool
    {
        $blob = $this->createThumbnailWithGD($width, $height, $format, $trim);
        return $blob !== null && file_put_contents($outputPath, $blob) !== false;
    }

    protected function trimWithVIPS($image)
    {
        try {
            $width = $image->width;
            $height = $image->height;

            $sample = $image->crop(0, 0, 1, 1);
            $pixelData = $sample->writeToArray();

            $backgroundR = $pixelData[0][0][0] ?? 0;
            $backgroundG = $pixelData[0][0][1] ?? 0;
            $backgroundB = $pixelData[0][0][2] ?? 0;
            $hasAlpha = isset($pixelData[0][0][3]);
            $backgroundA = $hasAlpha ? ($pixelData[0][0][3] ?? 255) : 255;

            $imageData = $image->writeToArray();
            $channels = count($imageData);

            $minX = $width;
            $minY = $height;
            $maxX = -1;
            $maxY = -1;

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $pixelR = $imageData[0][$y][$x] ?? 0;
                    $pixelG = $imageData[1][$y][$x] ?? 0;
                    $pixelB = $imageData[2][$y][$x] ?? 0;
                    $pixelA = ($channels > 3) ? ($imageData[3][$y][$x] ?? 255) : 255;

                    $shouldInclude = false;
                    if ($hasAlpha && $backgroundA < 128) {
                        if ($pixelA >= 128) {
                            $shouldInclude = true;
                        }
                    } else {
                        if ($pixelR != $backgroundR || $pixelG != $backgroundG || $pixelB != $backgroundB || $pixelA != $backgroundA) {
                            $shouldInclude = true;
                        }
                    }

                    if ($shouldInclude) {
                        if ($x < $minX) $minX = $x;
                        if ($x > $maxX) $maxX = $x;
                        if ($y < $minY) $minY = $y;
                        if ($y > $maxY) $maxY = $y;
                    }
                }
            }

            if ($maxX < $minX || $maxY < $minY) {
                return $image;
            }

            $trimWidth = $maxX - $minX + 1;
            $trimHeight = $maxY - $minY + 1;

            if ($minX == 0 && $minY == 0 && $trimWidth == $width && $trimHeight == $height) {
                return $image;
            }

            return $image->crop($minX, $minY, $trimWidth, $trimHeight);
        } catch (\Exception $e) {
            Yii::error('VIPS trim failed: ' . $e->getMessage());
            return $image;
        }
    }

    /**
     * @return string|null Binary image data or null on failure
     */
    protected function createThumbnailWithGD(?int $width, ?int $height, string $format, bool $trim): ?string
    {
        $sourcePath = $this->sourcePath;

        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            return null;
        }

        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return null;
        }

        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $sourceType = $imageInfo[2];

        switch ($sourceType) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                if (!imageistruecolor($sourceImage)) {
                    $truecolorImage = imagecreatetruecolor($sourceWidth, $sourceHeight);
                    imagealphablending($truecolorImage, false);
                    imagesavealpha($truecolorImage, true);
                    $transparent = imagecolorallocatealpha($truecolorImage, 0, 0, 0, 127);
                    imagefilledrectangle($truecolorImage, 0, 0, $sourceWidth - 1, $sourceHeight - 1, $transparent);
                    imagealphablending($truecolorImage, true);
                    imagecopy($truecolorImage, $sourceImage, 0, 0, 0, 0, $sourceWidth, $sourceHeight);
                    imagealphablending($truecolorImage, false);
                    imagesavealpha($truecolorImage, true);
                    imagedestroy($sourceImage);
                    $sourceImage = $truecolorImage;
                } else {
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                }
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($sourcePath);
                if (!imageistruecolor($sourceImage)) {
                    $truecolorImage = imagecreatetruecolor($sourceWidth, $sourceHeight);
                    imagealphablending($truecolorImage, false);
                    imagesavealpha($truecolorImage, true);
                    $transparent = imagecolorallocatealpha($truecolorImage, 0, 0, 0, 127);
                    imagefilledrectangle($truecolorImage, 0, 0, $sourceWidth - 1, $sourceHeight - 1, $transparent);
                    imagealphablending($truecolorImage, true);
                    imagecopy($truecolorImage, $sourceImage, 0, 0, 0, 0, $sourceWidth, $sourceHeight);
                    imagealphablending($truecolorImage, false);
                    imagesavealpha($truecolorImage, true);
                    imagedestroy($sourceImage);
                    $sourceImage = $truecolorImage;
                } else {
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                }
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $sourceImage = imagecreatefromwebp($sourcePath);
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                } else {
                    return null;
                }
                break;
            default:
                return null;
        }

        if (!$sourceImage) {
            return null;
        }

        $trimBounds = null;
        if ($trim) {
            $trimBounds = $this->findTrimBoundsWithGD($sourceImage, $sourceType);
            if ($trimBounds) {
                $trimmedImage = imagecreatetruecolor($trimBounds['width'], $trimBounds['height']);
                if ($sourceType == IMAGETYPE_PNG || $sourceType == IMAGETYPE_GIF || $sourceType == IMAGETYPE_WEBP) {
                    imagealphablending($trimmedImage, false);
                    imagesavealpha($trimmedImage, true);
                    $transparent = imagecolorallocatealpha($trimmedImage, 255, 255, 255, 127);
                    imagefilledrectangle($trimmedImage, 0, 0, $trimBounds['width'], $trimBounds['height'], $transparent);
                }
                imagealphablending($trimmedImage, false);
                imagesavealpha($trimmedImage, true);
                imagealphablending($sourceImage, false);
                imagesavealpha($sourceImage, true);
                imagecopyresampled($trimmedImage, $sourceImage, 0, 0, $trimBounds['x'], $trimBounds['y'], $trimBounds['width'], $trimBounds['height'], $trimBounds['width'], $trimBounds['height']);
                imagedestroy($sourceImage);
                $sourceImage = $trimmedImage;
                $sourceWidth = $trimBounds['width'];
                $sourceHeight = $trimBounds['height'];
            }
        }

        if ($width !== null && $height === null) {
            $ratio = $sourceHeight / $sourceWidth;
            $height = (int)($width * $ratio);
        } elseif ($width === null && $height !== null) {
            $ratio = $sourceWidth / $sourceHeight;
            $width = (int)($height * $ratio);
        } elseif ($width === null && $height === null) {
            $width = 480;
            $ratio = $sourceHeight / $sourceWidth;
            $height = (int)($width * $ratio);
        }

        $thumbImage = imagecreatetruecolor($width, $height);
        if ($sourceType == IMAGETYPE_PNG || $sourceType == IMAGETYPE_GIF || $sourceType == IMAGETYPE_WEBP) {
            imagealphablending($thumbImage, false);
            imagesavealpha($thumbImage, true);
            $transparent = imagecolorallocatealpha($thumbImage, 255, 255, 255, 127);
            imagefilledrectangle($thumbImage, 0, 0, $width, $height, $transparent);
        }

        imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        switch (strtolower($format)) {
            case '.jpg':
            case '.jpeg':
                imagejpeg($thumbImage, null, 90);
                break;
            case '.png':
                imagepng($thumbImage, null, 9);
                break;
            case '.gif':
                imagegif($thumbImage);
                break;
            case '.webp':
                if (function_exists('imagewebp')) {
                    imagewebp($thumbImage, null, 90);
                } else {
                    imagejpeg($thumbImage, null, 90);
                }
                break;
            default:
                imagejpeg($thumbImage, null, 90);
        }
        $blob = ob_get_clean();

        imagedestroy($sourceImage);
        imagedestroy($thumbImage);

        return $blob;
    }

    /**
     * @param resource $image
     * @return array|false
     */
    protected function findTrimBoundsWithGD($image, int $sourceType)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $hasAlpha = ($sourceType == IMAGETYPE_PNG || $sourceType == IMAGETYPE_GIF || $sourceType == IMAGETYPE_WEBP);

        $topLeft = imagecolorat($image, 0, 0);
        $topRight = imagecolorat($image, $width - 1, 0);
        $bottomLeft = imagecolorat($image, 0, $height - 1);
        $bottomRight = imagecolorat($image, $width - 1, $height - 1);

        $cornerColors = [$topLeft, $topRight, $bottomLeft, $bottomRight];
        $backgroundColor = $topLeft;
        $cornerCounts = array_count_values($cornerColors);
        if (count($cornerCounts) > 1) {
            arsort($cornerCounts);
            $backgroundColor = array_key_first($cornerCounts);
        }

        $backgroundAlpha = null;
        if ($hasAlpha && imageistruecolor($image)) {
            $backgroundAlpha = ($backgroundColor >> 24) & 0xFF;
        }

        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixelColor = imagecolorat($image, $x, $y);
                $shouldInclude = false;

                if ($hasAlpha && $backgroundAlpha !== null && imageistruecolor($image)) {
                    $pixelAlpha = ($pixelColor >> 24) & 0xFF;
                    if ($backgroundAlpha >= 120 && $pixelAlpha >= 120) {
                        if (abs($pixelAlpha - $backgroundAlpha) <= 10) {
                            $shouldInclude = false;
                        } else {
                            $shouldInclude = true;
                        }
                    } else {
                        if ($pixelColor != $backgroundColor) {
                            $shouldInclude = true;
                        }
                    }
                } else {
                    if ($pixelColor != $backgroundColor) {
                        $shouldInclude = true;
                    }
                }

                if ($shouldInclude) {
                    if ($x < $minX) $minX = $x;
                    if ($x > $maxX) $maxX = $x;
                    if ($y < $minY) $minY = $y;
                    if ($y > $maxY) $maxY = $y;
                }
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            return false;
        }

        $trimWidth = $maxX - $minX + 1;
        $trimHeight = $maxY - $minY + 1;

        if ($minX == 0 && $minY == 0 && $trimWidth == $width && $trimHeight == $height) {
            return false;
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $trimWidth,
            'height' => $trimHeight
        ];
    }
}
