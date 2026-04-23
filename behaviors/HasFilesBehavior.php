<?php
namespace thyseus\files\behaviors;


use \Yii;
use thyseus\files\models\File;
use yii\base\Behavior;

class HasFilesBehavior extends Behavior
{
    /**
     * Base model class name to use for file lookups.
     * If set, files will be queried using this class name instead of the actual owner class.
     * This is useful when models extend a base class (e.g., IssuerUi extends Issuer)
     * but files are attached to the base class.
     * 
     * @var string|null
     */
    public $baseModelClass = null;

    /**
     * Attaches an relation 'files' to the owner model that retrieves all files.
     *
     * @return yii\db\ActiveQuery
     */
    private $_attr;
    public function getIdentifierAttribute()
    {
        if (! $this->_attr) {
            if (method_exists($this->owner, 'identifierAttribute')) {
                $this->_attr = $this->owner->identifierAttribute();

            }
            else
            {
                $cls = get_class($this->owner);
                $schema = call_user_func([$cls, 'getTableSchema']);
                $this->_attr = $schema->primaryKey[0];
            }
            
        }
        return $this->_attr;
    }
    public function getFiles()
    {
        $identifierAttribute = $this->getIdentifierAttribute();
        $modelClass = $this->baseModelClass ?? $this->owner::class;

        return $this->owner
            ->hasMany(File::class, ['target_id' => $identifierAttribute])
            ->andWhere(['model' => $modelClass])
            ->andWhere(['status' => File::STATUS_NORMAL])
            ->orderBy('position ASC');
    }

    public function attachFile($fileOptions = []) {
        $attr = $this->getIdentifierAttribute();
        $modelClass = $this->baseModelClass ?? $this->owner::className();
        $file = Yii::createObject([
            'class' => File::class,
            'attributes' => [
                'content' => $fileOptions['content'] ?? null,
                'filename_user' => $fileOptions['name'] ?? null,
                'created_by' => Yii::$app->user->id ?? null,
                'filename_path' => $fileOptions['path'] ?? null,
                'mimetype' => $fileOptions['type'] ?? null,
                'model' => $modelClass,
                'target_id' => (string) $this->owner->$attr,
                'target_url' => $fileOptions['target_url']??'',
                'public' => $fileOptions['public'] ?? 0,
                'tags' => $fileOptions['tags']?? '',
                'status' => 0,
            ],
        ]);

        if(!($success = $file->save()))
        {
            Yii::error( $file->getErrors() );
            return null;
        }
        return $file;
    }

    /**
     * Attaches an relation 'filesPublic' to the owner model that retrieves all public files.
     *
     * @return yii\db\ActiveQuery
     */
    public function getFilesPublic()
    {
        $identifierAttribute = $this->getIdentifierAttribute();

        return $this->owner->hasMany(File::class,
            ['target_id' => $identifierAttribute])
            ->andWhere(['files.public' => 1])
            ->orderBy('position ASC');
    }

    /**
     * Attaches an relation 'filesProtected' to the owner model that retrieves all protected files.
     *
     * @return yii\db\ActiveQuery
     */
    public function getFilesProtected()
    {
        $identifierAttribute = $this->getIdentifierAttribute();

        return $this->owner->hasMany(File::class,
            ['target_id' => $identifierAttribute])
            ->andWhere(['files.public' => 0])
            ->orderBy('position ASC');
    }

    /**
     * Attaches an method 'filesFromUser(<id>)' to the owner model that retrieves all files that are owned by this user.
     *
     * @return array list of found files
     */
    public function filesFromUser($id)
    {
        $identifierAttribute = $this->getIdentifierAttribute();

        return $this->owner
            ->hasMany(File::class, ['target_id' => $identifierAttribute])
            ->andWhere(['files.created_by' => $id])
            ->orderBy('position ASC')
            ->all();
    }

    /**
     * Attaches an method 'filesWithTag(<tag>)' to the owner model that retrieves all files that are tagged with the given tag.
     *
     * @return array list of found files
     */
    public function filesWithTag($tag)
    {
        $identifierAttribute = $this->getIdentifierAttribute();
        $modelClass = $this->baseModelClass ?? $this->owner::class;

        return $this->owner
            ->hasMany(File::class, ['target_id' => $identifierAttribute])
            ->andWhere(['model' => $modelClass])
            ->andWhere(['like', 'files.tags', $tag])
            ->andWhere(['status' => File::STATUS_NORMAL])
            ->orderBy('position ASC')
            ->all();
    }

    public function reassignAllFiles($destination, $makePublic = true){
        foreach($this->owner->files as $fileModel)
        {
            $fileModel->reassign($destination, $makePublic);
            
        }
    }
}
