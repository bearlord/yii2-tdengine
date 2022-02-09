<?php

namespace yii\db\taos;

use yii\db\DataReader;
use yii\db\Exception;
use yii\db\Expression;
use yii\db\PdoValue;
use Yii;

/**
 * Command represents an TDengine's SQL statement to be executed against a database.
 *
 * {@inheritdoc}
 *
 * @author bearlord <565364226@qq.com>
 * @since 2.0.14
 */
class Command extends \yii\db\Command
{
    /**
     * @var array pending parameters to be bound to the current PDO statement.
     */
    private $_pendingParams = [];
    /**
     * @var string the SQL statement that this command represents
     */
    private $_sql;
    /**
     * @var string name of the table, which schema, should be refreshed after command execution.
     */
    private $_refreshTableName;
    /**
     * @var string|false|null the isolation level to use for this transaction.
     * See [[Transaction::begin()]] for details.
     */
    private $_isolationLevel = false;
    /**
     * @var callable a callable (e.g. anonymous function) that is called when [[\yii\db\Exception]] is thrown
     * when executing the command.
     */
    private $_retryHandler;

    /**
     * Executes the SQL statement.
     * This method should only be used for executing non-query SQL statement, such as `INSERT`, `DELETE`, `UPDATE` SQLs.
     * No result set will be returned.
     * @return int number of rows affected by the execution.
     * @throws Exception execution failed
     */
    public function execute()
    {
        $sql = $this->getSql();
        list($profile, $rawSql) = $this->logQuery(__METHOD__);

        if ($sql == '') {
            return 0;
        }

        $this->prepare(false);

        try {
            $profile and Yii::beginProfile($rawSql, __METHOD__);

            $this->internalExecute($rawSql);
            $n = $this->pdoStatement->rowCount();

            $profile and Yii::endProfile($rawSql, __METHOD__);

            $this->refreshTableSchema();

            return $n;
        } catch (Exception $e) {
            $profile and Yii::endProfile($rawSql, __METHOD__);
            throw $e;
        }
    }

    /**
     * Logs the current database query if query logging is enabled and returns
     * the profiling token if profiling is enabled.
     * @param string $category the log category.
     * @return array array of two elements, the first is boolean of whether profiling is enabled or not.
     * The second is the rawSql if it has been created.
     */
    protected function logQuery($category)
    {
        if ($this->db->enableLogging) {
            $rawSql = $this->getRawSql();
            Yii::info($rawSql, $category);
        }
        if (!$this->db->enableProfiling) {
            return [false, isset($rawSql) ? $rawSql : null];
        }

        return [true, isset($rawSql) ? $rawSql : $this->getRawSql()];
    }

    /**
     * Performs the actual DB query of a SQL statement.
     * @param string $method method of PDOStatement to be called
     * @param int $fetchMode the result fetch mode. Please refer to [PHP manual](https://secure.php.net/manual/en/function.PDOStatement-setFetchMode.php)
     * for valid fetch modes. If this parameter is null, the value set in [[fetchMode]] will be used.
     * @return mixed the method execution result
     * @throws Exception if the query causes any problem
     * @since 2.0.1 this method is protected (was private before).
     */
    protected function queryInternal($method, $fetchMode = null)
    {
        list($profile, $rawSql) = $this->logQuery('yii\db\Command::query');

        if ($method !== '') {
            $info = $this->db->getQueryCacheInfo($this->queryCacheDuration, $this->queryCacheDependency);
            if (is_array($info)) {
                /* @var $cache \yii\caching\CacheInterface */
                $cache = $info[0];
                $rawSql = $rawSql ?: $this->getRawSql();
                $cacheKey = $this->getCacheKey($method, $fetchMode, $rawSql);
                $result = $cache->get($cacheKey);
                if (is_array($result) && isset($result[0])) {
                    Yii::debug('Query result served from cache', 'yii\db\Command::query');
                    return $result[0];
                }
            }
        }

        $this->prepare(true);

        try {
            $profile and Yii::beginProfile($rawSql, 'yii\db\Command::query');

            $this->internalExecute($rawSql);

            if ($method === '') {
                $result = new DataReader($this);
            } else {
                if ($fetchMode === null) {
                    $fetchMode = $this->fetchMode;
                }
                $result = call_user_func_array([$this->pdoStatement, $method], (array) $fetchMode);
                $this->pdoStatement->closeCursor();
            }

            $profile and Yii::endProfile($rawSql, 'yii\db\Command::query');
        } catch (Exception $e) {
            $profile and Yii::endProfile($rawSql, 'yii\db\Command::query');
            throw $e;
        }

        if (isset($cache, $cacheKey, $info)) {
            $cache->set($cacheKey, [$result], $info[1], $info[2]);
            Yii::debug('Saved query result in cache', 'yii\db\Command::query');
        }

        return $result;
    }

