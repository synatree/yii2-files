<?php

namespace thyseus\files\services;

use thyseus\files\models\File;
use thyseus\files\services\preview\ImagickPdfDriver;
use thyseus\files\services\preview\PopplerPdfDriver;
use thyseus\files\services\preview\PreviewDriverInterface;
use Yii;

/**
 * Generates and caches document preview images (PDF first page, etc.).
 */
class DocumentPreviewService
{
    /** @var PreviewDriverInterface|null */
    private static $pdfDriver;

    /** @var bool */
    private static $driverLogged = false;

    public function getPreviewPath(File $file, ?int $maxWidth, ?int $maxHeight, string $format = '.png'): ?string
    {
        if (!$file->hasReadableBinary()) {
            return null;
        }

        if ($file->isImage() && !$file->isSvg()) {
            return $file->getThumbnailPath($maxWidth, $maxHeight, $format, false);
        }

        if (!$file->isPdf()) {
            return null;
        }

        $cacheKey = $this->getPreviewCacheKey($file, $maxWidth, $maxHeight, $format);
        $cacheDir = $this->getPreviewsDir();
        $cacheFile = $cacheDir . '/' . $cacheKey . $format;

        if (!is_dir($cacheDir)) {
            \yii\helpers\FileHelper::createDirectory($cacheDir, 0755, true);
        }

        if (is_file($cacheFile)) {
            return $cacheFile;
        }

        $driver = self::resolvePdfDriver();
        if ($driver === null) {
            return null;
        }

        if (!$driver->generateFirstPagePreview($file->filename_path, $cacheFile, $maxWidth, $maxHeight)) {
            return null;
        }

        return is_file($cacheFile) ? $cacheFile : null;
    }

    public function getPreviewCacheKey(File $file, ?int $maxWidth, ?int $maxHeight, string $format = '.png'): string
    {
        return md5(
            'preview_'
            . $file->id . '_'
            . $file->checksum . '_'
            . ($maxWidth ?? 'auto') . '_'
            . ($maxHeight ?? 'auto') . '_'
            . $format
        );
    }

    public function isPreviewKeyForFile(File $file, string $previewBasename): bool
    {
        $ext = pathinfo($previewBasename, PATHINFO_EXTENSION);
        $format = $ext ? '.' . $ext : '.png';
        $hashPart = substr($previewBasename, 0, strlen($previewBasename) - strlen($format));
        if (strlen($hashPart) !== 32) {
            return false;
        }

        $widths = [null, 64, 80, 128, 256, 512];
        foreach ($widths as $w) {
            foreach ($widths as $h) {
                if ($hashPart === $this->getPreviewCacheKey($file, $w, $h, $format)) {
                    return true;
                }
            }
        }

        return $hashPart === $this->getPreviewCacheKey($file, null, null, $format);
    }

    public function getPreviewsDir(): string
    {
        $uploadPath = Yii::$app->getModule('files')->uploadPath;
        if (strpos($uploadPath, '@') === 0) {
            $uploadPath = Yii::getAlias($uploadPath);
        }

        return $uploadPath . '/previews';
    }

    public static function resolvePdfDriver(): ?PreviewDriverInterface
    {
        if (self::$pdfDriver !== null) {
            return self::$pdfDriver ?: null;
        }

        if (PopplerPdfDriver::isAvailable()) {
            self::$pdfDriver = new PopplerPdfDriver();
        } elseif (ImagickPdfDriver::isAvailable()) {
            self::$pdfDriver = new ImagickPdfDriver();
        } else {
            self::$pdfDriver = false;
        }

        if (!self::$driverLogged) {
            self::$driverLogged = true;
            if (self::$pdfDriver instanceof PreviewDriverInterface) {
                Yii::info('Document preview PDF driver: ' . self::$pdfDriver->getName(), __METHOD__);
            } else {
                Yii::info('Document preview PDF driver: none (install poppler-utils or imagick)', __METHOD__);
            }
        }

        return self::$pdfDriver instanceof PreviewDriverInterface ? self::$pdfDriver : null;
    }

    /**
     * @internal For tests
     */
    public static function resetDrivers(): void
    {
        self::$pdfDriver = null;
        self::$driverLogged = false;
    }
}
