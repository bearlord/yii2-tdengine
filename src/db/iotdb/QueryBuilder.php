<?php

namespace yii\db\iotdb;

use yii\base\InvalidArgumentException;
use yii\base\NotSupportedException;

/** Builds Yii queries and IoTDB Table DDL. */
class QueryBuilder extends \yii\db\QueryBuilder
{
    private $_typeMap = [
        'pk' => 'TIMESTAMP',
        'bigpk' => 'TIMESTAMP',
        'char' => 'STRING',
        'varchar' => 'STRING',
        'nvarchar' => 'STRING',
        'string' => 'STRING',
        'text' => 'STRING',
        'longtext' => 'STRING',
        'binary' => 'BLOB',
        'boolean' => 'BOOLEAN',
        'bool' => 'BOOLEAN',
        'tinyint' => 'INT32',
        'smallint' => 'INT32',
        'integer' => 'INT32',
        'int' => 'INT32',
        'bigint' => 'INT64',
        'float' => 'FLOAT',
        'double' => 'DOUBLE',
        'decimal' => 'DOUBLE',
        'numeric' => 'DOUBLE',
        'date' => 'DATE',
        'time' => 'TIMESTAMP',
        'datetime' => 'TIMESTAMP',
        'timestamp' => 'TIMESTAMP',
    ];

    /**
     * Converts Yii abstract types and validates native IoTDB types.
     * @param string $type
     * @return string
     */
    public function getColumnType($type)
    {
        $type = trim((string) $type);
        if (preg_match('/^([A-Za-z]+)(?:\s*\(\s*[0-9]+(?:\s*,\s*[0-9]+)?\s*\))?$/', $type, $matches)) {
            $type = $matches[1];
        }
        $lower = strtolower($type);
        if (isset($this->_typeMap[$lower])) {
            return $this->_typeMap[$lower];
        }
        if (preg_match('/^(BOOLEAN|INT32|INT64|FLOAT|DOUBLE|STRING|BLOB|TIMESTAMP|DATE)$/i', $type, $matches)) {
            return strtoupper($matches[1]);
        }
        throw new InvalidArgumentException("Unsupported IoTDB column type '$type'.");
    }

