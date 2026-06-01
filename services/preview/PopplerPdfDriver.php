<?php

namespace thyseus\files\services\preview;

use thyseus\files\FileWebModule;
use Yii;

/**
 * Renders PDF page 1 via pdftoppm (poppler-utils).
 */
class PopplerPdfDriver implements PreviewDriverInterface
{
    /** @var string|null Cached path to pdftoppm binary; empty string means unavailable */
    private static $binaryPath;

    public static function isAvailable(): bool
    {
        return self::resolveBinary() !== null;
    }

    public function getName(): string
    {
        return 'poppler';
    }

    public function generateFirstPagePreview(string $sourcePath, string $outputPath, ?int $maxWidth, ?int $maxHeight): bool
    {
        $binary = self::resolveBinary();
        if ($binary === null || !is_readable($sourcePath)) {
            return false;
        }

        $maxDim = max(64, (int) ($maxWidth ?? $maxHeight ?? 256));
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) && !@mkdir($outputDir, 0755, true)) {
            return false;
        }

        $prefix = $outputDir . '/' . pathinfo($outputPath, PATHINFO_FILENAME) . '_ppm';
        $existing = glob($prefix . '-*.png');
        if (is_array($existing)) {
            foreach ($existing as $old) {
                @unlink($old);
            }
        }

        $cmd = escapeshellarg($binary)
            . ' -png -f 1 -l 1 -scale-to ' . (int) $maxDim . ' '
            . escapeshellarg($sourcePath) . ' '
            . escapeshellarg($prefix);

        $output = [];
        $exitCode = 1;
        @exec($cmd . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            Yii::warning('PopplerPdfDriver failed: ' . implode("\n", $output), __METHOD__);

            return false;
        }

        $generated = glob($prefix . '-*.png');
        if (empty($generated) || !is_file($generated[0])) {
            return false;
        }

        if (!@rename($generated[0], $outputPath)) {
            return @copy($generated[0], $outputPath);
        }

        return is_file($outputPath);
    }

    /**
     * @internal For tests
     */
    public static function resetCachedBinary(): void
    {
        self::$binaryPath = null;
    }

    private static function resolveBinary(): ?string
    {
        if (self::$binaryPath !== null) {
            return self::$binaryPath !== '' ? self::$binaryPath : null;
        }

        foreach (self::binaryCandidates() as $candidate) {
            $resolved = self::resolveCandidate($candidate);
            if ($resolved !== null) {
                self::$binaryPath = $resolved;

                return $resolved;
            }
        }

        self::$binaryPath = '';

        return null;
    }

    /**
     * @return string[]
     */
    private static function binaryCandidates(): array
    {
        $candidates = [];

        $module = FileWebModule::getInstance() ?? Yii::$app->getModule('files');
        if ($module instanceof FileWebModule) {
            if (!empty($module->pdftoppmPath)) {
                $candidates[] = (string) $module->pdftoppmPath;
            }
            if (!empty($module->popplerPath)) {
                $candidates = array_merge($candidates, self::pathsFromPopplerRoot((string) $module->popplerPath));
            }
        }

        $envPath = getenv('POPPLER_PATH') ?: getenv('poppler_path');
        if ($envPath) {
            $candidates = array_merge($candidates, self::pathsFromPopplerRoot($envPath));
        }

        $envBinary = getenv('PDFTOPPM_PATH') ?: getenv('pdftoppm_path');
        if ($envBinary) {
            $candidates[] = $envBinary;
        }

        $candidates[] = 'pdftoppm';

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = 'C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe';
            $candidates[] = 'C:\\poppler\\Library\\bin\\pdftoppm.exe';
            $candidates[] = 'C:\\poppler-26.02.0\\Library\\bin\\pdftoppm.exe';

            foreach (glob('C:\\poppler-*\\Library\\bin\\pdftoppm.exe') ?: [] as $match) {
                $candidates[] = $match;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return string[]
     */
    private static function pathsFromPopplerRoot(string $root): array
    {
        $root = rtrim($root, "\\/");

        if (DIRECTORY_SEPARATOR === '\\') {
            return [
                $root . '\\Library\\bin\\pdftoppm.exe',
                $root . '\\bin\\pdftoppm.exe',
            ];
        }

        return [
            $root . '/bin/pdftoppm',
            $root . '/usr/bin/pdftoppm',
        ];
    }

    private static function resolveCandidate(string $candidate): ?string
    {
        if (self::isAbsolutePath($candidate)) {
            return self::isRunnable($candidate) ? $candidate : null;
        }

        return self::which($candidate);
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private static function isRunnable(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        if (@is_executable($path)) {
            return true;
        }
        // Windows .exe often fails is_executable() even when runnable.
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/\.exe$/i', $path)) {
            return is_readable($path);
        }

        return false;
    }

    private static function which(string $command): ?string
    {
        $pathEnv = getenv('PATH') ?: getenv('Path');
        if (!$pathEnv) {
            return null;
        }

        $suffix = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            $full = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $command . $suffix;
            if (self::isRunnable($full)) {
                return $full;
            }
        }

        return null;
    }
}
