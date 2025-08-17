<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");
require_once(G5_PATH . "/subrental_api/modules/WareHouseInOutModel.php");
require_once(G5_PATH . "/subrental_api/modules/WareHouseStockModel.php");

/**
 * 주문에 바코드 등록 (단건) :: 입출고창고 출고 리스트
 *
 * - 주문서에 바코드 등록시 설치건은 성치파트너 소속창고와 바코드 소속창고가 일치해야함
 * - 주문서에 바코드 등록시 배송건은 현재 cmk파주본사창고에서만 출고함 srw_id = 4 추후 배송에 관해서 어떻게 할지 정해야함 (여러업체가 생성되기때문)
 */
class OrderBarcodeMatch extends OrderStatusHandlerAbstract
{
    protected $orderStatusService;
    /**
     * @var OrderStatusModel
     */
    protected $orderStatusModel;
    protected $inOutModel;
    protected $stockModel;

    public function __construct($orderStatusModel, $callType, BaseService $baseService, OrderStatusService $serviceInstance)
    {
        parent::__construct($orderStatusModel, $callType, $baseService);
        $this->orderStatusService = $serviceInstance;
        $this->orderStatusModel = $orderStatusModel;
        $this->inOutModel = new WareHouseInOutModel();
        $this->stockModel = new WareHouseStockModel();
    }

