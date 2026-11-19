<?php

namespace yii\db\iotdb;

/** Column metadata including the IoTDB Table column category. */
class ColumnSchema extends \yii\db\ColumnSchema
{
    /** @var string TIME, TAG, ATTRIBUTE, or FIELD */
    public $category;
}
