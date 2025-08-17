<?php

require_once(G5_PATH . '/subrental_api/subrental_base.php');
require_once(G5_PATH . '/subrental_api/helpers/handlerLoader.php');
require_once(G5_PATH . "/subrental_api/services/exceptions/CustomException.php");

class BaseService extends subrental_base
{
    public $orderLogDir;
    public $mysql;
    public $fixedRealWarehouseName;
    public $maxFileSizeBytes;
    public $maxFIleSizeMb;

    public function __construct()
    {
        parent::__construct();
        $this->mysql = $this->getProperty('mysql');
        $this->fixedRealWarehouseName = $this->getProperty('fixedRealWarehouseName');
        $this->maxFileSizeBytes = $this->getProperty('maxFileSizeBytes');
        $this->maxFIleSizeMb = $this->getProperty('maxFIleSizeMb');
    }

    public function getKeys($data): array
    {
        return parent::getKeys($data);
    }

    public function setUpdateMultipleQuery(string $table, string $pk, array $updateData, array $extraConditions = []): string
    {
        return parent::setUpdateMultipleQuery($table, $pk, $updateData, $extraConditions);
    }

    public function setUpdateMultipleInnerJoinedTablesQuery(string $mainTable, array $joins, string $pk, array $updateData, array $conditions = []): string
    {
        return parent::setUpdateMultipleInnerJoinedTablesQuery($mainTable, $joins, $pk, $updateData, $conditions);
    }

    public function setInsertMultipleQuery(string $table, array $insertColumn, array $insertData): string
    {
        return parent::setInsertMultipleQuery($table, $insertColumn, $insertData);
    }

    public function setInsertMultipleBarcodeHistoryQuery(array $insertData): string
    {
        return parent::setInsertMultipleBarcodeHistoryQuery($insertData);
    }

    /**
     * @param $required
     * @param $params
     * @return bool
     */
    public function validateParams($required, $params): bool
    {
        $paramsKey = array_keys($params);
        foreach ($required as  $value) {
            if (!in_array($value, $paramsKey)) {
                return false;
            }
        }
        return true;
    }

    public function returnData($status = "success", $message = '', $data = [], $code = '0000', $errorLog = ['dir' => null, 'data' => []]): array
    {
        if (!empty($errorLog['dir']) && count($errorLog['data']) > 0) {
            $this->logger->error($errorLog['data'], $errorLog['dir']);
        }

        return ["status" => $status, "message" => $message, 'result' => $data, 'code' => $code];
    }


}
