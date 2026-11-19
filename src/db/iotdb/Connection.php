<?php

namespace yii\db\iotdb;

use Yii;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\db\Command;

/**
 * Yii database connection for Apache IoTDB's Table REST v1 API.
 *
 * This connection deliberately does not expose a native PDO handle. Commands
 * are executed through the REST client and Yii's SQL/query builder APIs.
 */
class Connection extends \yii\db\Connection
{
    public $baseUrl;
    public $database;
    public $username;
    public $password;
    public $timeout = 30;
    public $rowLimit;
    public $verifyTls = true;
    public $endpointPrefix = '/rest/table/v1';
    public $schemaMap = [
        'iotdb' => 'yii\\db\\iotdb\\Schema',
    ];
    public $commandMap = [
        'iotdb' => 'yii\\db\\iotdb\\Command',
    ];
    /** @var HttpClient|object|null */
    public $httpClient;

    private $_iotdbSchema;

    /**
     * IoTDB has no PDO driver name, but returning a stable name lets Yii use
     * its standard schema and command maps.
     * @return string
     */
    public function getDriverName()
    {
        return 'iotdb';
    }

    /**
     * Marks the connection active without constructing a fake PDO instance.
     * The non-null object is only Yii's active-connection sentinel.
     */
    public function open()
    {
        if ($this->pdo !== null) {
            return;
        }

        if (empty($this->baseUrl)) {
            throw new InvalidConfigException('The "baseUrl" property must be configured for IoTDB.');
        }
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new InvalidConfigException('IoTDB baseUrl must be an absolute HTTP or HTTPS URL.');
        }
        if ($this->timeout <= 0) {
            throw new InvalidConfigException('IoTDB timeout must be greater than zero.');
        }
        if ($this->rowLimit !== null && $this->rowLimit <= 0) {
            throw new InvalidConfigException('IoTDB rowLimit must be greater than zero when configured.');
        }

        $this->pdo = (object) [];
    }

    /**
     * Returns the REST transport used by commands and schema discovery.
     * @return HttpClient|object
     */
    public function getHttpClient()
    {
        if ($this->httpClient === null) {
            $this->httpClient = new HttpClient($this);
        }
        return $this->httpClient;
    }

    /**
     * Returns the IoTDB schema implementation.
     * @return Schema
     */
    public function getSchema()
    {
        if ($this->_iotdbSchema === null) {
            $config = $this->schemaMap['iotdb'];
            if (!is_array($config)) {
                $config = ['class' => $config];
            }
            $config['db'] = $this;
            $this->_iotdbSchema = Yii::createObject($config);
        }
        return $this->_iotdbSchema;
    }

    /**
     * Creates an IoTDB command. Yii's default implementation also honors the
     * driver command map, but defining it here keeps REST commands independent
     * from PDO driver discovery.
     * @param string|null $sql
     * @param array $params
     * @return Command
     */
    public function createCommand($sql = null, $params = [])
    {
        $config = $this->commandMap['iotdb'];
        if (!is_array($config)) {
            $config = ['class' => $config];
        }
        $config['db'] = $this;
        $config['sql'] = $sql;
        return Yii::createObject($config)->bindValues($params);
    }

    /**
     * Clears the cached schema along with Yii's connection state.
     */
    public function close()
    {
        $this->_iotdbSchema = null;
        parent::close();
    }

    /**
     * IoTDB REST does not provide SQL transactions.
     * @throws NotSupportedException
     */
    public function beginTransaction($isolationLevel = null)
    {
        throw new NotSupportedException('IoTDB Table REST does not support transactions.');
    }
}
