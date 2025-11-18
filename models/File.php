<?php

namespace thyseus\files\models;

use app\models\User;
use thyseus\files\events\ShareWithUserEvent;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\SluggableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use thyseus\files\FileWebModule;

/**
 * This is the model class for table "file".
 *
 * @property string $id
 * @property integer $user_id
 * @property string $offer_id
 *
 * @property Offer $offer
 */
class File extends ActiveRecord
{
    use CropTrait;

    const STATUS_DELETED = -2; # Restoration not possible anymore. File could be shared with other people, they still have access!
    const STATUS_TRASHED = -1; # Only mark as deleted, can be restored
    const STATUS_NORMAL = 0; # solely owner

    const EVENT_BEFORE_SHARE_WITH_USER = 'before_share_with_user';
    const EVENT_AFTER_SHARE_WITH_USER = 'after_share_with_user';

    /**
     * @var $content in order to generate the md5 sum of the file the content is temporarily saved here.
     * Does cost much memory when using big files - ideas on how to do this better are always welcome.
     */
    public $content;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{files}}';
    }

    public static function getDb(){
        return Yii::$app->getModule('files')->get('db');    // because the model is instantiated without the module, have to use this syntax
    }

    public function __toString()
    {
        return $this->filename_path;
    }

    /**
     * Returns an link that downloads the file.
     * @param bool $raw
     * @param string $caption optional: the caption for the link.
     * @return string
     */
    public function downloadLink($raw = false, $caption = false)
    {
        if (!$caption) {
            $innerHtml = '<span class="fa fa-download" aria-hidden="true"></span> ' . Yii::t('app', 'Download');
        } else {
            $innerHtml = $caption;
        }
        return Html::a($innerHtml, $this->downloadUrl($raw), ['data-pjax' => '0']);
    }
    public static function absUrl($path=[])
    {
        $module = FileWebModule::getInstance() ?? Yii::$app->getModule('files');
        $urlManager = $module->get('urlManager');
        return $urlManager->createAbsoluteUrl($path);
    }
    public function downloadUrl($raw = false)
    {
       
        return self::absUrl(['//files/file/download', 'id' => $this->slug, 'raw' => $raw]);
    }

    /**
     * Get URL to a trimmed thumbnail, generating and caching it if it doesn't exist
     * @param int|null $width Target width
     * @param int|null $height Target height (optional, maintains aspect ratio if not provided)
     * @param string $format Output format (e.g., '.png', '.jpg')
     * @param bool $trim Whether to trim based on upper-left pixel color
     * @return string URL to the thumbnail
     */
    public function thumbnailUrl($width = null, $height = null, $format = '.png', $trim = false)
    {
        Yii::info('thumbnailUrl() called - File ID: ' . $this->id . ', width: ' . ($width ?? 'null') . ', height: ' . ($height ?? 'null') . ', format: ' . $format . ', trim: ' . ($trim ? 'true' : 'false'));
        
        if (!$this->isImage()) {
            Yii::info('File ' . $this->id . ' is not an image, returning downloadUrl()');
            return $this->downloadUrl();
        }

        // SVG files are scalable vector graphics - no need to generate thumbnails
        // Return the original file URL directly
        if ($this->isSvg()) {
            Yii::info('File ' . $this->id . ' is an SVG, returning downloadUrl() directly (SVG is scalable)');
            return $this->downloadUrl(true); // true = raw URL
        }

        // Generate cache key based on file properties and parameters
        $cacheKey = md5($this->id . '_' . $this->checksum . '_' . ($width ?? 'auto') . '_' . ($height ?? 'auto') . '_' . $format . '_' . ($trim ? 'trim' : 'notrim'));
        $uploadPath = Yii::$app->getModule('files')->uploadPath;
        // Resolve alias if needed
        if (strpos($uploadPath, '@') === 0) {
            $uploadPath = Yii::getAlias($uploadPath);
        }
        $cacheDir = $uploadPath . '/thumbnails';
        $cacheFile = $cacheDir . '/' . $cacheKey . $format;
        
        Yii::info('Thumbnail path resolution - File ID: ' . $this->id . ', uploadPath: ' . $uploadPath . ', cacheDir: ' . $cacheDir . ', cacheFile: ' . $cacheFile . ', cacheKey: ' . $cacheKey);
        
        // Create thumbnails directory if it doesn't exist
        if (!is_dir($cacheDir)) {
            Yii::info('Creating thumbnails directory: ' . $cacheDir);
            $created = \yii\helpers\FileHelper::createDirectory($cacheDir, 0755, true);
            if (!$created) {
                Yii::error('Failed to create thumbnails directory: ' . $cacheDir);
            } else {
                Yii::info('Successfully created thumbnails directory: ' . $cacheDir);
            }
        } else {
            Yii::info('Thumbnails directory already exists: ' . $cacheDir);
        }
        
        // Generate thumbnail if it doesn't exist
        if (!file_exists($cacheFile)) {
            Yii::info('Thumbnail does not exist, generating for file ' . $this->id . ' - Source: ' . $this->filename_path . ' - Output: ' . $cacheFile . ' - Trim: ' . ($trim ? 'true' : 'false'));
            $success = $this->generateThumbnail($width, $height, $format, $trim, $cacheFile);
            if (!$success || !file_exists($cacheFile)) {
                // If generation failed, log error and return original file URL
                Yii::error('Failed to generate thumbnail for file: ' . $this->id . ' - Cache file: ' . $cacheFile . ' - Success: ' . ($success ? 'true' : 'false') . ' - File exists: ' . (file_exists($cacheFile) ? 'true' : 'false'));
                return $this->downloadUrl();
            }
            Yii::info('Successfully generated thumbnail for file ' . $this->id . ' at ' . $cacheFile . ' - File size: ' . filesize($cacheFile) . ' bytes');
        } else {
            Yii::info('Thumbnail already exists for file ' . $this->id . ' at ' . $cacheFile . ' - File size: ' . filesize($cacheFile) . ' bytes');
        }
        
        // Return URL to the cached thumbnail via download action with thumbnail parameter
        $url = self::absUrl(['//files/file/download', 'id' => $this->slug, 'thumbnail' => $cacheKey . $format]);
        Yii::info('Returning thumbnail URL for file ' . $this->id . ': ' . $url);
        return $url;
    }

    /**
     * Generate and save a thumbnail to the specified path
     * @param int|null $width Target width
     * @param int|null $height Target height
     * @param string $format Output format
     * @param bool $trim Whether to trim
     * @param string $outputPath Path where to save the thumbnail
     * @return bool Success
     */
    protected function generateThumbnail($width, $height, $format, $trim, $outputPath)
    {
        Yii::info('generateThumbnail() called - File ID: ' . $this->id . ', width: ' . ($width ?? 'null') . ', height: ' . ($height ?? 'null') . ', format: ' . $format . ', trim: ' . ($trim ? 'true' : 'false') . ', outputPath: ' . $outputPath);
        Yii::info('generateThumbnail() - File mimetype: ' . $this->mimetype . ', isImage(): ' . ($this->isImage() ? 'true' : 'false') . ', filename_path: ' . $this->filename_path);
        $vipsLoaded = extension_loaded('vips') || class_exists('\Jcupitt\Vips\Image');
        $gdLoaded = extension_loaded('gd') || function_exists('imagecreatefromjpeg') || function_exists('imagecreatefrompng');
        Yii::info('generateThumbnail() - VIPS loaded: ' . ($vipsLoaded ? 'true' : 'false') . ', GD loaded: ' . ($gdLoaded ? 'true' : 'false'));
        
        try {
            // Use the existing inline logic but save to file instead of returning base64
            if ($this->isImage()) {
                Yii::info('generateThumbnail() - File is an image, proceeding with thumbnail generation');
                
                // SVG files are scalable vector graphics - no thumbnail generation needed
                // They should be handled by thumbnailUrl() which returns the original file
                if ($this->isSvg()) {
                    Yii::info('generateThumbnail() - SVG file detected, thumbnail generation not needed (SVG is scalable)');
                    return false; // Return false so thumbnailUrl() can return the original file URL
                }
                
                // Try to use VIPS if available
                if ($vipsLoaded) {
                    Yii::info('VIPS extension is loaded, attempting to use VIPS for file ' . $this->id);
                    try {
                        if (class_exists('\Jcupitt\Vips\Image')) {
                            Yii::info('Loading image with VIPS from: ' . $this->filename_path);
                            $image = \Jcupitt\Vips\Image::newFromFile($this->filename_path);
                            Yii::info('VIPS image loaded - Original dimensions: ' . $image->width . 'x' . $image->height);
                            
                            // Trim based on upper-left pixel color if requested
                            if ($trim) {
                                Yii::info('Trimming image with VIPS for file ' . $this->id);
                                $image = $this->trimImageWithVIPS($image);
                                Yii::info('After VIPS trim - Dimensions: ' . $image->width . 'x' . $image->height);
                            } else {
                                Yii::info('Trim not requested for file ' . $this->id);
                            }
                            
                            // Create thumbnail
                            $args = array_filter([$width ?? 480, $height ? ['height' => $height] : null]);
                            Yii::info('Creating VIPS thumbnail with args: ' . json_encode($args));
                            if (!empty($args)) {
                                $thumb = $image->thumbnail(...$args);
                            } else {
                                $thumb = $image;
                            }
                            Yii::info('VIPS thumbnail created - Dimensions: ' . $thumb->width . 'x' . $thumb->height);
                            
                            // Save to file
                            Yii::info('Writing VIPS thumbnail to file: ' . $outputPath);
                            $thumb->writeToFile($outputPath);
                            // Verify file was created
                            if (!file_exists($outputPath)) {
                                Yii::error('VIPS wrote thumbnail but file does not exist: ' . $outputPath);
                                return false;
                            }
                            Yii::info('VIPS thumbnail successfully written to: ' . $outputPath . ' - Size: ' . filesize($outputPath) . ' bytes');
                            return true;
                        } else {
                            Yii::warning('VIPS extension loaded but \Jcupitt\Vips\Image class not found');
                        }
                    } catch (\Exception $e) {
                        // Log VIPS error and fall back to GD if VIPS fails
                        Yii::error('VIPS thumbnail generation failed: ' . $e->getMessage() . ' - Stack trace: ' . $e->getTraceAsString() . ' - Falling back to GD');
                        if ($gdLoaded) {
                            Yii::info('Falling back to GD for file ' . $this->id);
                            return $this->generateThumbnailWithGD($width, $height, $format, $trim, $outputPath);
                        }
                    }
                } elseif ($gdLoaded) {
                    Yii::info('VIPS not available, using GD for file ' . $this->id);
                    return $this->generateThumbnailWithGD($width, $height, $format, $trim, $outputPath);
                } else {
                    Yii::error('Neither VIPS nor GD extension is available for file ' . $this->id);
                }
            } else {
                Yii::error('File ' . $this->id . ' is not an image (mimetype: ' . $this->mimetype . ') - Skipping thumbnail generation');
            }
            Yii::info('generateThumbnail() - Returning false (file is not an image or no image processor available)');
            return false;
        } catch (\Exception $e) {
            Yii::error('Failed to generate thumbnail: ' . $e->getMessage() . ' - File: ' . $this->filename_path . ' - Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Generate thumbnail using GD and save to file
     * @param int|null $width Target width
     * @param int|null $height Target height
     * @param string $format Output format
     * @param bool $trim Whether to trim
     * @param string $outputPath Path where to save the thumbnail
     * @return bool Success
     */
    protected function generateThumbnailWithGD($width, $height, $format, $trim, $outputPath)
    {
        Yii::info('generateThumbnailWithGD() called - File ID: ' . $this->id . ', width: ' . ($width ?? 'null') . ', height: ' . ($height ?? 'null') . ', format: ' . $format . ', trim: ' . ($trim ? 'true' : 'false') . ', outputPath: ' . $outputPath);
        
        $blob = $this->createThumbnailWithGD($width ?? 480, $height, $format, $trim);
        if ($blob) {
            Yii::info('GD thumbnail blob generated for file ' . $this->id . ' - Blob size: ' . strlen($blob) . ' bytes');
            $written = file_put_contents($outputPath, $blob);
            if ($written !== false) {
                Yii::info('GD thumbnail successfully written to: ' . $outputPath . ' - Bytes written: ' . $written);
                return true;
            } else {
                Yii::error('Failed to write GD thumbnail to file: ' . $outputPath);
                return false;
            }
        } else {
            Yii::error('createThumbnailWithGD() returned empty blob for file ' . $this->id);
        }
        return false;
    }

    public function deleteUrl(){
        $module = FileWebModule::getInstance() ?? Yii::$app->getModule('files');
        $accessTokenProperty = $module->accessTokenProperty ?? 'apiKey';
        $accessToken = Yii::$app->user->identity->{$accessTokenProperty} ?? null;
        return self::absUrl(['//files/file/delete', 'id' => $this->slug, 'access-token' => $accessToken]);
    }

    public function isImage()
    {
        return strpos($this->mimetype, 'image') !== false;
    }

    /**
     * Check if the file is an SVG image
     * @return bool
     */
    public function isSvg()
    {
        return strpos($this->mimetype, 'svg') !== false || 
               (isset($this->filename_user) && strtolower(pathinfo($this->filename_user, PATHINFO_EXTENSION)) === 'svg');
    }

    public function inline($w=null, $h=null, $format='.jpg', $mime='image/jpeg', $trim=false)
    {
        // Check if file exists before processing
        if (!file_exists($this->filename_path) || !is_readable($this->filename_path)) {
            Yii::error('File::inline() - Source file does not exist or is not readable: ' . $this->filename_path . ' (File ID: ' . $this->id . ')');
            return null; // Return null to indicate file is missing
        }
        
        if($this->isImage())
        {
            // SVG files are vector graphics - return them directly as data URIs
            // No processing needed since they're already scalable
            if ($this->isSvg()) {
                $blob = file_get_contents($this->filename_path);
                $mime = $this->mimetype ?: 'image/svg+xml';
                $blob = base64_encode($blob);
                return "data:{$mime};base64,$blob";
            }
            
            // Try to use VIPS if available
            if (extension_loaded('vips')) {
                try {
                    if (class_exists('\Jcupitt\Vips\Image')) {
                        $image = \Jcupitt\Vips\Image::newFromFile($this->filename_path);
                        
                        // Trim based on upper-left pixel color if requested
                        if ($trim) {
                            $image = $this->trimImageWithVIPS($image);
                        }
                        
                        // Create thumbnail
                        $args = array_filter([$w ?? 480, $h ? ['height' => $h] : null]);
                        if (!empty($args)) {
                            $thumb = $image->thumbnail(...$args);
                        } else {
                            $thumb = $image;
                        }
                        $blob = $thumb->writeToBuffer($format);
                    } else {
                        throw new \Exception('VIPS Image class not found');
                    }
                } catch (\Exception $e) {
                    // Fallback to GD if VIPS fails
                    if (extension_loaded('gd')) {
                        try {
                            $blob = $this->createThumbnailWithGD($w ?? 480, $h, $format, $trim);
                        } catch (\Exception $gdException) {
                            Yii::error('File::inline() - GD thumbnail creation failed: ' . $gdException->getMessage());
                            return null; // Return null if both VIPS and GD fail
                        }
                    } else {
                        // No image processing extension available, return original
                        $blob = file_get_contents($this->filename_path);
                        $mime = $this->mimetype;
                    }
                }
            } elseif (extension_loaded('gd')) {
                // Fallback to GD if VIPS is not available
                try {
                    $blob = $this->createThumbnailWithGD($w ?? 480, $h, $format, $trim);
                } catch (\Exception $gdException) {
                    Yii::error('File::inline() - GD thumbnail creation failed: ' . $gdException->getMessage());
                    return null; // Return null if GD fails
                }
            } else {
                // No image processing extension available, return original
                $blob = file_get_contents($this->filename_path);
                $mime = $this->mimetype;
            }
        }
        else
        {
            $blob = file_get_contents($this->filename_path);
            $mime = $this->mimetype;
            
        }
        $blob = base64_encode($blob);    
        
        return "data:{$mime};base64,$blob";
    }

    /**
     * Trim image based on upper-left pixel color using VIPS
     * Detects the upper-left pixel color and trims away all matching pixels to leave only the central image
     * @param \Jcupitt\Vips\Image $image The VIPS image object
     * @return \Jcupitt\Vips\Image The trimmed image
     */
    protected function trimImageWithVIPS($image)
    {
        Yii::info('trimImageWithVIPS() called - File ID: ' . $this->id);
        try {
            $width = $image->width;
            $height = $image->height;
            Yii::info('VIPS trim - Original image dimensions: ' . $width . 'x' . $height);
            
            // Get the upper-left pixel color
            // Extract a 1x1 pixel from the upper-left corner
            $sample = $image->crop(0, 0, 1, 1);
            $pixelData = $sample->writeToArray();
            Yii::info('VIPS trim - Pixel data structure: ' . json_encode(array_map(function($arr) { return is_array($arr) ? 'array(' . count($arr) . ')' : $arr; }, $pixelData)));
            
            // Get RGB values from the pixel (assuming RGB or RGBA format)
            $backgroundR = $pixelData[0][0][0] ?? 0;
            $backgroundG = $pixelData[0][0][1] ?? 0;
            $backgroundB = $pixelData[0][0][2] ?? 0;
            $hasAlpha = isset($pixelData[0][0][3]);
            $backgroundA = $hasAlpha ? ($pixelData[0][0][3] ?? 255) : 255;
            Yii::info('VIPS trim - Background color detected - R: ' . $backgroundR . ', G: ' . $backgroundG . ', B: ' . $backgroundB . ', A: ' . $backgroundA . ', hasAlpha: ' . ($hasAlpha ? 'true' : 'false'));
            
            // Convert image to array to scan pixels
            Yii::info('VIPS trim - Converting image to array for scanning...');
            $imageData = $image->writeToArray();
            $channels = count($imageData);
            Yii::info('VIPS trim - Image converted to array, channels: ' . $channels);
            
            $minX = $width;
            $minY = $height;
            $maxX = -1;
            $maxY = -1;
            
            // Scan all pixels to find bounding box of pixels that differ from the background
            Yii::info('VIPS trim - Scanning ' . ($width * $height) . ' pixels to find trim bounds...');
            $pixelsScanned = 0;
            $pixelsIncluded = 0;
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $pixelsScanned++;
                    $pixelR = $imageData[0][$y][$x] ?? 0;
                    $pixelG = $imageData[1][$y][$x] ?? 0;
                    $pixelB = $imageData[2][$y][$x] ?? 0;
                    $pixelA = ($channels > 3) ? ($imageData[3][$y][$x] ?? 255) : 255;
                    
                    $shouldInclude = false;
                    
                    if ($hasAlpha && $backgroundA < 128) {
                        // Background is transparent - include any non-transparent pixel
                        if ($pixelA >= 128) {
                            $shouldInclude = true;
                        }
                    } else {
                        // Background has color - include pixels that differ in color OR alpha
                        if ($pixelR != $backgroundR || $pixelG != $backgroundG || $pixelB != $backgroundB || $pixelA != $backgroundA) {
                            $shouldInclude = true;
                        }
                    }
                    
                    if ($shouldInclude) {
                        $pixelsIncluded++;
                        if ($x < $minX) $minX = $x;
                        if ($x > $maxX) $maxX = $x;
                        if ($y < $minY) $minY = $y;
                        if ($y > $maxY) $maxY = $y;
                    }
                }
            }
            Yii::info('VIPS trim - Scan complete - Pixels scanned: ' . $pixelsScanned . ', pixels included: ' . $pixelsIncluded . ', bounds: minX=' . $minX . ', maxX=' . $maxX . ', minY=' . $minY . ', maxY=' . $maxY);
            
            // If no different pixels found or same dimensions, return original
            if ($maxX < $minX || $maxY < $minY) {
                Yii::warning('VIPS trim - No different pixels found, returning original image');
                return $image;
            }
            
            $trimWidth = $maxX - $minX + 1;
            $trimHeight = $maxY - $minY + 1;
            Yii::info('VIPS trim - Trim dimensions calculated: ' . $trimWidth . 'x' . $trimHeight . ' at position (' . $minX . ', ' . $minY . ')');
            
            // If the trim area is the same as the original, no trimming needed
            if ($minX == 0 && $minY == 0 && $trimWidth == $width && $trimHeight == $height) {
                Yii::info('VIPS trim - Trim area matches original, no trimming needed');
                return $image;
            }
            
            // Crop to the trimmed bounding box
            Yii::info('VIPS trim - Cropping image to trimmed bounds');
            $trimmed = $image->crop($minX, $minY, $trimWidth, $trimHeight);
            Yii::info('VIPS trim - Successfully trimmed image from ' . $width . 'x' . $height . ' to ' . $trimmed->width . 'x' . $trimmed->height);
            return $trimmed;
        } catch (\Exception $e) {
            // If trimming fails, return original image
            Yii::error('VIPS trim failed: ' . $e->getMessage() . ' - Stack trace: ' . $e->getTraceAsString());
            return $image;
        }
    }

    /**
     * Trim image based on upper-left pixel color using GD
     * @param resource $image The GD image resource
     * @param int $sourceType The image type constant (IMAGETYPE_PNG, etc.)
     * @return array|false Returns array with [x, y, width, height] of bounding box, or false if no trim needed
     */
    protected function findTrimBoundsWithGD($image, $sourceType)
    {
        Yii::info('findTrimBoundsWithGD() called - File ID: ' . $this->id . ', sourceType: ' . $sourceType);
        $width = imagesx($image);
        $height = imagesy($image);
        Yii::info('GD trim - Image dimensions: ' . $width . 'x' . $height . ', isTrueColor: ' . (imageistruecolor($image) ? 'true' : 'false'));
        
        // Check if image supports transparency (PNG, GIF, WebP)
        // Note: We assume the image is already converted to truecolor if needed
        $hasAlpha = ($sourceType == IMAGETYPE_PNG || $sourceType == IMAGETYPE_GIF || $sourceType == IMAGETYPE_WEBP);
        Yii::info('GD trim - Has alpha support: ' . ($hasAlpha ? 'true' : 'false'));
        
        // Get the color of the upper-left pixel (background color to trim)
        // Also check other corners to ensure we have the correct border color
        $topLeft = imagecolorat($image, 0, 0);
        $topRight = imagecolorat($image, $width - 1, 0);
        $bottomLeft = imagecolorat($image, 0, $height - 1);
        $bottomRight = imagecolorat($image, $width - 1, $height - 1);
        
        // Use the most common corner color as the background (border) color
        $cornerColors = [$topLeft, $topRight, $bottomLeft, $bottomRight];
        $backgroundColor = $topLeft; // Default to top-left
        $cornerCounts = array_count_values($cornerColors);
        if (count($cornerCounts) > 1) {
            // If corners differ, use the most common one
            arsort($cornerCounts);
            $backgroundColor = array_key_first($cornerCounts);
            Yii::info('GD trim - Corner colors differ - TopLeft: ' . $topLeft . ', TopRight: ' . $topRight . ', BottomLeft: ' . $bottomLeft . ', BottomRight: ' . $bottomRight . ', Using most common: ' . $backgroundColor);
        }
        
        // Extract alpha component if image supports transparency
        // In GD, alpha is in bits 24-31: 0 = opaque, 127 = fully transparent
        $backgroundAlpha = null;
        if ($hasAlpha && imageistruecolor($image)) {
            $backgroundAlpha = ($backgroundColor >> 24) & 0xFF;
        }
        
        // Extract RGB components for logging
        $bgR = ($backgroundColor >> 16) & 0xFF;
        $bgG = ($backgroundColor >> 8) & 0xFF;
        $bgB = $backgroundColor & 0xFF;
        Yii::info('GD trim - Background color - R: ' . $bgR . ', G: ' . $bgG . ', B: ' . $bgB . ', A: ' . ($backgroundAlpha !== null ? $backgroundAlpha : 'N/A') . ', Full color: ' . $backgroundColor);
        
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        
        // Scan all pixels to find bounding box of pixels that differ from the background
        Yii::info('GD trim - Scanning ' . ($width * $height) . ' pixels to find trim bounds...');
        $pixelsScanned = 0;
        $pixelsIncluded = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixelsScanned++;
                $pixelColor = imagecolorat($image, $x, $y);
                $shouldInclude = false;
                
                if ($hasAlpha && $backgroundAlpha !== null && imageistruecolor($image)) {
                    // For images with alpha channel, do exact RGBA matching
                    // However, if background is nearly transparent (alpha >= 120), 
                    // also treat pixels with similar high alpha as background (to handle slight variations)
                    $pixelAlpha = ($pixelColor >> 24) & 0xFF;
                    
                    if ($backgroundAlpha >= 120 && $pixelAlpha >= 120) {
                        // Both are nearly transparent - treat as matching if alpha is close
                        // Use a tolerance of 10 for nearly transparent pixels
                        if (abs($pixelAlpha - $backgroundAlpha) <= 10) {
                            // Pixels are both nearly transparent with similar alpha - treat as background
                            $shouldInclude = false;
                        } else {
                            // Pixel differs significantly in alpha
                            $shouldInclude = true;
                        }
                    } else {
                        // For opaque or mixed transparency, do exact matching
                        if ($pixelColor != $backgroundColor) {
                            $shouldInclude = true;
                        }
                    }
                } else {
                    // For images without alpha, simple color comparison
                    if ($pixelColor != $backgroundColor) {
                        $shouldInclude = true;
                    }
                }
                
                if ($shouldInclude) {
                    $pixelsIncluded++;
                    if ($x < $minX) $minX = $x;
                    if ($x > $maxX) $maxX = $x;
                    if ($y < $minY) $minY = $y;
                    if ($y > $maxY) $maxY = $y;
                }
            }
        }
        Yii::info('GD trim - Scan complete - Pixels scanned: ' . $pixelsScanned . ', pixels included: ' . $pixelsIncluded . ', bounds: minX=' . $minX . ', maxX=' . $maxX . ', minY=' . $minY . ', maxY=' . $maxY);
        
        // If no different pixels found, return false
        if ($maxX < $minX || $maxY < $minY) {
            Yii::warning('GD trim - No different pixels found, returning false');
            return false;
        }
        
        $trimWidth = $maxX - $minX + 1;
        $trimHeight = $maxY - $minY + 1;
        Yii::info('GD trim - Trim dimensions calculated: ' . $trimWidth . 'x' . $trimHeight . ' at position (' . $minX . ', ' . $minY . ')');
        
        // If the trim area is the same as the original, no trimming needed
        if ($minX == 0 && $minY == 0 && $trimWidth == $width && $trimHeight == $height) {
            Yii::info('GD trim - Trim area matches original, returning false (no trim needed)');
            return false;
        }
        
        $bounds = [
            'x' => $minX,
            'y' => $minY,
            'width' => $trimWidth,
            'height' => $trimHeight
        ];
        Yii::info('GD trim - Returning trim bounds: ' . json_encode($bounds));
        return $bounds;
    }

    /**
     * Create thumbnail using GD library as fallback when VIPS is not available
     * @param int $width Target width
     * @param int|null $height Target height (optional, maintains aspect ratio if not provided)
     * @param string $format Output format (e.g., '.jpg', '.png')
     * @param bool $trim Whether to trim transparent pixels before resizing
     * @return string Binary image data
     */
    protected function createThumbnailWithGD($width, $height = null, $format = '.jpg', $trim = false)
    {
        Yii::info('createThumbnailWithGD() called - File ID: ' . $this->id . ', width: ' . $width . ', height: ' . ($height ?? 'null') . ', format: ' . $format . ', trim: ' . ($trim ? 'true' : 'false'));
        $sourcePath = $this->filename_path;
        Yii::info('GD thumbnail - Source file: ' . $sourcePath);
        
        // Check if file exists
        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            Yii::error('GD thumbnail - Source file does not exist or is not readable: ' . $sourcePath);
            throw new \Exception('Source file does not exist or is not readable');
        }
        
        // Determine image type and load source image
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            Yii::error('GD thumbnail - Unable to get image size from: ' . $sourcePath . ' (file exists but may be corrupted or not a valid image)');
            throw new \Exception('Unable to get image size - file may be corrupted or not a valid image');
        }
        
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $sourceType = $imageInfo[2];
        Yii::info('GD thumbnail - Image info - Width: ' . $sourceWidth . ', Height: ' . $sourceHeight . ', Type: ' . $sourceType);
        
        // Load source image based on type
        Yii::info('GD thumbnail - Loading image based on type: ' . $sourceType);
        switch ($sourceType) {
            case IMAGETYPE_JPEG:
                Yii::info('GD thumbnail - Loading JPEG image');
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                Yii::info('GD thumbnail - Loading PNG image');
                $sourceImage = imagecreatefrompng($sourcePath);
                // Convert palette PNG to truecolor to preserve alpha channel
                if (!imageistruecolor($sourceImage)) {
                    Yii::info('GD thumbnail - PNG is palette-based, converting to truecolor');
                    // Create truecolor image with alpha support
                    $truecolorImage = imagecreatetruecolor($sourceWidth, $sourceHeight);
                    imagealphablending($truecolorImage, false);
                    imagesavealpha($truecolorImage, true);
                    
                    // Fill entire image with transparent color using imagefilledrectangle
                    $transparent = imagecolorallocatealpha($truecolorImage, 0, 0, 0, 127);
                    imagefilledrectangle($truecolorImage, 0, 0, $sourceWidth - 1, $sourceHeight - 1, $transparent);
                    
                    // Enable blending for copy operation to preserve source transparency
                    imagealphablending($truecolorImage, true);
                    // Copy the palette image to truecolor, preserving transparency
                    imagecopy($truecolorImage, $sourceImage, 0, 0, 0, 0, $sourceWidth, $sourceHeight);
                    // Disable blending to preserve alpha
                    imagealphablending($truecolorImage, false);
                    imagesavealpha($truecolorImage, true);
                    
                    imagedestroy($sourceImage);
                    $sourceImage = $truecolorImage;
                    Yii::info('GD thumbnail - PNG converted to truecolor');
                } else {
                    // Ensure alpha channel is preserved for truecolor images
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                    Yii::info('GD thumbnail - PNG is already truecolor, preserving alpha');
                }
                break;
            case IMAGETYPE_GIF:
                Yii::info('GD thumbnail - Loading GIF image');
                $sourceImage = imagecreatefromgif($sourcePath);
                // Convert GIF to truecolor to handle transparency properly
                if (!imageistruecolor($sourceImage)) {
                    Yii::info('GD thumbnail - GIF is palette-based, converting to truecolor');
                    // Create truecolor image with alpha support
                    $truecolorImage = imagecreatetruecolor($sourceWidth, $sourceHeight);
                    imagealphablending($truecolorImage, false);
                    imagesavealpha($truecolorImage, true);
                    
                    // Fill entire image with transparent color using imagefilledrectangle
                    $transparent = imagecolorallocatealpha($truecolorImage, 0, 0, 0, 127);
                    imagefilledrectangle($truecolorImage, 0, 0, $sourceWidth - 1, $sourceHeight - 1, $transparent);
                    
                    // Enable blending for copy operation to preserve source transparency
                    imagealphablending($truecolorImage, true);
                    // Copy the palette image to truecolor, preserving transparency
                    imagecopy($truecolorImage, $sourceImage, 0, 0, 0, 0, $sourceWidth, $sourceHeight);
                    // Disable blending to preserve alpha
                    imagealphablending($truecolorImage, false);
                    imagesavealpha($truecolorImage, true);
                    
                    imagedestroy($sourceImage);
                    $sourceImage = $truecolorImage;
                    Yii::info('GD thumbnail - GIF converted to truecolor');
                } else {
                    // Ensure alpha channel is preserved for truecolor images
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                    Yii::info('GD thumbnail - GIF is already truecolor, preserving alpha');
                }
                break;
            case IMAGETYPE_WEBP:
                Yii::info('GD thumbnail - Loading WebP image');
                if (function_exists('imagecreatefromwebp')) {
                    $sourceImage = imagecreatefromwebp($sourcePath);
                    // Ensure alpha channel is preserved
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                } else {
                    Yii::error('GD thumbnail - WebP format not supported by GD');
                    throw new \Exception('WebP format not supported by GD');
                }
                break;
            default:
                Yii::error('GD thumbnail - Unsupported image format: ' . $sourceType);
                throw new \Exception('Unsupported image format');
        }
        
        if (!$sourceImage) {
            Yii::error('GD thumbnail - Failed to load source image from: ' . $sourcePath);
            throw new \Exception('Failed to load source image');
        }
        Yii::info('GD thumbnail - Source image loaded successfully - Dimensions: ' . imagesx($sourceImage) . 'x' . imagesy($sourceImage) . ', isTrueColor: ' . (imageistruecolor($sourceImage) ? 'true' : 'false'));
        
        // Trim based on upper-left pixel color if requested
        $trimBounds = null;
        if ($trim) {
            Yii::info('GD thumbnail - Trim requested, calling findTrimBoundsWithGD()');
            $trimBounds = $this->findTrimBoundsWithGD($sourceImage, $sourceType);
            if ($trimBounds) {
                Yii::info('GD thumbnail - Trim bounds found: ' . json_encode($trimBounds) . ' - Creating trimmed image');
                // Create a new image with the trimmed dimensions
                $trimmedImage = imagecreatetruecolor($trimBounds['width'], $trimBounds['height']);
                
                // Preserve transparency for formats that support it
                if ($sourceType == IMAGETYPE_PNG || $sourceType == IMAGETYPE_GIF || $sourceType == IMAGETYPE_WEBP) {
                    imagealphablending($trimmedImage, false);
                    imagesavealpha($trimmedImage, true);
                    $transparent = imagecolorallocatealpha($trimmedImage, 255, 255, 255, 127);
                    imagefilledrectangle($trimmedImage, 0, 0, $trimBounds['width'], $trimBounds['height'], $transparent);
                }
                
                // Copy the trimmed region with proper alpha handling
                imagealphablending($trimmedImage, false);
                imagesavealpha($trimmedImage, true);
                imagealphablending($sourceImage, false);
                imagesavealpha($sourceImage, true);
                Yii::info('GD thumbnail - Copying trimmed region from (' . $trimBounds['x'] . ', ' . $trimBounds['y'] . ') size ' . $trimBounds['width'] . 'x' . $trimBounds['height']);
                imagecopyresampled($trimmedImage, $sourceImage, 0, 0, $trimBounds['x'], $trimBounds['y'], $trimBounds['width'], $trimBounds['height'], $trimBounds['width'], $trimBounds['height']);
                
                // Replace source image with trimmed version
                imagedestroy($sourceImage);
                $sourceImage = $trimmedImage;
                $sourceWidth = $trimBounds['width'];
                $sourceHeight = $trimBounds['height'];
                Yii::info('GD thumbnail - Image trimmed successfully - New dimensions: ' . $sourceWidth . 'x' . $sourceHeight);
            } else {
                Yii::info('GD thumbnail - findTrimBoundsWithGD() returned false, no trimming performed');
            }
        } else {
            Yii::info('GD thumbnail - Trim not requested');
        }
        
        // Calculate thumbnail dimensions maintaining aspect ratio
        if ($height === null) {
            // Only width specified, calculate height to maintain aspect ratio
            $ratio = $sourceHeight / $sourceWidth;
            $height = (int)($width * $ratio);
        }
        
        // Create thumbnail image
        $thumbImage = imagecreatetruecolor($width, $height);
        
        // Preserve transparency for PNG and GIF
        if ($sourceType == IMAGETYPE_PNG || $sourceType == IMAGETYPE_GIF || $sourceType == IMAGETYPE_WEBP) {
            imagealphablending($thumbImage, false);
            imagesavealpha($thumbImage, true);
            $transparent = imagecolorallocatealpha($thumbImage, 255, 255, 255, 127);
            imagefilledrectangle($thumbImage, 0, 0, $width, $height, $transparent);
        }
        
        // Resize image with high quality
        Yii::info('GD thumbnail - Resizing from ' . $sourceWidth . 'x' . $sourceHeight . ' to ' . $width . 'x' . $height);
        imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        
        // Output to buffer
        Yii::info('GD thumbnail - Generating output blob in format: ' . $format);
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
                    imagejpeg($thumbImage, null, 90); // Fallback to JPEG
                }
                break;
            default:
                imagejpeg($thumbImage, null, 90); // Default to JPEG
        }
        $blob = ob_get_clean();
        Yii::info('GD thumbnail - Blob generated successfully - Size: ' . strlen($blob) . ' bytes');
        
        // Clean up
        imagedestroy($sourceImage);
        imagedestroy($thumbImage);
        
        return $blob;
    }

    public function behaviors()
    {
        return [
            'timestamp' => [
                'class' => TimestampBehavior::class,
                'value' => date('Y-m-d G:i:s'),
            ],
            'blameable' => [
                'class' => BlameableBehavior::class,
            ],
            'sluggable' => [
                'class' => SluggableBehavior::class,
                'attribute' => 'filename_user',
                'ensureUnique' => true,
                'immutable' => true,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['public', 'status'], 'default', 'value' => 0],
            [['position'], 'default', 'value' => 1000],
            [['download_count'], 'default', 'value' => 0],
            [['status'], 'default', 'value' => 0],

            [['tags', 'content'], 'safe'],
            [['public', 'position', 'status', 'download_count', 'created_by'], 'integer'],
            [['filename_path', 'filename_user', 'model', 'target_id', 'target_url', 'mimetype'], 'string'],
            [['checksum'], 'required'],
            [['checksum'], 'string', 'max' => 32],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('files', '#'),
            'created_by' => Yii::t('files', 'created by'),
            'updated_by' => Yii::t('files', 'updated by'),
            'created_at' => Yii::t('files', 'created at'),
            'updated_at' => Yii::t('files', 'updated at'),
            'public' => Yii::t('files', 'public'),
            'model' => Yii::t('files', 'model'),
            'target_id' => Yii::t('files', 'Target'),
            'target_url' => Yii::t('files', 'Target Url'),
            'filename_path' => Yii::t('files', 'filename_path'),
            'filename_user' => Yii::t('files', 'filename_user'),
            'mimetype' => Yii::t('files', 'File format'),
            'position' => Yii::t('files', 'Position'),
            'download_count' => Yii::t('files', 'Downloads'),
            'tags' => Yii::t('files', 'Tags'),
            'shared_with' => Yii::t('files', 'Shared with'),
            'display_shared_files' => Yii::t('files', 'Uploaded by'),
            'checksum' => Yii::t('files', 'Checksum'),
        ];
    }

    /**
     * When files are being deleted by the user, we first send it into a temporary trash bin.
     * Files inside this bin have the status STATUS_TRASHED. They can be restored by the
     * user anytime.
     *
     * When he empties his trash bin, the files inside it get the status STATUS_DELETED. They
     * can not be restored anymore and are never displayed.
     *
     * We still keep it in our database (soft delete).
     *
     * Ensure that files are also removed physically from the hard drive when the option
     * is set in the module configuration
     */
    public function delete()
    {
        if ($this->status == File::STATUS_NORMAL) {
            return $this->updateAttributes(['status' => File::STATUS_TRASHED]);
        }

        if ($this->status == File::STATUS_TRASHED) {
            return $this->updateAttributes(['status' => File::STATUS_DELETED]);
        }

    }

    /**
     * Restoration is only possible when the file has the status STATUS_TRASHED.
     * @return bool succeeded?
     */
    public function restore()
    {
        if ($this->status == File::STATUS_TRASHED) {
            $this->updateAttributes(['status' => File::STATUS_NORMAL]);
            return true;
        }

        return false;
    }

    public function addShareWith($username)
    {
        $recipient = User::find()->where(['username' => $username])->one();

        if (!$recipient) {
            throw new NotFoundHttpException(Yii::t('files', 'User can not be found'));
        }

        $sharedWith = $this->shared_with;
        $sharedWith[] = $username;
        $sharedWith = array_unique($sharedWith);

        $this->updateAttributes(['shared_with' => implode(', ', $sharedWith)]);

        $event = new ShareWithUserEvent;
        $event->sharedFrom = Yii::$app->user->identity;
        $event->sharedWith = $recipient;
        $event->sharedFile = $this;
        $event->add = 1;
        $this->trigger(self::EVENT_AFTER_SHARE_WITH_USER, $event);
    }

    public function removeShareWith($username)
    {
        $recipient = User::find()->where(['username' => $username])->one();

        if (!$recipient) {
            throw new NotFoundHttpException(Yii::t('files', 'User can not be found'));
        }

        $sharedWith = $this->shared_with;

        if (($key = array_search($username, $sharedWith)) !== false) {
            unset($sharedWith[$key]);
        }

        $sharedWith = array_unique($sharedWith);

        $this->updateAttributes(['shared_with' => implode(', ', $sharedWith)]);

        $event = new ShareWithUserEvent;
        $event->sharedFrom = Yii::$app->user->identity;
        $event->sharedWith = $recipient;
        $event->sharedFile = $this;
        $event->add = 0;
        $this->trigger(self::EVENT_AFTER_SHARE_WITH_USER, $event);
    }

    /**
     * Deserialize shared_with column
     */
    public function afterFind()
    {
        $this->shared_with = explode(', ', $this->shared_with);
        $this->tags = explode(', ', $this->tags);
        return parent::afterFind();
    }

    public function beforeValidate()
    {
        $this->handleSerializableFields();
        $this->createChecksum();
        return parent::beforeValidate();
    }

    public function getTagsFormatted()
    {
        if (!is_array($this->tags)) {
            $this->tags = explode(', ', $this->tags);
        }

        $output = '';
        foreach ($this->tags as $tag) {
            $output .= Yii::t('app', Yii::$app->getModule('files')->possibleTags[$tag] ?? $tag) . ', ';
        }

        if ($output) {
            $output = substr($output, 0, -2);
        }

        return $output;
    }

    /**
     * @return bool if the file is valid; always true if the check is being skipped
     */
    public function proofChecksum()
    {
        if (Yii::$app->getModule('files')->skipChecksumIntegrity) {
            return true;
        }

        return $this->checksum == md5(file_get_contents($this->filename_path));
    }

    /**
     * Create the checksum only once when uploading the file. Never ever change it afterwards.
     */
    protected function createChecksum()
    {
        if (!$this->checksum) {
            $this->checksum = md5($this->content);
        }
    }

    protected function handleSerializableFields()
    {
        if (is_array($this->shared_with)) {
            $this->shared_with = implode(', ', $this->shared_with);
        }

        if (is_array($this->tags)) {
            $this->tags = implode(', ', $this->tags);
        }

        if( $this->content && !$this->filename_path)
        {
            // we don't' have this file in our directory yet
            $parts = explode('.', basename($this->filename_user));
            $ext = end($parts);
            $name = implode(".", array_slice($parts,0,-1));
            $uniq = uniqid($name."-");
            $target = Yii::$app->getModule('files')->uploadPath . "/{$uniq}.{$ext}";
            file_put_contents($target,$this->content);
            $this->filename_path = $target;
        }

    }

    /**
     * serialize shared_with column
     */
    public function beforeDelete()
    {
        $this->handleSerializableFields();

        return parent::beforeDelete();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getOwner()
    {
        return $this->hasOne(Yii::$app->getModule('files')->userModelClass, ['id' => 'created_by']);
    }

    /**
     * Take all tags that are defined in Yii::$app->getModule('files')->possibleTags and translate the values.
     * @return array the translated tags
     */
    public static function possibleTagsTranslated()
    {
        $tags = [];
        $possibleTags = Yii::$app->getModule('files')->possibleTags ?? [];
        
        // Handle both numeric-indexed arrays and associative arrays
        foreach ($possibleTags as $key => $value) {
            // If key is numeric, use the value as both key and label
            if (is_numeric($key)) {
                $tagKey = $value;
                $tagLabel = $value;
            } else {
                // If key is string, use key as tag value and value as label
                $tagKey = $key;
                $tagLabel = $value;
            }
            $tags[$tagKey] = Yii::t('app', $tagLabel);
        }

        return $tags;
    }

    /**
     * identifierAttribute is necessary e.g. for cases where the target model gets referenced by slug
     * @return \yii\db\ActiveQuery
     */
    public function getTarget()
    {
        if (!$this->model) {
            return null;
        }

        $targetClass = $this->model;

        $target = new $targetClass;

        $identifier_attribute = 'id';

        if (method_exists($target, 'identifierAttribute')) {
            $identifier_attribute = $target->identifierAttribute();
        }
        if(method_exists($target, 'primaryKey'))
        {
            $identifier_attribute = current($target->primaryKey());
        }

        return $this->hasOne($targetClass::className(), [$identifier_attribute => 'target_id']);
    }

    public function reassign($model, $makePublic = true)
    {
        $this->model = get_class($model);
        $this->target_id = (string) $model->primaryKey;
        if(!$this->public && $makePublic)
        {
            $this->public = 1;
        }
        if(!$this->save())
        {
            Yii::error($this->getErrors());
            return false;
        }
        return true;
    }

    public function isDeleteable()
    {
        $allowDeletion = Yii::$app->getModule('files')->allowDeletion;

        if ($allowDeletion === true) {
            return true;
        }

        if ($allowDeletion === false) {
            return false;
        }

        if (is_callable($allowDeletion)) {
            return call_user_func($allowDeletion, $this) === true;
        }

        return $allowDeletion;
    }

    /**
     * Checks the mimeType of the $file against the list in the [[mimeTypes]] property.
     * borrowed from: https://github.com/yiisoft/yii2/blob/master/framework/validators/FileValidator.php#L479
     *
     * @param string $tempName
     * @return bool whether the $file mimeType is allowed
     * @throws \yii\base\InvalidConfigException
     */
    public static function validateMimeType($tempName, $mimeTypes)
    {
        $fileMimeType = FileHelper::getMimeType($tempName);
        if(!$fileMimeType)
        {
            return null;
        }
        foreach ($mimeTypes as $mimeType) {
            if ($mimeType === $fileMimeType) {
                return true;
            }

            $regexp =  '/^' . str_replace('\*', '.*', preg_quote($mimeType, '/')) . '$/';

            if (strpos($mimeType, '*') !== false && preg_match($regexp, $fileMimeType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove all files that are in the trash bin permanently.
     *
     * @param $user_id
     * @return int
     */
    public static function emptyTrashBin($user_id)
    {
        foreach (File::find()->where([
            'status' => File::STATUS_TRASHED,
            'created_by' => $user_id,
        ])->all() as $file) {
            $file->delete();
        }
    }
}