    /**
     * Creates an IoTDB Table table. Categories can be included in a type string
     * (for example `STRING TAG`) or supplied in $options['categories'].
     * Unclassified non-time columns default to FIELD.
     * @param string $table
     * @param array $columns
     * @param string|array|null $options
     * @return string
     */
    public function createTable($table, $columns, $options = null)
    {
        if (empty($columns)) {
            throw new InvalidArgumentException('IoTDB tables require at least one column.');
        }

        $categories = [];
        $suffix = '';
        if (is_array($options)) {
            if (isset($options['categories']) && is_array($options['categories'])) {
                $categories = $options['categories'];
            }
            foreach (['tags' => 'TAG', 'attributes' => 'ATTRIBUTE', 'fields' => 'FIELD'] as $key => $category) {
                if (isset($options[$key])) {
                    foreach ((array) $options[$key] as $columnName) {
                        $categories[$columnName] = $category;
                    }
                }
            }
            $suffix = isset($options['with']) ? trim($options['with']) : '';
        } elseif (is_string($options) && trim($options) !== '') {
            $suffix = trim($options);
        }

        $definitions = [];
        $timeCount = 0;
        foreach ($columns as $name => $definition) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('IoTDB CREATE TABLE requires named columns.');
            }
            list($type, $category) = $this->parseColumnDefinition($definition);
            if (isset($categories[$name])) {
                $category = strtoupper($categories[$name]);
            }
            if ($category === null) {
                $category = strtolower($name) === 'time' ? 'TIME' : 'FIELD';
            }
            if (!in_array($category, ['TIME', 'TAG', 'ATTRIBUTE', 'FIELD'], true)) {
                throw new InvalidArgumentException("Unsupported IoTDB column category '$category'.");
            }
            if ($category === 'TIME') {
                $timeCount++;
                if ($type !== 'TIMESTAMP') {
                    throw new InvalidArgumentException('The IoTDB TIME column must use TIMESTAMP type.');
                }
            }
            $definitions[] = "\t" . $this->db->quoteColumnName($name) . ' ' . $type . ' ' . $category;
        }
        if ($timeCount !== 1) {
            throw new InvalidArgumentException('IoTDB Table DDL requires exactly one TIMESTAMP TIME column.');
        }
        if ($suffix !== '' && !preg_match('/^WITH\s+/i', $suffix)) {
            throw new InvalidArgumentException('IoTDB table options must use the WITH (...) clause.');
        }

        $sql = 'CREATE TABLE ' . $this->db->quoteTableName($table)
            . " (\n" . implode(",\n", $definitions) . "\n)";
        return $suffix === '' ? $sql : $sql . ' ' . $suffix;
    }

    /**
     * @param string $table
     * @param string $column
     * @param string|array $type
     * @return string
     */
    public function addColumn($table, $column, $type)
    {
        list($nativeType, $category) = $this->parseColumnDefinition($type);
        if ($category === null) {
            $category = 'FIELD';
        }
        if (!in_array($category, ['TAG', 'ATTRIBUTE', 'FIELD'], true)) {
            throw new InvalidArgumentException('Adding a TIME column to an existing IoTDB table is unsupported.');
        }
        return 'ALTER TABLE ' . $this->db->quoteTableName($table)
            . ' ADD COLUMN ' . $this->db->quoteColumnName($column)
            . ' ' . $nativeType . ' ' . $category;
    }

    /**
     * @param string|array $definition
     * @return array native type and optional category
     */
    private function parseColumnDefinition($definition)
    {
        $category = null;
        if (is_array($definition)) {
            if (!isset($definition['type'])) {
                throw new InvalidArgumentException('IoTDB column definitions must include a type.');
            }
            $category = isset($definition['category']) ? strtoupper($definition['category']) : null;
            $definition = $definition['type'];
        }

        $definition = trim((string) $definition);
        if (preg_match('/^(.*?)\s+(TIME|TAG|ATTRIBUTE|FIELD)$/i', $definition, $matches)) {
            $definition = trim($matches[1]);
            $category = strtoupper($matches[2]);
        }
        return [$this->getColumnType($definition), $category];
    }

    public function update($table, $columns, $condition, &$params)
    {
        throw new NotSupportedException('IoTDB Table REST does not support Yii UPDATE operations.');
    }

    public function upsert($table, $insertColumns, $updateColumns, &$params)
    {
        throw new NotSupportedException('IoTDB Table REST does not support Yii UPSERT operations.');
    }

    public function renameTable($oldName, $newName)
    {
        throw new NotSupportedException('IoTDB Table REST does not support renaming tables.');
    }

    public function renameColumn($table, $oldName, $newName)
    {
        throw new NotSupportedException('IoTDB Table REST does not support renaming columns.');
    }

    public function addPrimaryKey($name, $table, $columns)
    {
        throw new NotSupportedException('IoTDB Table REST does not support primary key constraints.');
    }

    public function dropPrimaryKey($name, $table)
    {
        throw new NotSupportedException('IoTDB Table REST does not support primary key constraints.');
    }

    public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
    {
        throw new NotSupportedException('IoTDB Table REST does not support foreign key constraints.');
    }

    public function dropForeignKey($name, $table)
    {
        throw new NotSupportedException('IoTDB Table REST does not support foreign key constraints.');
    }

    public function createIndex($name, $table, $columns, $unique = false)
    {
        throw new NotSupportedException('IoTDB Table REST does not support Yii index creation.');
    }

    public function dropIndex($name, $table)
    {
        throw new NotSupportedException('IoTDB Table REST does not support Yii index operations.');
    }

    public function addUnique($name, $table, $columns)
    {
        throw new NotSupportedException('IoTDB Table REST does not support unique constraints.');
    }

    public function dropUnique($name, $table)
    {
        throw new NotSupportedException('IoTDB Table REST does not support unique constraints.');
    }

    public function addCheck($name, $table, $expression)
    {
        throw new NotSupportedException('IoTDB Table REST does not support check constraints.');
    }

    public function dropCheck($name, $table)
    {
        throw new NotSupportedException('IoTDB Table REST does not support check constraints.');
    }

    public function addDefaultValue($name, $table, $column, $value)
    {
        throw new NotSupportedException('IoTDB Table REST does not support default value constraints.');
    }

    public function dropDefaultValue($name, $table)
    {
        throw new NotSupportedException('IoTDB Table REST does not support default value constraints.');
    }

    public function truncateTable($table)
    {
        throw new NotSupportedException('IoTDB Table REST does not support TRUNCATE TABLE.');
    }
}
