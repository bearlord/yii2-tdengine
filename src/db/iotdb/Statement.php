<?php

namespace yii\db\iotdb;

/** Minimal in-memory statement adapter used by Yii's DataReader. */
class Statement
{
    private $_rows;
    private $_columns;
    private $_cursor = 0;
    private $_fetchMode = \PDO::FETCH_ASSOC;

    /**
     * @param array $rows
     */
    public function __construct($rows, $columns = [])
    {
        $this->_rows = array_values($rows);
        $this->_columns = !empty($columns)
            ? array_values($columns)
            : (empty($this->_rows) ? [] : array_keys($this->_rows[0]));
    }

    public function setFetchMode($mode)
    {
        $this->_fetchMode = $mode;
        return true;
    }

    public function fetch($mode = null)
    {
        if (!isset($this->_rows[$this->_cursor])) {
            return false;
        }
        $row = $this->_rows[$this->_cursor++];
        return $this->formatRow($row, $mode === null ? $this->_fetchMode : $mode);
    }

    public function fetchAll($mode = null)
    {
        $mode = $mode === null ? $this->_fetchMode : $mode;
        if ($mode === \PDO::FETCH_COLUMN) {
            $values = [];
            while (($row = $this->fetch(\PDO::FETCH_NUM)) !== false) {
                $values[] = isset($row[0]) ? $row[0] : null;
            }
            return $values;
        }
        $rows = [];
        while (($row = $this->fetch($mode)) !== false) {
            $rows[] = $row;
        }

        if ($mode === \PDO::FETCH_KEY_PAIR) {
            $result = [];
            foreach ($rows as $row) {
                $values = array_values($row);
                if (count($values) >= 2) {
                    $result[$values[0]] = $values[1];
                }
            }
            return $result;
        }
        return $rows;
    }

    public function fetchColumn($columnIndex = 0)
    {
        $row = $this->fetch(\PDO::FETCH_NUM);
        return $row === false || !array_key_exists($columnIndex, $row) ? false : $row[$columnIndex];
    }

    public function fetchObject($className = 'stdClass', $constructorArgs = [])
    {
        $row = $this->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        $reflection = new \ReflectionClass($className);
        $object = $reflection->newInstanceArgs($constructorArgs);
        foreach ($row as $name => $value) {
            $object->$name = $value;
        }
        return $object;
    }

    public function closeCursor()
    {
        $this->_cursor = count($this->_rows);
        return true;
    }

    public function rowCount()
    {
        return count($this->_rows);
    }

    public function columnCount()
    {
        return count($this->_columns);
    }

    public function nextRowset()
    {
        return false;
    }

    public function bindColumn($column, &$value, $type = null)
    {
        return true;
    }

    private function formatRow($row, $mode)
    {
        if ($mode === \PDO::FETCH_NUM || $mode === \PDO::FETCH_COLUMN) {
            return array_values($row);
        }
        if ($mode === \PDO::FETCH_BOTH) {
            $result = $row;
            foreach (array_values($row) as $index => $value) {
                $result[$index] = $value;
            }
            return $result;
        }
        if ($mode === \PDO::FETCH_OBJ) {
            return (object) $row;
        }
        return $row;
    }
}
