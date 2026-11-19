<?php

namespace yii\db\iotdb;

use yii\base\InvalidConfigException;

/** HTTP transport for the IoTDB Table REST v1 API. */
class HttpClient
{
    private $_db;
    private $_transport;

    /**
     * @param Connection $db database connection configuration
     * @param callable|null $transport optional callback used by tests
     */
    public function __construct(Connection $db, $transport = null)
    {
        $this->_db = $db;
        $this->_transport = $transport;
    }

    /**
     * Executes a query and normalizes IoTDB's response to row arrays.
     * @param string $sql
     * @param string|null $database
     * @return array
     */
    public function query($sql, $database = null)
    {
        $result = $this->queryResult($sql, $database);
        return $result['rows'];
    }

    /**
     * Executes a query and returns both rows and column names.
     * @param string $sql
     * @param string|null $database
     * @return array
     */
    public function queryResult($sql, $database = null)
    {
        $response = $this->request('query', $sql, $database);
        return [
            'rows' => $this->normalizeRows($response),
            'columns' => $this->normalizeColumns($response),
        ];
    }

    /**
     * Executes a statement that does not return rows.
     * @param string $sql
     * @param string|null $database
     * @return array
     */
    public function nonQuery($sql, $database = null)
    {
        return $this->request('nonQuery', $sql, $database);
    }

