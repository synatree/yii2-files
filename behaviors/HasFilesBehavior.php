<?php
namespace thyseus\files\behaviors;

use thyseus\files\models\File;
use Yii;
use yii\base\Behavior;

class HasFilesBehavior extends Behavior
{
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

        return $this->owner->hasMany(File::class, ['target_id' => $identifierAttribute])->andWhere(['status' => File::STATUS_NORMAL])->orderBy('position ASC');
    }

    public function attachFile($fileOptions = []) {
        $attr = $this->getIdentifierAttribute();
        $file = Yii::createObject([
            'class' => File::class,
            'attributes' => [
                'content' => $fileOptions['content'] ?? null,
                'filename_user' => $fileOptions['name'] ?? null,
                'created_by' => Yii::$app->user->id ?? null,
                'filename_path' => $fileOptions['path'] ?? null,
                'mimetype' => $fileOptions['type'] ?? null,
                'model' => $this->owner::className(),
                'target_id' => (string) $this->owner->$attr,
                'target_url' => $fileOptions['target_url']??'',
                'public' => 0,
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

        return $this->owner
            ->hasMany(File::class, ['target_id' => $identifierAttribute])
            ->andWhere(['like', 'files.tags', $tag])
            ->andWhere(['status' => File::STATUS_NORMAL])
            ->orderBy('position ASC')
            ->all();
    }
}
