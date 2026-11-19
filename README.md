# Yii2 TDengine and IoTDB adapters

This package provides the existing Yii2 TDengine adapter and an IoTDB Table
adapter using Apache IoTDB's Table REST v1 SQL endpoints. It requires an IoTDB 2.x
server with the Table model and REST service enabled.

## IoTDB connection

```php
'components' => [
    'db' => [
        'class' => 'yii\\db\\iotdb\\Connection',
        'baseUrl' => getenv('IOTDB_REST_URL'), // server root, for example https://iotdb.example:18080
        'database' => getenv('IOTDB_DATABASE'),
        'username' => getenv('IOTDB_USERNAME'),
        'password' => getenv('IOTDB_PASSWORD'),
        'timeout' => 30,
        'verifyTls' => true,
    ],
],
```

The adapter sends SQL to `/rest/table/v1/query` and
`/rest/table/v1/nonQuery` as JSON. Requests include the configured `database`;
queries can also set the API's `row_limit` through the connection's `rowLimit`
property. The Table REST endpoint selects Table SQL dialect server-side; its SQL
request schema does not include a `dialect` field. Credentials are sent with
HTTP Basic authentication. Keep credentials in the application's environment
or secret provider, and use HTTPS with TLS verification outside local
development.

## Yii queries

Yii query objects and commands can be used for supported IoTDB Table SQL:

```php
use yii\db\Query;

$db = Yii::$app->db;
$rows = (new Query())
    ->select(['time', 'device_id', 'temperature'])
    ->from('sensor_reading')
    ->where(['device_id' => 'pump-17'])
    ->orderBy(['time' => SORT_DESC])
    ->limit(100)
    ->all(Yii::$app->db);

$db->createCommand(
    'INSERT INTO sensor_reading (time, device_id, temperature) VALUES (:time, :device, :value)',
    [':time' => 1735689600000, ':device' => 'pump-17', ':value' => 21.5]
)->execute();
```

Reads are returned as materialized REST result rows, so apply `LIMIT` for large
queries. Yii value parameters are rendered as escaped SQL literals because the
REST SQL endpoint does not expose server-side prepared statements. Use values
for data only; table and column identifiers must be selected from trusted code.

## Table DDL

IoTDB table columns have semantic categories. Specify them in column type
definitions, or in the `categories` option:

```php
$db->createCommand()->createTable('sensor_reading', [
    'time' => 'timestamp TIME',
    'device_id' => 'string TAG',
    'site_name' => 'string ATTRIBUTE',
    'temperature' => 'float FIELD',
])->execute();
```

An unclassified column named `time` becomes `TIMESTAMP TIME`; other unclassified
columns become `FIELD`. `createTable()` requires exactly one time column. Yii
abstract types such as `string`, `integer`, `double`, `boolean`, `date`, and
`timestamp` are mapped to IoTDB types. Explicit categories are recommended for
schemas that include tags or attributes.

## Limits

The REST adapter does not provide a PDO handle, server-side prepared statements,
transactions, auto-generated IDs, or reliable affected-row counts (successful
statements without a count return `0`). IoTDB Table mode does not map to Yii
primary/foreign-key, index, default-value, or check-constraint DDL. `UPDATE`,
`UPSERT`, table/column rename, and `TRUNCATE TABLE` are rejected. Yii ActiveRecord
can query and insert into existing tables when the time and tag metadata is
available; applications should supply time values explicitly.

## Offline test

After installing Composer dependencies, run:

```sh
php tests/iotdb/ProviderTest.php
```

The test injects a local transport callback and does not connect to a database.