    /**
     * Returns an affected row count when the server provides one.
     * IoTDB REST otherwise reports successful execution without a row count.
     * @param array $response
     * @return int
     */
    public function affectedRows($response)
    {
        if (isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        foreach (['affectedRows', 'affected_rows', 'updateCount', 'update_count'] as $key) {
            if (isset($response[$key])) {
                return (int) $response[$key];
            }
        }
        return 0;
    }

    /**
     * Sends a SQL request using IoTDB's Table REST v1 endpoint.
     * @param string $operation query or nonQuery
     * @param string $sql
     * @param string|null $database
     * @return array
     */
    public function request($operation, $sql, $database = null)
    {
        if (!in_array($operation, ['query', 'nonQuery'], true)) {
            throw new InvalidConfigException('Unsupported IoTDB REST operation.');
        }
        $payload = ['sql' => $sql];
        $database = $database === null ? $this->_db->database : $database;
        if ($database !== null && $database !== '') {
            $payload['database'] = $database;
        }
        if ($operation === 'query' && $this->_db->rowLimit !== null) {
            $payload['row_limit'] = (int) $this->_db->rowLimit;
        }
        $body = json_encode($payload);
        if ($body === false) {
            throw new \RuntimeException('Could not encode the IoTDB REST request as JSON.');
        }

        $baseUrl = rtrim($this->_db->baseUrl, '/');
        $prefix = '/' . trim($this->_db->endpointPrefix, '/');
        $url = $baseUrl . $prefix . '/' . $operation;
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($this->_db->username !== null || $this->_db->password !== null) {
            $credentials = (string) $this->_db->username . ':' . (string) $this->_db->password;
            $headers[] = 'Authorization: Basic ' . base64_encode($credentials);
        }

        if ($this->_transport !== null) {
            $result = call_user_func(
                $this->_transport,
                $url,
                $headers,
                $body,
                $this->_db->timeout,
                $this->_db->verifyTls
            );
            if (!is_array($result) || count($result) < 2) {
                throw new \RuntimeException('The IoTDB test transport must return [HTTP status, response body].');
            }
            $status = (int) $result[0];
            $responseBody = $result[1];
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $body,
                    'timeout' => $this->_db->timeout,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => (bool) $this->_db->verifyTls,
                    'verify_peer_name' => (bool) $this->_db->verifyTls,
                ],
            ]);
            $responseBody = @file_get_contents($url, false, $context);
            $status = 0;
            if (isset($http_response_header[0])
                && preg_match('/\s([0-9]{3})\s/', $http_response_header[0], $matches)
            ) {
                $status = (int) $matches[1];
            }
            if ($responseBody === false) {
                throw new \RuntimeException('Could not connect to the configured IoTDB REST endpoint.');
            }
        }

        $response = json_decode($responseBody, true);
        if (!is_array($response)) {
            throw new \RuntimeException('IoTDB REST returned a non-JSON response (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            $message = isset($response['message']) ? $response['message'] : 'HTTP request failed';
            throw new \RuntimeException('IoTDB REST HTTP ' . $status . ': ' . $message);
        }
        if (isset($response['code']) && (int) $response['code'] !== 200) {
            $message = isset($response['message']) ? $response['message'] : 'IoTDB rejected the SQL statement';
            throw new \RuntimeException('IoTDB error ' . (int) $response['code'] . ': ' . $message);
        }

        return $response;
    }

    /**
     * Converts row-oriented and column-oriented query payloads to rows.
     * @param array $response
     * @return array
     */
    public function normalizeRows($response)
    {
        $response = $this->resultPayload($response);
        $columns = $this->normalizeColumns($response);
        $values = isset($response['values']) && is_array($response['values']) ? $response['values'] : [];
        if (empty($columns) && !empty($values) && is_array($values[0])) {
            $columns = array_map('strval', array_keys($values[0]));
        }
        if (empty($columns)) {
            return [];
        }

        $timestamps = isset($response['timestamps']) && is_array($response['timestamps'])
            ? $response['timestamps']
            : [];
        $rows = [];
        $columnCount = count($columns);

        if (!empty($values) && is_array($values[0]) && count($values[0]) === $columnCount) {
            foreach ($values as $valueRow) {
                if (!is_array($valueRow)) {
                    continue;
                }
                $row = [];
                foreach ($columns as $index => $column) {
                    $row[$column] = array_key_exists($index, $valueRow)
                        ? $valueRow[$index]
                        : (array_key_exists($column, $valueRow) ? $valueRow[$column] : null);
                }
                $rows[] = $row;
            }
            return $rows;
        }

        if (count($values) === $columnCount) {
            $rowCount = 0;
            foreach ($values as $columnValues) {
                if (is_array($columnValues)) {
                    $rowCount = max($rowCount, count($columnValues));
                }
            }
            $rowCount = max($rowCount, count($timestamps));
            for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
                $row = [];
                foreach ($columns as $columnIndex => $column) {
                    $columnValues = isset($values[$columnIndex]) && is_array($values[$columnIndex])
                        ? $values[$columnIndex]
                        : [];
                    $row[$column] = array_key_exists($rowIndex, $columnValues)
                        ? $columnValues[$rowIndex]
                        : null;
                }
                $rows[] = $row;
            }
            return $rows;
        }

        if (empty($values) && !empty($timestamps)) {
            $timeColumn = null;
            foreach ($columns as $column) {
                if (strcasecmp($column, 'time') === 0) {
                    $timeColumn = $column;
                    break;
                }
            }
            if ($timeColumn !== null) {
                foreach ($timestamps as $timestamp) {
                    $rows[] = [$timeColumn => $timestamp];
                }
            }
        }

        return $rows;
    }

    /**
     * Extracts column labels from either top-level or nested REST query data.
     * @param array $response
     * @return array
     */
    public function normalizeColumns($response)
    {
        $response = $this->resultPayload($response);
        if (isset($response['data']) && is_array($response['data'])
            && (isset($response['data']['values']) || isset($response['data']['columnNames'])
                || isset($response['data']['column_names']) || isset($response['data']['columns']))
        ) {
            $response = $response['data'];
        }
        $columns = [];
        foreach (['column_names', 'columnNames', 'columns', 'expressions'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $columns = $response[$key];
                break;
            }
        }
        foreach ($columns as $index => $column) {
            if (is_array($column)) {
                $columns[$index] = isset($column['columnName'])
                    ? $column['columnName']
                    : (isset($column['name']) ? $column['name'] : (string) $index);
            }
        }
        return array_values($columns);
    }

    /**
     * Unwraps the optional data object used by REST response versions.
     * @param array $response
     * @return array
     */
    private function resultPayload($response)
    {
        if (isset($response['data']) && is_array($response['data'])
            && (isset($response['data']['values']) || isset($response['data']['columnNames'])
                || isset($response['data']['column_names']) || isset($response['data']['columns']))
        ) {
            return $response['data'];
        }
        return $response;
    }
}
