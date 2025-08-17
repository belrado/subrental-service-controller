<?php

require_once(G5_PATH . "/subrental_api/services/BaseService.php");
require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerInterface.php");
require_once(G5_PATH . "/subrental_api/services/exceptions/CustomException.php");

abstract class OrderStatusHandlerAbstract implements OrderStatusHandlerInterface
{
    protected $orderStatusModel;
    protected $baseService;
    protected $callType;
    protected $logDir;
    protected $od_id;
    protected $loginMb;

    public function __construct($serviceModel, $callType, BaseService $baseService)
    {
        global $member;
        $this->loginMb = $member;

        $this->orderStatusModel = $serviceModel;
        $this->orderStatusModel->setCallType($callType);
        $this->baseService = $baseService;
        $this->callType = $callType;
        $this->logDir = $this->baseService->orderLogDir;
    }

    public function returnData($status = "success", $message = "", array $data = [], $code = "0000", $log = true): array
    {
        if ($log) {
            $errorLog = [
                'dir' => $this->logDir,
                'data' => [
                    'callType' => $this->callType,
                    'handler' => get_class($this),
                    'message' => $message,
                    'errorCode' => $code,
                    'data' => $data
                ]
            ];
            return $this->baseService->returnData($status, $message, $data, $code, $errorLog);
        } else if (is_array($log)) {
            return $this->baseService->returnData($status, $message, $data, $code, $log);
        } else {
            return $this->baseService->returnData($status, $message, $data, $code);
        }
    }

    /**
     * @param $od_id int | array
     * @param array $params
     * @param array $customParams
     * @param bool $useTransaction
     * @param Closure $businessLogic
     * @return array
     */
    public function processOrderUpdate($od_id, array $params, array $customParams, bool $useTransaction, Closure $businessLogic): array
    {
        if ($useTransaction) {
            $this->baseService->mysql->begin_transaction();
        }

        try {
            // 비즈니스 로직 실행 :: handler 클래스에서 정의
            $result = $businessLogic($this->orderStatusModel, $od_id, $params, $customParams);

            $resultData = is_array($result) ? $result : [];

            if ($useTransaction) {
                $this->baseService->mysql->commit();
            }

            return $this->baseService->returnData("success", "", $resultData);

        } catch (CustomException $e) {
            if ($useTransaction) {
                $this->baseService->mysql->rollback();
            }

            return $this->returnData("error", $e->getMessage(), $e->getErrorData(), $e->getErrorCode());
        }
    }
}
