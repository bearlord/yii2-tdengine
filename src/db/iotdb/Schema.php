<?php

namespace yii\db\iotdb;

use yii\base\NotSupportedException;
use yii\db\TableSchema;

/** Reads IoTDB Table REST metadata and maps it to Yii table schemas. */
class Schema extends \yii\db\Schema
{
    private $_columnTypes = [
        'BOOLEAN' => ['boolean', 'boolean'],
        'INT32' => ['integer', 'integer'],
        'INT64' => ['bigint', 'integer'],
        'FLOAT' => ['float', 'double'],
        'DOUBLE' => ['double', 'double'],
        'STRING' => ['string', 'string'],
        'BLOB' => ['binary', 'string'],
        'TIMESTAMP' => ['timestamp', 'integer'],
        'DATE' => ['date', 'string'],
    ];

    /**
     * @return QueryBuilder
     */
    public function createQueryBuilder()
    {
        return new QueryBuilder($this->db);
    }

    /**
     * Returns the configured table database or discovers available databases.
     * @return array
     */
    protected function findSchemaNames()
    {
        if ($this->db->database !== null && $this->db->database !== '') {
            return [$this->db->database];
        }
        $rows = $this->db->getHttpClient()->query('SHOW DATABASES');
        return $this->firstColumn($rows);
    }

    /**
     * @param string $schema
     * @return array
     */
    protected function findTableNames($schema = '')
    {
        $database = $schema !== '' ? $schema : $this->db->database;
        $rows = $this->db->getHttpClient()->query('SHOW TABLES', $database);
        return $this->firstColumn($rows);
    }

    /**
     * Loads columns from DESCRIBE. The time and tag columns form the natural
     * row identity exposed to Yii ActiveRecord.
     * @param string $name
     * @return TableSchema|null
     */
    protected function loadTableSchema($name)
    {
        $schemaName = '';
        $tableName = $name;
        if (strpos($name, '.') !== false) {
            $parts = explode('.', $name);
            $tableName = array_pop($parts);
            $schemaName = array_pop($parts);
        }
        $database = $schemaName !== '' ? $schemaName : $this->db->database;
        $rows = $this->db->getHttpClient()->query(
            'DESCRIBE ' . $this->quoteTableName($tableName),
            $database
        );
        if (empty($rows)) {
            return null;
        }

        $table = new TableSchema();
        $table->name = $tableName;
        $table->schemaName = $schemaName;
        $table->fullName = $schemaName === '' ? $tableName : $schemaName . '.' . $tableName;
        $primaryKey = [];

        foreach ($rows as $row) {
            $name = $this->rowValue($row, ['columnname', 'column_name', 'column', 'name']);
            $type = $this->rowValue($row, ['datatype', 'data_type', 'type']);
            if ($name === null || $type === null) {
                continue;
            }
            $category = strtoupper((string) $this->rowValue($row, ['category', 'columncategory']));
            $nativeType = strtoupper(preg_replace('/\s*\(.*/', '', trim((string) $type)));
            $mapping = isset($this->_columnTypes[$nativeType])
                ? $this->_columnTypes[$nativeType]
                : ['string', 'string'];

            $column = new ColumnSchema();
            $column->name = (string) $name;
            $column->dbType = (string) $type;
            $column->type = $mapping[0];
            $column->phpType = $mapping[1];
            $column->allowNull = $category !== 'TIME';
            $column->isPrimaryKey = false;
            $column->category = $category;
            $table->columns[$column->name] = $column;

            if ($category === 'TIME' || $category === 'TAG') {
                $primaryKey[] = $column->name;
            }
        }

        if (empty($table->columns)) {
            return null;
        }
        $table->primaryKey = $primaryKey;
        foreach ($primaryKey as $columnName) {
            $table->columns[$columnName]->isPrimaryKey = true;
        }
        return $table;
    }

    /**
     * IoTDB REST cannot return a generated sequence value.
     * @param string $sequenceName
     * @throws NotSupportedException
     */
    public function getLastInsertID($sequenceName = '')
    {
        throw new NotSupportedException('IoTDB Table REST does not provide last-insert IDs or sequences.');
    }

    /**
     * Quotes string literals using IoTDB SQL escaping.
     * @param mixed $value
     * @return mixed
     */
    public function quoteValue($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @param string $name
     * @return string
     */
    public function quoteTableName($name)
    {
        if (strpos($name, '(') !== false || strpos($name, '{{') !== false) {
            return $name;
        }
        $parts = explode('.', $name);
        foreach ($parts as $index => $part) {
            $parts[$index] = $this->quoteSimpleTableName($part);
        }
        return implode('.', $parts);
    }

    /**
     * @param string $name
     * @return string
     */
    public function quoteSimpleTableName($name)
    {
        if (preg_match('/^"(?:[^"]|"")*"$/D', $name)) {
            return $name;
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * @param string $name
     * @return string
     */
    public function quoteColumnName($name)
    {
        if (strpos($name, '(') !== false || strpos($name, '[[') !== false
            || strpos($name, '{{') !== false
        ) {
            return $name;
        }
        $parts = explode('.', $name);
        foreach ($parts as $index => $part) {
            $parts[$index] = $this->quoteSimpleColumnName($part);
        }
        return implode('.', $parts);
    }

    /**
     * @param string $name
     * @return string
     */
    public function quoteSimpleColumnName($name)
    {
        return $name === '*' ? '*' : $this->quoteSimpleTableName($name);
    }

    /**
     * @param array $rows
     * @return array
     */
    private function firstColumn($rows)
    {
        $values = [];
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row)) {
                $values[] = reset($row);
            }
        }
        return $values;
    }

    /**
     * @param array $row
     * @param array $names lower-case candidate labels
     * @return mixed
     */
    private function rowValue($row, $names)
    {
        foreach ($row as $key => $value) {
            if (in_array(strtolower((string) $key), $names, true)) {
                return $value;
            }
        }
        return null;
    }
}
