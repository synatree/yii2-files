<?php

namespace thyseus\files\console;


use \Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use thyseus\files\models\File;

/**
 * Console command to clean up trashed files by unlinking them from the filesystem
 * and updating their status to STATUS_DELETED.
 * 
 * Usage:
 *   php yii files/cleanup-trashed-files [options]
 * 
 * Options:
 *   --dry-run    Show what would be deleted without actually deleting
 *   --force      Also delete files that are already STATUS_DELETED but still exist on disk
 */
class CleanupTrashedFilesCommand extends Controller
{
    /**
     * @var bool Dry run mode - show what would be deleted without actually deleting
     */
    public $dryRun = false;
    
    /**
     * @var bool Force mode - also delete files that are already STATUS_DELETED but still exist
     */
    public $force = false;

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(
            parent::options($actionID),
            ['dry-run', 'force']
        );
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'd' => 'dry-run',
            'f' => 'force',
        ]);
    }

    /**
     * Clean up trashed files by unlinking them from the filesystem
     * 
     * @return int Exit code
     */
    public function actionIndex()
    {
        $this->stdout("Starting cleanup of trashed files...\n", \yii\helpers\Console::FG_YELLOW);
        
        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No files will actually be deleted\n", \yii\helpers\Console::FG_CYAN);
        }

        // Find all trashed files
        $query = File::find()->where(['status' => File::STATUS_TRASHED]);
        $trashedFiles = $query->all();
        
        $this->stdout("Found " . count($trashedFiles) . " trashed file(s)\n", \yii\helpers\Console::FG_YELLOW);

        $deletedCount = 0;
        $errorCount = 0;
        $notFoundCount = 0;
        $alreadyDeletedCount = 0;

        foreach ($trashedFiles as $file) {
            $this->processFile($file, $deletedCount, $errorCount, $notFoundCount, $alreadyDeletedCount);
        }

        // If force mode, also clean up files that are already STATUS_DELETED but still exist
        if ($this->force) {
            $this->stdout("\nChecking for files with STATUS_DELETED that still exist on disk...\n", \yii\helpers\Console::FG_YELLOW);
            
            $deletedFiles = File::find()->where(['status' => File::STATUS_DELETED])->all();
            $this->stdout("Found " . count($deletedFiles) . " deleted file record(s)\n", \yii\helpers\Console::FG_YELLOW);
            
            foreach ($deletedFiles as $file) {
                if (!empty($file->filename_path) && file_exists($file->filename_path)) {
                    $this->stdout("  - File still exists on disk: {$file->filename_path} (ID: {$file->id})\n", \yii\helpers\Console::FG_RED);
                    
                    if (!$this->dryRun) {
                        if (@unlink($file->filename_path)) {
                            $this->stdout("    ✓ Unlinked successfully\n", \yii\helpers\Console::FG_GREEN);
                            $deletedCount++;
                        } else {
                            $this->stdout("    ✗ Failed to unlink: " . error_get_last()['message'] . "\n", \yii\helpers\Console::FG_RED);
                            $errorCount++;
                        }
                    } else {
                        $this->stdout("    [DRY RUN] Would unlink\n", \yii\helpers\Console::FG_CYAN);
                        $deletedCount++;
                    }
                }
            }
        }

        // Summary
        $this->stdout("\n" . str_repeat("=", 50) . "\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout("Summary:\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout("  Files processed: " . count($trashedFiles) . "\n");
        $this->stdout("  Files " . ($this->dryRun ? "that would be " : "") . "deleted: {$deletedCount}\n", \yii\helpers\Console::FG_GREEN);
        
        if ($notFoundCount > 0) {
            $this->stdout("  Files not found on disk: {$notFoundCount}\n", \yii\helpers\Console::FG_YELLOW);
        }
        
        if ($errorCount > 0) {
            $this->stdout("  Errors: {$errorCount}\n", \yii\helpers\Console::FG_RED);
        }
        
        if ($this->dryRun) {
            $this->stdout("\nThis was a dry run. Run without --dry-run to actually delete files.\n", \yii\helpers\Console::FG_CYAN);
        } else {
            $this->stdout("\nCleanup completed successfully.\n", \yii\helpers\Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Process a single file
     * 
     * @param File $file
     * @param int $deletedCount Reference to deleted count
     * @param int $errorCount Reference to error count
     * @param int $notFoundCount Reference to not found count
     * @param int $alreadyDeletedCount Reference to already deleted count
     */
    protected function processFile($file, &$deletedCount, &$errorCount, &$notFoundCount, &$alreadyDeletedCount)
    {
        $filename = $file->filename_user ?? "file #{$file->id}";
        $this->stdout("Processing: {$filename} (ID: {$file->id})\n");

        // Check if file exists on disk
        if (empty($file->filename_path)) {
            $this->stdout("  - No filename_path set, skipping\n", \yii\helpers\Console::FG_YELLOW);
            $notFoundCount++;
            return;
        }

        if (!file_exists($file->filename_path)) {
            $this->stdout("  - File not found on disk: {$file->filename_path}\n", \yii\helpers\Console::FG_YELLOW);
            $notFoundCount++;
            
            // Update status to DELETED even if file doesn't exist
            if (!$this->dryRun) {
                $file->updateAttributes(['status' => File::STATUS_DELETED]);
                $this->stdout("    ✓ Updated status to DELETED\n", \yii\helpers\Console::FG_GREEN);
            }
            return;
        }

        // Unlink the file
        if (!$this->dryRun) {
            if (@unlink($file->filename_path)) {
                $this->stdout("  ✓ Unlinked: {$file->filename_path}\n", \yii\helpers\Console::FG_GREEN);
                
                // Also try to delete thumbnail if it exists
                $this->deleteThumbnail($file);
                
                // Update status to DELETED
                $file->updateAttributes(['status' => File::STATUS_DELETED]);
                $this->stdout("  ✓ Updated status to DELETED\n", \yii\helpers\Console::FG_GREEN);
                $deletedCount++;
            } else {
                $error = error_get_last();
                $errorMsg = $error ? $error['message'] : 'Unknown error';
                $this->stdout("  ✗ Failed to unlink: {$errorMsg}\n", \yii\helpers\Console::FG_RED);
                $errorCount++;
            }
        } else {
            $this->stdout("  [DRY RUN] Would unlink: {$file->filename_path}\n", \yii\helpers\Console::FG_CYAN);
            $this->stdout("  [DRY RUN] Would update status to DELETED\n", \yii\helpers\Console::FG_CYAN);
            $deletedCount++;
        }
    }

    /**
     * Delete thumbnail files if they exist
     * 
     * @param File $file
     */
    protected function deleteThumbnail($file)
    {
        try {
            $filesModule = \thyseus\files\FileWebModule::getInstance() ?? Yii::$app->getModule('files');
            $uploadPath = $filesModule->uploadPath;
            
            // Resolve alias if needed
            if (strpos($uploadPath, '@') === 0) {
                $uploadPath = Yii::getAlias($uploadPath);
            }
            
            $thumbnailsDir = $uploadPath . '/thumbnails';
            
            if (is_dir($thumbnailsDir)) {
                // Find all thumbnails for this file
                // Thumbnail cache keys are generated as: md5($this->id . '_' . $this->checksum . '_' . ($width ?? 'auto') . '_' . ($height ?? 'auto') . '_' . $format . '_' . ($trim ? 'trim' : 'notrim'))
                // So we need to search for files starting with the base hash
                $baseHash = md5($file->id . '_' . $file->checksum . '_');
                $pattern = $thumbnailsDir . '/' . $baseHash . '*';
                $thumbnails = glob($pattern);
                
                if (!empty($thumbnails)) {
                    foreach ($thumbnails as $thumbnail) {
                        if (file_exists($thumbnail)) {
                            if (@unlink($thumbnail)) {
                                $this->stdout("  ✓ Deleted thumbnail: " . basename($thumbnail) . "\n", \yii\helpers\Console::FG_GREEN);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail thumbnail deletion - it's not critical
            $this->stdout("  - Could not delete thumbnails: " . $e->getMessage() . "\n", \yii\helpers\Console::FG_YELLOW);
        }
    }
}