    /**
     * Marks a specified table schema to be refreshed after command execution.
     * @param string $name name of the table, which schema should be refreshed.
     * @return \yii\db\Command this command instance
     * @since 2.0.6
     */
    protected function requireTableSchemaRefresh($name)
    {
        $this->_refreshTableName = $name;
        return $this;
    }

    /**
     * Refreshes table schema, which was marked by [[requireTableSchemaRefresh()]].
     * @since 2.0.6
     */
    protected function refreshTableSchema()
    {
        if ($this->_refreshTableName !== null) {
            $this->db->getSchema()->refreshTableSchema($this->_refreshTableName);
        }
    }

    /**
     * Marks the command to be executed in transaction.
     * @param string|null $isolationLevel The isolation level to use for this transaction.
     * See [[Transaction::begin()]] for details.
     * @return \yii\db\Command this command instance.
     * @since 2.0.14
     */
    protected function requireTransaction($isolationLevel = null)
    {
        $this->_isolationLevel = $isolationLevel;
        return $this;
    }

    /**
     * Sets a callable (e.g. anonymous function) that is called when [[Exception]] is thrown
     * when executing the command. The signature of the callable should be:
     *
     * ```php
     * function (\yii\db\Exception $e, $attempt)
     * {
     *     // return true or false (whether to retry the command or rethrow $e)
     * }
     * ```
     *
     * The callable will recieve a database exception thrown and a current attempt
     * (to execute the command) number starting from 1.
     *
     * @param callable $handler a PHP callback to handle database exceptions.
     * @return \yii\db\Command this command instance.
     * @since 2.0.14
     */
    protected function setRetryHandler(callable $handler)
    {
        $this->_retryHandler = $handler;
        return $this;
    }

