<?php

namespace yii\db\iotdb;

use Yii;
use yii\base\InvalidArgumentException;
use yii\db\DataReader;
use yii\db\Exception;
use yii\db\PdoValue;

/** Executes Yii SQL commands over IoTDB's Table REST v1 API. */
class Command extends \yii\db\Command
{
    /**
     * IoTDB REST accepts SQL text rather than prepared statements. This method
     * expands Yii parameters only outside SQL string literals and comments.
     * @return string
     */
    public function getRawSql()
    {
        $sql = (string) $this->getSql();
        if (empty($this->params)) {
            return $sql;
        }

        $result = '';
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $position = 1;
        $positionOffset = array_key_exists(0, $this->params) ? 0 : 1;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                $result .= $char;
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }
            if ($blockComment) {
                $result .= $char;
                if ($char === '*' && $next === '/') {
                    $result .= $next;
                    $i++;
                    $blockComment = false;
                }
                continue;
            }
            if ($quote !== null) {
                $result .= $char;
                if ($char === $quote) {
                    if ($next === $quote) {
                        $result .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === '-' && $next === '-') {
                $result .= '--';
                $i++;
                $lineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $result .= '/*';
                $i++;
                $blockComment = true;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $result .= $char;
                continue;
            }
            $previous = $i > 0 ? $sql[$i - 1] : '';
            if ($char === ':' && $previous !== ':' && $next !== ':' && preg_match('/[A-Za-z_]/', $next)) {
                $remaining = substr($sql, $i + 1);
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $remaining, $matches)) {
                    $name = ':' . $matches[1];
                    if (array_key_exists($name, $this->params) || array_key_exists($matches[1], $this->params)) {
                        $value = array_key_exists($name, $this->params)
                            ? $this->params[$name]
                            : $this->params[$matches[1]];
                        $result .= $this->quoteParameter($value);
                        $i += strlen($matches[1]);
                        continue;
                    }
                }
            }
            if ($char === '?') {
                $key = $position - 1 + $positionOffset;
                if (array_key_exists($key, $this->params)) {
                    $result .= $this->quoteParameter($this->params[$key]);
                    $position++;
                    continue;
                }
                $position++;
            }
            $result .= $char;
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function quoteParameter($value)
    {
        if ($value instanceof PdoValue) {
            $value = $value->getValue();
        } elseif (is_array($value) && array_key_exists(0, $value)) {
            $value = $value[0];
        }

        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new InvalidArgumentException('IoTDB SQL parameters must not contain NaN or infinity.');
            }
            $options = defined('JSON_PRESERVE_ZERO_FRACTION') ? JSON_PRESERVE_ZERO_FRACTION : 0;
            return json_encode($value, $options);
        }
        if (is_string($value)) {
            return $this->db->quoteValue($value);
        }

        throw new InvalidArgumentException('IoTDB SQL parameters must be scalar values or null.');
    }

    /**
     * REST has no prepare phase; parameters are safely rendered by getRawSql().
     * @param bool|null $forRead
     * @return $this
     */
    public function prepare($forRead = null)
    {
        return $this;
    }

    /**
     * @param string|int $name
     * @param mixed $value
     * @param int|null $dataType
     * @return $this
     */
    public function bindValue($name, $value, $dataType = null)
    {
        $this->params[$name] = $value;
        return $this;
    }

    /**
     * @param array $values
     * @return $this
     */
    public function bindValues($values)
    {
        foreach ($values as $name => $value) {
            $this->bindValue($name, $value);
        }
        return $this;
    }

    /**
     * Executes a DDL or DML statement.
     * @return int affected row count if provided by the REST server, otherwise zero
     */
    public function execute()
    {
        $sql = $this->getSql();
        if ($sql === null || trim($sql) === '') {
            return 0;
        }

        $rawSql = $this->getRawSql();
        $category = __METHOD__;
        $profile = $this->logSql($category);
        try {
            $this->db->open();
            $response = $this->db->getHttpClient()->nonQuery($rawSql, $this->db->database);
        } catch (\Exception $e) {
            $this->endProfile($profile, $category);
            throw $this->db->getSchema()->convertException($e, $rawSql);
        }
        $this->endProfile($profile, $category);

        $this->refreshTableSchema();
        return $this->db->getHttpClient()->affectedRows($response);
    }

    /**
     * Executes a query and adapts the returned rows to Yii's command API.
     * @param string $method
     * @param int|null $fetchMode
     * @return mixed
     */
    protected function queryInternal($method, $fetchMode = null)
    {
        $sql = $this->getSql();
        if ($sql === null || trim($sql) === '') {
            $this->pdoStatement = new Statement([]);
            return $method === '' ? new DataReader($this) : false;
        }

        $rawSql = $this->getRawSql();
        $category = 'yii\\db\\Command::query';

        $cache = null;
        $cacheKey = null;
        $cacheInfo = $this->db->getQueryCacheInfo($this->queryCacheDuration, $this->queryCacheDependency);
        if ($method !== '' && is_array($cacheInfo)) {
            $cache = $cacheInfo[0];
            $cacheKey = $this->getCacheKey($method, $fetchMode, $rawSql);
            $cacheKey[] = $this->db->database;
            $cached = $cache->get($cacheKey);
            if (is_array($cached) && array_key_exists(0, $cached)) {
                return $cached[0];
            }
        }

        $profile = $this->logSql($category);
        try {
            $this->db->open();
            $resultSet = $this->db->getHttpClient()->queryResult($rawSql, $this->db->database);
        } catch (\Exception $e) {
            $this->endProfile($profile, $category);
            throw $this->db->getSchema()->convertException($e, $rawSql);
        }

        $this->pdoStatement = new Statement($resultSet['rows'], $resultSet['columns']);
        if ($method === '') {
            $result = new DataReader($this);
        } else {
            if ($fetchMode === null) {
                $fetchMode = $this->fetchMode;
            }
            if ($method === 'fetch') {
                $result = $this->pdoStatement->fetch($fetchMode);
            } elseif ($method === 'fetchColumn') {
                $result = $this->pdoStatement->fetchColumn(0);
            } else {
                $result = $this->pdoStatement->fetchAll($fetchMode);
            }
        }

        if ($cache !== null && $method !== '') {
            $cache->set($cacheKey, [$result], $cacheInfo[1], $cacheInfo[2]);
        }
        $this->endProfile($profile, $category);
        return $result;
    }

    /**
     * Logs SQL and brackets profiling without writing parameter values to logs.
     * @param string $category
     * @return bool whether profiling was started
     */
    private function logSql($category)
    {
        if ($this->db->enableLogging) {
            Yii::info($this->getSql(), $category);
        }
        if ($this->db->enableProfiling) {
            Yii::beginProfile($this->getSql(), $category);
            return true;
        }
        return false;
    }

    /**
     * @param bool $profile
     * @param string $category
     */
    private function endProfile($profile, $category)
    {
        if ($profile) {
            Yii::endProfile($this->getSql(), $category);
        }
    }
}
