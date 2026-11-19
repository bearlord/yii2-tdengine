<?php

$projectRoot = dirname(dirname(__DIR__));
$frameworkPath = getenv('YII2_FRAMEWORK_PATH');
if ($frameworkPath) {
    require rtrim($frameworkPath, '\\/') . '/framework/Yii.php';
    $iotdbPath = $projectRoot . '/src/db/iotdb/';
    spl_autoload_register(function ($class) use ($iotdbPath) {
        $prefix = 'yii\\db\\iotdb\\';
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $iotdbPath . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
} else {
    require $projectRoot . '/vendor/autoload.php';
}

use yii\db\Exception;
use yii\db\iotdb\Connection;
use yii\db\iotdb\HttpClient;

function expect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$requests = [];
$failNext = false;
$db = new Connection([
    'baseUrl' => 'http://127.0.0.1:18080',
    'database' => 'test_db',
    'username' => 'offline-user',
    'password' => 'offline-password',
    'rowLimit' => 500,
]);
$db->httpClient = new HttpClient($db, function ($url, $headers, $body) use (&$requests, &$failNext) {
    $payload = json_decode($body, true);
    $requests[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];
    if ($failNext) {
        $failNext = false;
        return [200, json_encode(['code' => 600, 'message' => 'invalid SQL'])];
    }
    if (substr($url, -9) === '/nonQuery') {
        return [200, json_encode(['code' => 200, 'message' => 'SUCCESS', 'affectedRows' => 1])];
    }

    $sql = strtoupper(trim($payload['sql']));
    if (strpos($sql, 'SHOW TABLES') === 0) {
        return [200, json_encode([
            'code' => 200,
            'column_names' => ['table_name'],
            'values' => [['sensor_reading']],
        ])];
    }
    if (strpos($sql, 'DESCRIBE') === 0) {
        return [200, json_encode([
            'code' => 200,
            'column_names' => ['ColumnName', 'DataType', 'Category'],
            'values' => [
                ['time', 'TIMESTAMP', 'TIME'],
                ['device_id', 'STRING', 'TAG'],
                ['temperature', 'FLOAT', 'FIELD'],
            ],
        ])];
    }
    if (strpos($sql, 'SELECT DEVICE_ID') === 0) {
        return [200, json_encode([
            'code' => 200,
            'column_names' => ['device_id'],
            'values' => [['pump-17'], ['pump-18']],
        ])];
    }
    return [200, json_encode([
        'code' => 200,
        'message' => 'SUCCESS',
        'column_names' => ['time', 'device_id', 'temperature'],
        'column_types' => ['TIMESTAMP', 'STRING', 'FLOAT'],
        'values' => [[1735689600000, 'pump-17', 21.5]],
    ])];
});

$rows = $db->createCommand(
    'SELECT time, device_id, temperature FROM sensor_reading WHERE device_id = :device',
    [':device' => 'pump-17']
)->queryAll();
expect(count($rows) === 1 && $rows[0]['device_id'] === 'pump-17', 'queryAll must normalize REST rows.');
expect($requests[0]['url'] === 'http://127.0.0.1:18080/rest/table/v1/query', 'queries must use the Table REST v1 endpoint.');
expect($requests[0]['payload']['database'] === 'test_db', 'configured database must be sent.');
expect($requests[0]['payload']['row_limit'] === 500, 'configured rowLimit must be sent as row_limit.');
expect(!array_key_exists('dialect', $requests[0]['payload']), 'Table REST v1 selects the Table dialect server-side.');
expect(in_array('Authorization: Basic ' . base64_encode('offline-user:offline-password'), $requests[0]['headers'], true), 'Basic auth header missing.');

$reader = $db->createCommand('SELECT time, device_id, temperature FROM sensor_reading')->query();
expect($reader->read()['temperature'] === 21.5, 'DataReader must expose the first row.');
expect($reader->read() === false, 'DataReader must stop at the end of the result.');
$reader->close();

$columns = $db->createCommand('SELECT device_id FROM sensor_reading')->queryColumn();
expect($columns === ['pump-17', 'pump-18'], 'queryColumn must return a flat column.');

$literal = $db->createCommand('SELECT :label', [':label' => "O'Reilly"])->getRawSql();
expect($literal === "SELECT 'O''Reilly'", 'Table string parameters must double quotes.');
$parameterSql = $db->createCommand(
    "SELECT * FROM sensor_reading WHERE note = ':literal' AND device_id = :device /* :ignored */",
    [':device' => 'pump-17']
)->getRawSql();
expect(strpos($parameterSql, "note = ':literal'") !== false, 'placeholders inside string literals must not be expanded.');
expect(strpos($parameterSql, 'device_id = \'pump-17\'') !== false, 'named parameters must be expanded.');
expect(strpos($parameterSql, '/* :ignored */') !== false, 'placeholders inside comments must not be expanded.');

$tableSql = $db->getQueryBuilder()->createTable('sensor_reading', [
    'time' => 'timestamp TIME',
    'device_id' => 'string TAG',
    'temperature' => 'float FIELD',
]);
expect(strpos($tableSql, '"time" TIMESTAMP TIME') !== false, 'table DDL must preserve the TIME category.');
expect(strpos($tableSql, '"device_id" STRING TAG') !== false, 'table DDL must preserve the TAG category.');
expect($db->createCommand($tableSql)->execute() === 1, 'execute must return a reported affected row count.');
expect(substr($requests[count($requests) - 1]['url'], -9) === '/nonQuery', 'DDL must use REST nonQuery.');

$table = $db->getTableSchema('sensor_reading');
expect($table !== null, 'DESCRIBE must produce a table schema.');
expect($table->primaryKey === ['time', 'device_id'], 'time and tag columns must form the Yii row identity.');
expect($table->columns['temperature']->type === 'float', 'IoTDB type metadata must map to Yii types.');

$failNext = true;
$failed = false;
try {
    $db->createCommand('SELECT broken')->queryAll();
} catch (Exception $e) {
    $failed = true;
}
expect($failed, 'IoTDB REST error envelopes must surface as Yii DB exceptions.');

$db->close();
echo "IoTDB provider transport tests passed.\n";

if (getenv('IOTDB_TEST_REST_URL')) {
    new \yii\console\Application(['id' => 'iotdb-smoke', 'basePath' => $projectRoot]);
    $db = new Connection([
        'baseUrl' => getenv('IOTDB_TEST_REST_URL'),
        'username' => getenv('IOTDB_TEST_USER'),
        'password' => getenv('IOTDB_TEST_PASSWORD'),
        'rowLimit' => 10,
    ]);
    $database = 'yii_test_' . bin2hex(random_bytes(8));
    $db->createCommand('CREATE DATABASE ' . $database)->execute();
    try {
        $db->database = $database;
        $db->createCommand()->createTable('samples', [
            'time' => 'timestamp TIME', 'device' => 'string TAG', 'reading' => 'double FIELD',
        ])->execute();
        $device = "O'Reilly\\sensor";
        $db->createCommand()->insert('samples', ['time' => 1700000000000, 'device' => $device, 'reading' => 72.5])->execute();
        $row = (new \yii\db\Query())->from('samples')->where(['device' => $device])->limit(1)->one($db);
        expect($row && $row['device'] === $device && (float) $row['reading'] === 72.5, 'QueryBuilder round trip failed.');
        expect($db->getTableSchema('samples', true)->primaryKey === ['time', 'device'], 'Schema must map TIME and TAG.');
    } finally {
        $db->database = null;
        $db->createCommand('DROP DATABASE ' . $database)->execute();
        $db->close();
    }
    echo "IoTDB Yii QueryBuilder live round trip passed.\n";
}