    /**
     * Executes a prepared statement.
     *
     * It's a wrapper around [[\PDOStatement::execute()]] to support transactions
     * and retry handlers.
     *
     * @param string|null $rawSql the rawSql if it has been created.
     * @throws Exception if execution failed.
     * @since 2.0.14
     */
    protected function internalExecute($rawSql)
    {
        $attempt = 0;
        while (true) {
            try {
                if (
                    ++$attempt === 1
                    && $this->_isolationLevel !== false
                    && $this->db->getTransaction() === null
                ) {
                    $this->db->transaction(function () use ($rawSql) {
                        $this->internalExecute($rawSql);
                    }, $this->_isolationLevel);
                } else {
                    $this->pdoStatement->execute();
                }
                break;
            } catch (\Exception $e) {
                $rawSql = $rawSql ?: $this->getRawSql();
                $e = $this->db->getSchema()->convertException($e, $rawSql);
                if ($this->_retryHandler === null || !call_user_func($this->_retryHandler, $e, $attempt)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Resets command properties to their initial state.
     *
     * @since 2.0.13
     */
    protected function reset()
    {
        $this->_sql = null;
        $this->_pendingParams = [];
        $this->params = [];
        $this->_refreshTableName = null;
        $this->_isolationLevel = false;
        $this->_retryHandler = null;
    }


    /**
     * Binds a list of values to the corresponding parameters.
     * This is similar to [[bindValue()]] except that it binds multiple values at a time.
     * Note that the SQL data type of each value is determined by its PHP type.
     * @param array $values the values to be bound. This must be given in terms of an associative
     * array with array keys being the parameter names, and array values the corresponding parameter values,
     * e.g. `[':name' => 'John', ':age' => 25]`. By default, the PDO type of each value is determined
     * by its PHP type. You may explicitly specify the PDO type by using a [[ESD\Yii\Db\PdoValue]] class: `new PdoValue(value, type)`,
     * e.g. `[':name' => 'John', ':profile' => new PdoValue($profile, \PDO::PARAM_LOB)]`.
     * @return Command the current command being executed
     */
    public function bindValues($values)
    {
        if (empty($values)) {
            return $this;
        }

        $schema = $this->db->getSchema();
        foreach ($values as $name => $value) {
            if (is_array($value)) {
                $this->_pendingParams[$name] = $value;
                $this->params[$name] = $value[0];
            } elseif ($value instanceof PdoValue) {
                $this->_pendingParams[$name] = [$value->getValue(), $value->getType()];
                $this->params[$name] = $value->getValue();
            } else {
                $type = $schema->getPdoType($value);
                $this->_pendingParams[$name] = [$value, $type];
                $this->params[$name] = $value;
            }
        }

        return $this;
    }

    /**
     * Executes the SQL statement and returns query result.
     * This method is for executing a SQL query that returns result set, such as `SELECT`.
     * @return DataReader the reader object for fetching the query result
     * @throws Exception execution failed
     */
    public function query()
    {
        return $this->queryInternal('');
    }

    /**
     * Executes the SQL statement and returns ALL rows at once.
     * @param int $fetchMode the result fetch mode. Please refer to [PHP manual](https://secure.php.net/manual/en/function.PDOStatement-setFetchMode.php)
     * for valid fetch modes. If this parameter is null, the value set in [[fetchMode]] will be used.
     * @return array all rows of the query result. Each array element is an array representing a row of data.
     * An empty array is returned if the query results in nothing.
     * @throws Exception execution failed
     */
    public function queryAll($fetchMode = null)
    {
        return $this->queryInternal('fetchAll', $fetchMode);
    }

    /**
     * Executes the SQL statement and returns the first row of the result.
     * This method is best used when only the first row of result is needed for a query.
     * @param int $fetchMode the result fetch mode. Please refer to [PHP manual](https://secure.php.net/manual/en/pdostatement.setfetchmode.php)
     * for valid fetch modes. If this parameter is null, the value set in [[fetchMode]] will be used.
     * @return array|false the first row (in terms of an array) of the query result. False is returned if the query
     * results in nothing.
     * @throws Exception execution failed
     */
    public function queryOne($fetchMode = null)
    {
        return $this->queryInternal('fetch', $fetchMode);
    }

    /**
     * Executes the SQL statement and returns the value of the first column in the first row of data.
     * This method is best used when only a single value is needed for a query.
     * @return string|null|false the value of the first column in the first row of the query result.
     * False is returned if there is no value.
     * @throws Exception execution failed
     */
    public function queryScalar()
    {
        $result = $this->queryInternal('fetchColumn', 0);
        if (is_resource($result) && get_resource_type($result) === 'stream') {
            return stream_get_contents($result);
        }

        return $result;
    }

    /**
     * Executes the SQL statement and returns the first column of the result.
     * This method is best used when only the first column of result (i.e. the first element in each row)
     * is needed for a query.
     * @return array the first column of the query result. Empty array is returned if the query results in nothing.
     * @throws Exception execution failed
     */
    public function queryColumn()
    {
        return $this->queryInternal('fetchAll', \PDO::FETCH_COLUMN);
    }

    /**
     * Returns the SQL statement for this command.
     * @return string the SQL statement to be executed
     */
    public function getSql()
    {
        return $this->_sql;
    }

    /**
     * Specifies the SQL statement to be executed. The SQL statement will be quoted using [[Connection::quoteSql()]].
     * The previous SQL (if any) will be discarded, and [[params]] will be cleared as well. See [[reset()]]
     * for details.
     *
     * @param string $sql the SQL statement to be set.
     * @return Command this command instance
     * @see reset()
     * @see cancel()
     */
    public function setSql($sql)
    {
        if ($sql !== $this->_sql) {
            $this->cancel();
            $this->reset();
            $this->_sql = $this->quoteSql($sql);
        }

        return $this;
    }

    /**
     * Specifies the SQL statement to be executed. The SQL statement will not be modified in any way.
     * The previous SQL (if any) will be discarded, and [[params]] will be cleared as well. See [[reset()]]
     * for details.
     *
     * @param string $sql the SQL statement to be set.
     * @return \yii\db\Command this command instance
     * @since 2.0.13
     * @see reset()
     * @see cancel()
     */
    public function setRawSql($sql)
    {
        if ($sql !== $this->_sql) {
            $this->cancel();
            $this->reset();
            $this->_sql = $sql;
        }

        return $this;
    }

    /**
     * Returns the raw SQL by inserting parameter values into the corresponding placeholders in [[sql]].
     * Note that the return value of this method should mainly be used for logging purpose.
     * It is likely that this method returns an invalid SQL due to improper replacement of parameter placeholders.
     * @return string the raw SQL with parameter values inserted into the corresponding placeholders in [[sql]].
     */
    public function getRawSql()
    {
        if (empty($this->params)) {
            return $this->_sql;
        }
        $params = [];
        foreach ($this->params as $name => $value) {
            if (is_string($name) && strncmp(':', $name, 1)) {
                $name = ':' . $name;
            }
            if (is_string($value)) {
                $params[$name] = $this->db->quoteValue($value);
            } elseif (is_bool($value)) {
                $params[$name] = ($value ? 'TRUE' : 'FALSE');
            } elseif ($value === null) {
                $params[$name] = 'NULL';
            } elseif ((!is_object($value) && !is_resource($value)) || $value instanceof Expression) {
                $params[$name] = $value;
            }
        }
        if (!isset($params[1])) {
            return strtr($this->_sql, $params);
        }
        $sql = '';
        foreach (explode('?', $this->_sql) as $i => $part) {
            $sql .= (isset($params[$i]) ? $params[$i] : '') . $part;
        }

        return $sql;
    }

    /**
     * Prepares the SQL statement to be executed.
     * For complex SQL statement that is to be executed multiple times,
     * this may improve performance.
     * For SQL statement with binding parameters, this method is invoked
     * automatically.
     * @param bool $forRead whether this method is called for a read query. If null, it means
     * the SQL statement should be used to determine whether it is for read or write.
     * @throws Exception if there is any DB error
     */
    public function prepare($forRead = null)
    {
        if ($this->pdoStatement) {
            $this->bindPendingParams();
            return;
        }

        $sql = $this->getSql();

        if ($this->db->getTransaction()) {
            // master is in a transaction. use the same connection.
            $forRead = false;
        }
        if ($forRead || $forRead === null && $this->db->getSchema()->isReadQuery($sql)) {
            $pdo = $this->db->getSlavePdo();
        } else {
            $pdo = $this->db->getMasterPdo();
        }

        try {
            $this->pdoStatement = $pdo->prepare($sql);
            $this->bindPendingParams();
        } catch (\Exception $e) {
            $message = $e->getMessage() . "\nFailed to prepare SQL: $sql";
            $errorInfo = $e instanceof \PDOException ? $e->errorInfo : null;
            throw new Exception($message, $errorInfo, (int) $e->getCode(), $e);
        }
    }

    /**
     * Processes a SQL statement by quoting table and column names that are enclosed within double brackets.
     * Tokens enclosed within double curly brackets are treated as table names, while
     * tokens enclosed within double square brackets are column names. They will be quoted accordingly.
     * Also, the percentage character "%" at the beginning or ending of a table name will be replaced
     * with [[tablePrefix]].
     * @param string $sql the SQL to be quoted
     * @return string the quoted SQL
     */
    public function quoteSql($sql)
    {
        return preg_replace_callback(
            '/(\\{\\{(%?[\w\-\. ]+%?)\\}\\}|\\[\\[([\w\-\. ]+)\\]\\])/',
            function ($matches) {
                if (isset($matches[3])) {
                    return $matches[3];
                }

                return str_replace('%', $this->db->tablePrefix, $matches[2]);
            },
            $sql
        );
    }

    /**
     * Binds pending parameters that were registered via [[bindValue()]] and [[bindValues()]].
     * Note that this method requires an active [[pdoStatement]].
     */
    protected function bindPendingParams()
    {
        foreach ($this->_pendingParams as $name => $value) {
            $this->pdoStatement->bindValue($name, $value[0], $value[1]);
        }
        $this->_pendingParams = [];
    }

    /**
     * Binds a value to a parameter.
     * @param string|int $name Parameter identifier. For a prepared statement
     * using named placeholders, this will be a parameter name of
     * the form `:name`. For a prepared statement using question mark
     * placeholders, this will be the 1-indexed position of the parameter.
     * @param mixed $value The value to bind to the parameter
     * @param int $dataType SQL data type of the parameter. If null, the type is determined by the PHP type of the value.
     * @return \yii\db\Command the current command being executed
     * @see https://secure.php.net/manual/en/function.PDOStatement-bindValue.php
     */
    public function bindValue($name, $value, $dataType = null)
    {
        if ($dataType === null) {
            $dataType = $this->db->getSchema()->getPdoType($value);
        }
        $this->_pendingParams[$name] = [$value, $dataType];
        $this->params[$name] = $value;

        return $this;
    }

    /**
     * Creates a SQL command for changing the definition of a column.
     * @param string $table the table whose column is to be changed. The table name will be properly quoted by the method.
     * @param string $column the name of the column to be changed. The name will be properly quoted by the method.
     * @param string $type the column type. [[\ESD\Yii\Db\QueryBuilder::getColumnType()]] will be called
     * to convert the give column type to the physical one. For example, `string` will be converted
     * as `varchar(255)`, and `string not null` becomes `varchar(255) not null`.
     * @return Command the command object itself
     */
    public function alterColumn($table, $column, $type)
    {
        $sql = $this->db->getQueryBuilder()->alterColumn($table, $column, $type);

        return $this->setSql($sql)->requireTableSchemaRefresh($table);
    }

    /**
     * @param $table
     * @param $tag
     * @param $type
     * @return Command
     */
    public function addTag($table ,$tag, $type)
    {
        $sql = $this->db->getQueryBuilder()->addTag($table, $tag, $type);

        return $this->setSql($sql)->requireTableSchemaRefresh($table);
    }


    /**
     * Creates a SQL command for creating a new super table.
     *
     * @param $table
     * @param $columns
     * @param array $tags
     * @return Command
     */
    public function createSTable($table, $columns, $tags = [])
    {
        $sql = $this->db->getQueryBuilder()->createSTable($table, $columns, $tags);

        return $this->setSql($sql)->requireTableSchemaRefresh($table);
    }

    /**
     * Creates a SQL command for creating a new sub table.
     *
     * @param $table
     * @param $stable
     * @param array $tags
     * @return Command
     */
    public function createSubTable($table, $stable, $tags = [])
    {
        $sql = $this->db->getQueryBuilder()->createSubTable($table, $stable, $tags);

        return $this->setSql($sql)->requireTableSchemaRefresh($table);
    }

    /**
     * Creates an INSERT SQL statement.
     *
     * @param $table
     * @param $columns
     * @param $stable
     * @param array $tags
     * @return Command
     */
    public function insertUsingSTable($table, $columns, $stable, $tags = [])
    {
        $params = [];
        $sql = $this->db->getQueryBuilder()->insertUsingSTable($table, $columns, $params, $stable, $tags);

        return $this->setSql($sql)->bindValues($params);
    }
}