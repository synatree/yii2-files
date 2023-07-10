<?php

use kartik\file\FileInput;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use thyseus\files\models\File;
use thyseus\files\FileWebModule;
$module = FileWebModule::getInstance();

if (!isset($pluginOptions)) {
    $pluginOptions = [];
}

if (!isset($pluginOptions['uploadUrl'])) {
    $pluginOptions['uploadUrl'] = File::absUrl($module->uploadUrl);
}

if (!isset($target_id) && isset($model)) {
    $target_id = method_exists($model, 'identifierAttribute') ? $model->{$model->identifierAttribute()} : $model->id;
}

if (isset($model)) {
    $pluginOptions = ArrayHelper::merge($pluginOptions, [
        'uploadExtraData' => [
            'target_url' => isset($target_url) ? $target_url : '',
            'model' => $model::className(),
            'attribute' => isset($_POST['attribute']) ? $_POST['attribute'] : '',
            'target_id' => $target_id,
        ]
    ]);

}

if (isset($options) && isset($options['accept'])) {
    $pluginOptions['uploadExtraData']['allowed_mime_types'] = $options['accept'];
}

echo FileInput::widget([
    'name' => 'files',
    'options' => isset($options) ? $options : [],
    'pluginOptions' => $pluginOptions,
    'pluginEvents' => isset($pluginEvents) ? $pluginEvents : [],
]);
