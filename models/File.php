<?php

namespace thyseus\files\models;


use \Yii;
use thyseus\files\events\ShareWithUserEvent;
use yii\web\NotFoundHttpException;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\SluggableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use thyseus\files\FileWebModule;
use thyseus\files\services\DocumentPreviewService;
use thyseus\files\services\ImageProcessor;

/**
 * Model class for table "file".
 *
 * @property string $id
 * @property int $created_by
 * @property string $filename_path
 * @property string $filename_user
 * @property string $slug
 * @property string $mimetype
 * @property string $checksum
 * @property string $model
 * @property string $target_id
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
            $innerHtml = '<span class="bi bi-download" aria-hidden="true"></span> ' . Yii::t('app', 'Download');
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

        // SVG files are scalable - return inline data URI; no thumbnail file
        if ($this->isSvg()) {
            return $this->inline(null, null, '.svg', 'image/svg+xml', false);
        }

        $cacheKey = $this->getThumbnailCacheKey($width, $height, $format, $trim);
        $cacheDir = $this->getThumbnailsDir();
        $cacheFile = $cacheDir . '/' . $cacheKey . $format;

        if (!is_dir($cacheDir)) {
            $created = FileHelper::createDirectory($cacheDir, 0755, true);
            if (!$created) {
                Yii::error('Failed to create thumbnails directory: ' . $cacheDir);
            }
        }

        if (!file_exists($cacheFile)) {
            $success = $this->generateThumbnail($width, $height, $format, $trim, $cacheFile);
            if (!$success || !file_exists($cacheFile)) {
                Yii::error('Failed to generate thumbnail for file: ' . $this->id . ' - Cache file: ' . $cacheFile);
                return $this->downloadUrl();
            }
        }

        return self::absUrl(['//files/file/download', 'id' => $this->slug, 'thumbnail' => $cacheKey . $format]);
    }

    /**
     * Get filesystem path to a thumbnail with the given max dimensions, generating it if needed.
     * Used by the download action to serve thumbnails when maxWidth/maxHeight are requested.
     *
     * @param int|null $maxWidth Maximum width (maintains aspect ratio)
     * @param int|null $maxHeight Maximum height (maintains aspect ratio)
     * @param string $format Output format (e.g. '.png', '.jpg')
     * @param bool $trim Whether to trim based on upper-left pixel color
     * @return string|null Full path to the thumbnail file, or null if not an image or generation failed
     */
    public function getThumbnailPath($maxWidth = null, $maxHeight = null, $format = '.png', $trim = false)
    {
        if (!$this->isImage()) {
            return null;
        }
        if ($this->isSvg()) {
            return null; // SVG is served as original; no thumbnail file
        }
        $cacheKey = $this->getThumbnailCacheKey($maxWidth, $maxHeight, $format, $trim);
        $cacheDir = $this->getThumbnailsDir();
        $cacheFile = $cacheDir . '/' . $cacheKey . $format;
        if (!is_dir($cacheDir)) {
            FileHelper::createDirectory($cacheDir, 0755, true);
        }
        if (!file_exists($cacheFile)) {
            $success = $this->generateThumbnail($maxWidth, $maxHeight, $format, $trim, $cacheFile);
            if (!$success || !file_exists($cacheFile)) {
                return null;
            }
        }
        return $cacheFile;
    }

    /**
     * Cache key for a thumbnail (without format extension). Used by thumbnailUrl() and getThumbnailPath().
     * @param int|null $width
     * @param int|null $height
     * @param string $format
     * @param bool $trim
     * @return string 32-char md5 hash
     */
    protected function getThumbnailCacheKey($width, $height, $format, $trim)
    {
        return md5($this->id . '_' . $this->checksum . '_' . ($width ?? 'auto') . '_' . ($height ?? 'auto') . '_' . $format . '_' . ($trim ? 'trim' : 'notrim'));
    }

    /**
     * Resolved thumbnails directory path (upload path + /thumbnails, alias resolved).
     * @return string
     */
    protected function getThumbnailsDir()
    {
        $uploadPath = Yii::$app->getModule('files')->uploadPath;
        if (strpos($uploadPath, '@') === 0) {
            $uploadPath = Yii::getAlias($uploadPath);
        }
        return $uploadPath . '/thumbnails';
    }

    /**
     * Check whether a thumbnail filename (e.g. from download URL) belongs to this file.
     * Used to avoid serving a cached thumbnail from a different file (e.g. after delete + reattach).
     *
     * @param string $thumbnailBasename Thumbnail filename (e.g. "abc123.png")
     * @return bool True if the thumbnail was generated for this file's id+checksum
     */
    public function isThumbnailKeyForThisFile($thumbnailBasename)
    {
        $format = pathinfo($thumbnailBasename, PATHINFO_EXTENSION);
        $ext = $format ? '.' . $format : '.png';
        $hashPart = substr($thumbnailBasename, 0, strlen($thumbnailBasename) - strlen($ext));
        if (strlen($hashPart) !== 32) {
            return false;
        }
        $keyNotrim = $this->getThumbnailCacheKey(null, null, $ext, false);
        $keyTrim = $this->getThumbnailCacheKey(null, null, $ext, true);
        return $hashPart === $keyNotrim || $hashPart === $keyTrim;
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
        $processor = new ImageProcessor($this->filename_path, $this->mimetype);
        return $processor->generateToFile($width, $height, $format, $trim, $outputPath);
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

    public function isPdf()
    {
        if ($this->mimetype === 'application/pdf') {
            return true;
        }

        $name = strtolower((string) $this->filename_user);

        return $name !== '' && substr($name, -4) === '.pdf';
    }

    /**
     * Whether the file binary exists on disk and can be read.
     */
    public function hasReadableBinary(): bool
    {
        $path = $this->filename_path;

        return $path !== null && $path !== '' && is_file($path) && is_readable($path);
    }

    /**
     * Whether a preview thumbnail can be generated or served.
     */
    public function isPreviewable(): bool
    {
        if (!$this->hasReadableBinary()) {
            return false;
        }

        return ($this->isImage() && !$this->isSvg()) || $this->isPdf();
    }

    /**
     * URL to a cached preview image (raster image thumbnail or PDF first page).
     */
    public function previewUrl(?int $maxWidth = null, ?int $maxHeight = null): string
    {
        $params = [
            '//files/file/download',
            'id' => $this->slug,
            'preview' => 1,
        ];
        if ($maxWidth !== null) {
            $params['maxWidth'] = $maxWidth;
        }
        if ($maxHeight !== null) {
            $params['maxHeight'] = $maxHeight;
        }

        return self::absUrl($params);
    }

    /**
     * Filesystem path to preview image, generating on demand.
     */
    public function getDocumentPreviewPath(?int $maxWidth = null, ?int $maxHeight = null, string $format = '.png'): ?string
    {
        return (new DocumentPreviewService())->getPreviewPath($this, $maxWidth, $maxHeight, $format);
    }

    /**
     * Check whether a preview cache filename belongs to this file.
     */
    public function isPreviewKeyForThisFile(string $previewBasename): bool
    {
        return (new DocumentPreviewService())->isPreviewKeyForFile($this, $previewBasename);
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
        if (!file_exists($this->filename_path) || !is_readable($this->filename_path)) {
            Yii::error('File::inline() - Source file does not exist or is not readable: ' . $this->filename_path . ' (File ID: ' . $this->id . ')');
            return null;
        }
        $processor = new ImageProcessor($this->filename_path, $this->mimetype);
        $dataUri = $processor->createDataUri($w, $h, $format, $mime, $trim);
        if ($dataUri !== null) {
            return $dataUri;
        }
        $blob = file_get_contents($this->filename_path);
        return 'data:' . $this->mimetype . ';base64,' . base64_encode($blob);
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
        $module = Yii::$app->getModule('files');
        $userClass = $module->userModelClass;
        $usernameAttr = $module->userUsernameAttribute ?? 'username';
        $recipient = $userClass::find()->where([$usernameAttr => $username])->one();

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
        $module = Yii::$app->getModule('files');
        $userClass = $module->userModelClass;
        $usernameAttr = $module->userUsernameAttribute ?? 'username';
        $recipient = $userClass::find()->where([$usernameAttr => $username])->one();

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
