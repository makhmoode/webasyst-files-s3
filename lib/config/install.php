<?php

$model = new waModel();
if (method_exists($model, 'createSchema')) {
    $schema = include wa()->getAppPath('plugins/s3/lib/config/db.php', 'files');
    foreach ($schema as $table => $definition) {
        try {
            $model->query("SELECT 1 FROM {$table} WHERE 0");
        } catch (Exception $e) {
            $model->createSchema(array($table => $definition));
        }
    }
}
