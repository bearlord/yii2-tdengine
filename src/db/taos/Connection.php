<?php

namespace yii\db\taos;

use yii\base\NotSupportedException;
use yii\db\Command;
use yii\db\Schema;
use Yii;

class Connection extends \yii\db\Connection
{
    /**
     * @var Schema the database schema
     */
    private $_schema;

    public $schemaMap = [
        'taos' => 'yii\db\taos\Schema', // Taos
    ];

    public $commandMap = [
        'taos' => 'yii\db\taos\Command', // Taos
    ];

    /**
     * Returns the schema information for the database opened by this connection.
     * @return Schema the schema information for the database opened by this connection.
     * @throws NotSupportedException if there is no support for the current driver type
     */
    public function getSchema()
    {
        if ($this->_schema !== null) {
            return $this->_schema;
        }

        $driver = $this->getDriverName();
        if (isset($this->schemaMap[$driver])) {
            $config = !is_array($this->schemaMap[$driver]) ? ['class' => $this->schemaMap[$driver]] : $this->schemaMap[$driver];
            $config['db'] = $this;

            return $this->_schema = Yii::createObject($config);
        }

        throw new NotSupportedException("Connection does not support reading schema information for '$driver' DBMS.");
    }

    /**
     * Creates a command for execution.
     * @param string $sql the SQL statement to be executed
     * @param array $params the parameters to be bound to the SQL statement
     * @return Command the DB command
     */
    public function createCommand($sql = null, $params = [])
    {
        $driver = $this->getDriverName();
        $config = ['class' => 'yii\db\Command'];
        if ($this->commandClass !== $config['class']) {
            $config['class'] = $this->commandClass;
        } elseif (isset($this->commandMap[$driver])) {
            $config = !is_array($this->commandMap[$driver]) ? ['class' => $this->commandMap[$driver]] : $this->commandMap[$driver];
        }
        $config['db'] = $this;
        $config['sql'] = $sql;
        /** @var Command $command */
        $command = Yii::createObject($config);
        return $command->bindValues($params);
    }

}