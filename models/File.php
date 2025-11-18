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
        if (!$this->isImage()) {
            return $this->downloadUrl();
        }

        // Generate cache key based on file properties and parameters
        $cacheKey = md5($this->id . '_' . $this->checksum . '_' . ($width ?? 'auto') . '_' . ($height ?? 'auto') . '_' . $format . '_' . ($trim ? 'trim' : 'notrim'));
        $cacheDir = Yii::$app->getModule('files')->uploadPath . '/thumbnails';
        $cacheFile = $cacheDir . '/' . $cacheKey . $format;
        
        // Create thumbnails directory if it doesn't exist
        if (!is_dir($cacheDir)) {
            \yii\helpers\FileHelper::createDirectory($cacheDir, 0755, true);
        }
        
        // Generate thumbnail if it doesn't exist
        if (!file_exists($cacheFile)) {
            $this->generateThumbnail($width, $height, $format, $trim, $cacheFile);
        }
        
        // Return URL to the cached thumbnail via download action with thumbnail parameter
        return self::absUrl(['//files/file/download', 'id' => $this->slug, 'thumbnail' => $cacheKey . $format]);
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
        try {
            // Use the existing inline logic but save to file instead of returning base64
            if ($this->isImage()) {
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
                            $args = array_filter([$width ?? 480, $height ? ['height' => $height] : null]);
                            if (!empty($args)) {
                                $thumb = $image->thumbnail(...$args);
                            } else {
                                $thumb = $image;
                            }
                            
                            // Save to file
                            $thumb->writeToFile($outputPath);
                            return true;
                        }
                    } catch (\Exception $e) {
                        // Fallback to GD if VIPS fails
                        if (extension_loaded('gd')) {
                            return $this->generateThumbnailWithGD($width, $height, $format, $trim, $outputPath);
                        }
                    }
                } elseif (extension_loaded('gd')) {
                    return $this->generateThumbnailWithGD($width, $height, $format, $trim, $outputPath);
                }
            }
            return false;
        } catch (\Exception $e) {
            Yii::error('Failed to generate thumbnail: ' . $e->getMessage());
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
        $blob = $this->createThumbnailWithGD($width ?? 480, $height, $format, $trim);
        if ($blob) {
            return file_put_contents($outputPath, $blob) !== false;
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

    public function inline($w=null, $h=null, $format='.jpg', $mime='image/jpeg', $trim=false)
    {
        if($this->isImage())
        {
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
                        $blob = $this->createThumbnailWithGD($w ?? 480, $h, $format, $trim);
                    } else {
                        // No image processing extension available, return original
                        $blob = file_get_contents($this->filename_path);
                        $mime = $this->mimetype;
                    }
                }
            } elseif (extension_loaded('gd')) {
                // Fallback to GD if VIPS is not available
                $blob = $this->createThumbnailWithGD($w ?? 480, $h, $format, $trim);
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
        try {
            $width = $image->width;
            $height = $image->height;
            
            // Get the upper-left pixel color
            // Extract a 1x1 pixel from the upper-left corner
            $sample = $image->crop(0, 0, 1, 1);
            $pixelData = $sample->writeToArray();
            
            // Get RGB values from the pixel (assuming RGB or RGBA format)
            $backgroundR = $pixelData[0][0][0] ?? 0;
            $backgroundG = $pixelData[0][0][1] ?? 0;
            $backgroundB = $pixelData[0][0][2] ?? 0;
            $hasAlpha = isset($pixelData[0][0][3]);
            $backgroundA = $hasAlpha ? ($pixelData[0][0][3] ?? 255) : 255;
            
            // Convert image to array to scan pixels
            $imageData = $image->writeToArray();
            $channels = count($imageData);
            
            $minX = $width;
            $minY = $height;
            $maxX = -1;
            $maxY = -1;
            
            // Scan all pixels to find bounding box of pixels that differ from the background
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
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
                        if ($x < $minX) $minX = $x;
                        if ($x > $maxX) $maxX = $x;
                        if ($y < $minY) $minY = $y;
                        if ($y > $maxY) $maxY = $y;
                    }
                }
            }
            
            // If no different pixels found or same dimensions, return original
            if ($maxX < $minX || $maxY < $minY) {
                return $image;
            }
            
            $trimWidth = $maxX - $minX + 1;
            $trimHeight = $maxY - $minY + 1;
            
            // If the trim area is the same as the original, no trimming needed
            if ($minX == 0 && $minY == 0 && $trimWidth == $width && $trimHeight == $height) {
                return $image;
            }
            
            // Crop to the trimmed bounding box
            return $image->crop($minX, $minY, $trimWidth, $trimHeight);
        } catch (\Exception $e) {
            // If trimming fails, return original image
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
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Check if image supports transparency (PNG, GIF, WebP)
        // Note: We assume the image is already converted to truecolor if needed
        $hasAlpha = ($sourceType == IMAGETYPE_PNG || $sourceType == IMAGETYPE_GIF || $sourceType == IMAGETYPE_WEBP);
        
        // Get the color of the upper-left pixel (background color to trim)
        $backgroundColor = imagecolorat($image, 0, 0);
        
        // Extract alpha component if image supports transparency
        // In GD, alpha is in bits 24-31: 0 = opaque, 127 = fully transparent
        $backgroundAlpha = null;
        if ($hasAlpha && imageistruecolor($image)) {
            $backgroundAlpha = ($backgroundColor >> 24) & 0xFF;
        }
        
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        
        // Scan all pixels to find bounding box of pixels that differ from the background
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixelColor = imagecolorat($image, $x, $y);
                $shouldInclude = false;
                
                if ($hasAlpha && $backgroundAlpha !== null && imageistruecolor($image)) {
                    // For images with alpha channel, extract pixel alpha
                    $pixelAlpha = ($pixelColor >> 24) & 0xFF;
                    
                    // If background is transparent (alpha >= 127), trim all transparent pixels
                    // If background has color, trim pixels matching that exact color+alpha
                    if ($backgroundAlpha >= 127) {
                        // Background is transparent - include any non-transparent pixel
                        if ($pixelAlpha < 127) {
                            $shouldInclude = true;
                        }
                    } else {
                        // Background has color - include pixels that differ in color OR alpha
                        // Compare full 32-bit value to account for both RGB and alpha
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
                    if ($x < $minX) $minX = $x;
                    if ($x > $maxX) $maxX = $x;
                    if ($y < $minY) $minY = $y;
                    if ($y > $maxY) $maxY = $y;
                }
            }
        }
        
        // If no different pixels found, return false
        if ($maxX < $minX || $maxY < $minY) {
            return false;
        }
        
        $trimWidth = $maxX - $minX + 1;
        $trimHeight = $maxY - $minY + 1;
        
        // If the trim area is the same as the original, no trimming needed
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
        $sourcePath = $this->filename_path;
        
        // Determine image type and load source image
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            throw new \Exception('Unable to get image size');
        }
        
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $sourceType = $imageInfo[2];
        
        // Load source image based on type
        switch ($sourceType) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                // Convert palette PNG to truecolor to preserve alpha channel
                if (!imageistruecolor($sourceImage)) {
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
                } else {
                    // Ensure alpha channel is preserved for truecolor images
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                }
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($sourcePath);
                // Convert GIF to truecolor to handle transparency properly
                if (!imageistruecolor($sourceImage)) {
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
                } else {
                    // Ensure alpha channel is preserved for truecolor images
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                }
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $sourceImage = imagecreatefromwebp($sourcePath);
                    // Ensure alpha channel is preserved
                    imagealphablending($sourceImage, false);
                    imagesavealpha($sourceImage, true);
                } else {
                    throw new \Exception('WebP format not supported by GD');
                }
                break;
            default:
                throw new \Exception('Unsupported image format');
        }
        
        if (!$sourceImage) {
            throw new \Exception('Failed to load source image');
        }
        
        // Trim based on upper-left pixel color if requested
        $trimBounds = null;
        if ($trim) {
            $trimBounds = $this->findTrimBoundsWithGD($sourceImage, $sourceType);
            if ($trimBounds) {
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
                imagecopyresampled($trimmedImage, $sourceImage, 0, 0, $trimBounds['x'], $trimBounds['y'], $trimBounds['width'], $trimBounds['height'], $trimBounds['width'], $trimBounds['height']);
                
                // Replace source image with trimmed version
                imagedestroy($sourceImage);
                $sourceImage = $trimmedImage;
                $sourceWidth = $trimBounds['width'];
                $sourceHeight = $trimBounds['height'];
            }
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
        imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        
        // Output to buffer
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
