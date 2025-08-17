<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");
require_once(G5_PATH . "/subrental_api/modules/WareHouseInOutModel.php");
require_once(G5_PATH . "/lib/eroumcare.lib.php");

class OrderOutbound extends OrderStatusHandlerAbstract
{
    protected $orderStatusService;
    /**
     * @var OrderStatusModel
     */
    protected $orderStatusModel;
    protected $inOutModel;


    public function __construct($orderStatusModel, $callType, BaseService $baseService, OrderStatusService $serviceInstance)
    {
        parent::__construct($orderStatusModel, $callType, $baseService);
        $this->orderStatusService = $serviceInstance;
        $this->orderStatusModel = $orderStatusModel;
        $this->inOutModel = new WareHouseInOutModel();
    }

    /**
     * @param $od_id : 다중처리를위해 배열로 받음
     * @param array $params
     * @param bool $useTransaction
     * @return array
     */
    public function handle($od_id, array $params, bool $useTransaction): array
    {
        $od_ids = $od_id;
        $customParams = [];
        $required = ['srw_id'];
        if (!$this->baseService->validateParams($required, $params) || !is_array($od_ids)) {
            return $this->returnData("error", "네트워크 오류, 필수값이 없습니다.", ['params' =>$params], "0009");
        }

        if ($params['type'] === 'install') {
            $type = "설치";
        } else {
            $type = "택배";
        }

        $whereQuery = " gso.od_id in ('".implode("', '", $od_ids)."') ";

        if (!$checkList = $this->inOutModel->getOutboundOrderList($params['srw_id'], $whereQuery)) {
            return $this->returnData("error", "네트워크 오류, 주문 정보를 불러오지 못했습니다.", ['params_od_id' => $od_id], "0010");
        }

        foreach ($checkList as $row) {
            if (empty($row['sr_it_barcode_no'])) {
                return $this->returnData("error",
                    "{$type} 주문 항목에 바코드가 누락되었습니다.", ['params_od_id' => $row['od_id']], "0010");
            }
            // 현재 바코드의 소속창고가 입출고창고 관리자 소속창고와 동일한지 검증 (관리자에서 변경가능 & 추후 입고시 회수기사의 소속창고로 변경)
            if ($row['srw_id'] != $params['srw_id']) {
                return $this->returnData("error",
                    "출고 처리 [{$row['sr_it_barcode_no']}] 소속 창고가 변경된 바코드입니다.", ['params_od_id' => $row['od_id']], "0010");
            }

            if ($params['type'] === 'parcel'
                && ( preg_replace('/\s+/', '', $row['ct_delivery_num']) === '' || empty($row['ct_delivery_company']))) {
                return $this->returnData("error", "출고 처리 택배 주문 항목에 택배 운송장 번호가 누락되었습니다.", ['params_od_id' => $row['od_id']], "0010");
            }
            if (($row['ct_status'] !== "주문완료" && $row['ct_status'] !== "출고준비중") || $row['barcode_location_status'] === 'rental') {
                return $this->returnData("error", "출고 처리 {$type} 주문 항목에 이미 출고 처리된 항목이 있습니다.", ['params_od_id' => $row['od_id']], "0010");
            }
            if ($row['barcode_location_status'] === 'offline_rental') {
                return $this->returnData("error", "출고 처리 {$type} 주문 항목에 오프라인 임대중인 항목이 있습니다.", ['params_od_id' => $row['od_id']], "0010");
            }

            if ($row['disinfection_yn'] === 'N') {
                $message = "출고 처리 [{$row['sr_it_barcode_no']}] 소독처리가 안된 상태입니다.";
                if (isset($params['isMobile']) && $params['isMobile']) {
                    return $this->returnData("error", $message, ['od_id' => $row['od_id'], 'params_od_id' => $row['od_id']], "0009");
                }
                return $this->returnData("error", $message, ['params_od_id' => $row['od_id']], "0010");
            }

            if ($params['type'] === 'install') {
                if (empty($row['sr_install_partner_id']) || $row['sr_install_partner_id'] == '') {
                    $message = "출고 처리 [{$row['sr_it_barcode_no']}] 설치파트너 배정이 누락된 주문입니다.";
                    if (isset($params['isMobile']) && $params['isMobile']) {
                        return $this->returnData("error", $message, ['od_id' => $row['od_id'], 'params_od_id' => $row['od_id']], "0009");
                    }
                    return $this->returnData("error", $message, ['params_od_id' => $row['od_id']], "0010");
                }
                if ($row['sr_happy_call_yn'] === 'N') {
                    $message = "출고 처리 [{$row['sr_it_barcode_no']}] 해피콜 완료가 되지 않은 주문입니다.";
                    if (isset($params['isMobile']) && $params['isMobile']) {
                        return $this->returnData("error", $message, ['od_id' => $row['od_id'], 'params_od_id' => $row['od_id']], "0009");
                    }
                    return $this->returnData("error", $message, ['params_od_id' => $row['od_id']], "0010");
                }
            }

            if ($row['barcode_cancel_yn'] === 'N') {
                $message = "출고 처리 [{$row['sr_it_barcode_no']}] 상품은 바코드 해지가 안된 상태입니다.";
                if (isset($params['isMobile']) && $params['isMobile']) {
                    return $this->returnData("error", $message, ['od_id' => $row['od_id'], 'params_od_id' => $row['od_id']], "0009");
                }
                return $this->returnData("error", $message, ['params_od_id' => $row['od_id']], "0010");
            }
            if ($row['damaged_yn'] === 'Y') {
                $message = "출고 처리 [{$row['sr_it_barcode_no']}] 상품은 하자가 있는 제품입니다.";
                if (isset($params['isMobile']) && $params['isMobile']) {
                    return $this->returnData("error", $message, ['od_id' => $row['od_id'], 'params_od_id' => $row['od_id']], "0009");
                }
                return $this->returnData("error", $message, ['params_od_id' => $row['od_id']], "0010");
            }
        }

        $customParams['type'] = $type;
        $customParams['checkOrders'] = $checkList;
        $customParams['mb_id'] = $this->loginMb['mb_id'];

        return $this->processOrderUpdate(
            $od_ids,
            $params,
            $customParams,
            $useTransaction,
            function ($orderStatusModel, $od_ids, $params, $customParams) {
                /**
                 * @var OrderStatusModel $orderStatusModel
                 */
                if ($orderStatusModel->updateOutboundOrder($od_ids, $customParams)) {
                    return true;
                } else {
                    throw new CustomException("출고 처리 실패", '0009', [
                        'params_od_ids' => $od_ids
                    ]);
                }
            }
        );
    }
}