    public function handle($od_id, array $params, bool $useTransaction): array
    {
        $customParams = [];
        $required = ['srw_id', 'type', 'sr_it_barcode_no'];

        if (!$this->baseService->validateParams($required, $params)) {
            return $this->returnData("error", "네트워크 오류, 필수값이 없습니다.", [$params], "0009");
        }

        if ($params['type'] === 'install') {
            $type = "설치";
        } else {
            $type = "택배";
        }

        // 주문 정보 및 바코드가 등록되어있는지 확인
        if (!$order = $this->inOutModel->getOutboundOrder($od_id)) {
            return $this->returnData("error", "네트워크 오류, 주문 정보를 불러오지 못했습니다.", ['od_id' => $od_id], "0009");
        }

        if ($order['od_status'] !== "주문완료" && $order['od_status'] !== "출고준비중") {
            if ($order['od_status'] === '취소완료') {
                return $this->returnData("error", "취소 처리된 주문입니다.", ['od_id' => $od_id, 'od_status' => $order['od_status']], "0010");
            } else {
                return $this->returnData("error", "출고를 위한 바코드 등록을 할수없는 주문입니다.", ['od_id' => $od_id, 'od_status' => $order['od_status']], "0010");
            }
        }

        if (!empty($order['sr_it_barcode_no'])) {
            return $this->returnData("error", "이미 바코드가 입력되었습니다.", ['od_id' => $od_id, 'sr_it_barcode_no' => $order['sr_it_barcode_no']], "0010");
        }

        if ($params['type'] === 'install') {
            if (empty($order['assigned_srw_id'])) {
                return $this->returnData("error", "설치파트너가 배정되지않은 주문입니다.", [
                    'od_id' => $od_id,
                    'order_type' => 'install',
                    'order_assigned_srw_id' => $order['assigned_srw_id'],
                    'sr_is_visit_return' => $order['sr_is_visit_return']
                ], "0009");
            }

            if ($order['assigned_srw_id'] != $params['srw_id']) {
                return $this->returnData("error", "현재 창고와 주문에 매칭된 설치기사의 소속 창고가 다릅니다.", [
                    'od_id' => $od_id,
                    'order_type' => 'install',
                    'srw_id' => $params['srw_id'],
                    'order_assigned_srw_id' => $order['assigned_srw_id'],
                    'sr_is_visit_return' => $order['sr_is_visit_return']
                ], "0010");
            }
        }

        // 현재 바코드의 소속창고가 입출고창고 관리자 소속창고와 동일한지 검증 (관리자에서 변경가능 & 추후 입고시 회수기사의 소속창고로 변경)
        if (!$checkBarcode = $this->stockModel->getItemStockByBarcode($params['sr_it_barcode_no'])) {
            return $this->returnData("error", "바코드정보를 불러오지 못했습니다.", ['od_id' => $od_id, 'sr_it_barcode_no' => $order['sr_it_barcode_no']], "0009");
        }
        if ($checkBarcode['srw_id'] != $params['srw_id']) {
            return $this->returnData("error", "소속 창고가 변경된 바코드입니다.", ['od_id' => $od_id, 'sr_it_barcode_no' => $order['sr_it_barcode_no']], "0010");
        }

        $customParams['order'] = $order;
        $customParams['srw_id'] = $params['srw_id'];
        $customParams['type'] = $type;
        $customParams['mb_id'] = $this->loginMb['mb_id'];

        return $this->processOrderUpdate(
            $od_id,
            $params,
            $customParams,
            $useTransaction,
            function ($orderStatusModel, $od_id, $params, $customParams) {
                /**
                 * @var OrderStatusModel $orderStatusModel
                 */
                // 바코드 정보 확인
                if (!$customParams['barcode'] = $this->stockModel->getItemStockByBarcode($params['sr_it_barcode_no'])) {
                    throw new CustomException('네트워크 오류, 바코드 정보를 불러오지 못했습니다.', '0009', ['od_id' => $od_id, 'params' => $params]);
                }

                // 사용중인 바코드인지
                if (!empty($customParams['barcode']['od_id'])) {
                    throw new CustomException('이미 사용중인 바코드입니다.', '0010',
                        ['od_id' => $od_id, 'params' => $params, 'barcode' => $customParams['barcode']]);
                }

                // 바코드의 소속창고와 주문에 대해서 확인
                if ($customParams['type'] === 'install') {
                    // 설치건이라면 설치기사 소속창고와 매칭이 되는지 확인
                    if ($customParams['barcode']['srw_id'] != $customParams['order']['assigned_srw_id']) {
                        throw new CustomException('바코드 소속창고와 설치파트너의 소속창고가 다릅니다.', '0009',
                            [
                                'od_id' => $od_id,
                                'params' => $params,
                                'barcode_srw_id' => $customParams['barcode']['srw_id'],
                                'install_order_srw_id' => $customParams['order']['assigned_srw_id']
                            ]
                        );
                    }
                } else {
                    // 배송건은 현재 cmk파주본사창고에서만 보냄 srw_id = 4 추후 배송에 관해서 어떻게 할지 정해야함 (여러업체가 생성되기때문)
                    if ($customParams['barcode']['srw_name'] !== $this->baseService->fixedRealWarehouseName) {
                        // throw new CustomException('택배는 ['.$this->baseService->fixedRealWarehouseName.'] 바코드만 매칭 가능합니다.', '0009', ['params' => $params]);
                    }
                }

                // 임대중인지 확인
                if ($customParams['barcode']['barcode_location_status'] === 'rental'
                    || $customParams['barcode']['barcode_location_status'] === 'offline_rental') {
                    throw new CustomException('출고 처리된 바코드입니다.', '0010',
                        ['od_id' => $od_id, 'params' => $params, 'barcode' => $customParams['barcode']]);
                }
                // 소독 여부 확인
                if ($customParams['barcode']['disinfection_yn'] === 'N') {
                    throw new CustomException('소독 처리가 안된 바코드입니다.', '0010',
                        ['od_id' => $od_id, 'params' => $params, 'barcode' => $customParams['barcode']]);
                }
                // 바코드해제가 완료되었는지 barcode_cancel_yn
                if ($customParams['barcode']['barcode_cancel_yn'] === 'N') {
                    throw new CustomException('해지처리가 안된 바코드입니다.', '0010',
                        ['od_id' => $od_id, 'params' => $params, 'barcode' => $customParams['barcode']]);
                }

                if ($orderStatusModel->updateOutboundOrderBarcode($od_id, $params['sr_it_barcode_no'], $customParams)) {
                    return true;
                } else {
                    throw new CustomException("바코드 등록 실패", '0009', [
                        'od_id' => $od_id
                    ]);
                }
            }
        );
    }
}
