<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");
require_once(G5_PATH . "/subrental_api/modules/OrderModel.php");

/**
 * 회수취소완료 [다중처리]
 *
 * 회수취소완료 처리 : 회수요청취소 -> 회수취소완료로 변경 (회수요청취소는 설치팥트너/업체에서 신청만 가능, 최종 변경완료는 관리자에서만 진행)
 * 회수취소완료 -> 관리자 & 사업소에서 가능
 * 회수단계
 * - 회수요청 > 회수준비중 > 회수완료 > 입고완료, 회수취소완료, 회수취소요청(설치파트너만 요청 후 관리자가 회수취소완료 처리)
 * 관리자 (mb_level = 11) = 모든단계에서 회수취소완료 가능
 * 사업소 = 회수요청 단계에서만 회수취소완료 가능
 * (회수취소완료후 이전상태로 상태변경 못함)
 * 회수취소요청 -> 설치파트너/업체 에서 가능 (관리자에서 회수취소완료 처리)
 * 회수요청, 회수준비중 두단계에서만  회수취소요청 신청 가능
 * (회수취소요청 후 회수요청, 회수준비중 등 상태변경 못함)
 */
class OrderReturnCancelComplete extends OrderStatusHandlerAbstract
{
    /**
     * @var OrderStatusModel
     */
    protected $orderStatusModel;
    protected $orderModel;
    protected $loginMb;

    public function __construct($orderStatusModel, $callType, BaseService $baseService)
    {
        parent::__construct($orderStatusModel, $callType, $baseService);
        $this->orderStatusModel = $orderStatusModel;
        $this->orderModel = new OrderModel();
        global $member;
        $this->loginMb = $member;
    }

    public function handle($od_id, array $params, bool $useTransaction): array
    {
        $customParams = [];
        $required = ['before_change'];
        $od_ids = [];

        // 배열로 처리
        if (is_array($od_id)) {
            $od_ids = $od_id;
        } else {
            $od_ids = [$od_id];
        }

        foreach ($params as $row) {
            if (!$this->baseService->validateParams($required, $row)) {
                return $this->returnData("error", "네트워크 오류, 필수값이 없습니다.", [$row], "0009");
            }
        }

        // 회수주문일때만 회수요청취소처리
        if (!$this->orderModel->validateCancelRequestOrder($od_ids)) {
            return $this->returnData("error", "회수취소완료가 가능한 주문이 아닙니다.", ['param_od_id' => $od_ids], "0009");
        }

        return $this->processOrderUpdate(
            $od_ids,
            $params,
            $customParams,
            $useTransaction,
            function ($orderStatusModel, $od_ids, $params) {
                /**
                 * @var OrderStatusModel $orderStatusModel
                 */

                $isAdmin = ($this->loginMb['mb_level'] === '11');

                $restrictedStatuses = [
                    '회수취소요청' => '현재 회수취소요청중인 주문 입니다.',
                    '회수완료' => '이미 회수완료 주문입니다.',
                    '입고완료' => '이미 입고완료된 주문입니다.'
                ];

                if (!$orders = $this->orderModel->getOrderByBarcode($od_ids, "gso.od_id, gso.od_status, gso.od_id_cancel, gso.od_time, gso.sr_is_visit_return, sism.od_id as barcode_od_id, sism.sr_stock_id")) {
                    throw new CustomException('주문 ID가 유효하지 않습니다.', '0009', [
                        'params_od_id' => $od_ids, 'params' => $params
                    ]);
                }

                foreach ($orders as $len => $row) {
                    foreach ($params as $param) {
                        if ($row['od_id'] == $param['od_id']) {
                            $orders[$len]['before_change'] = $param['before_change'];
                            $orders[$len]['mb_id'] = $this->loginMb['mb_id'];
                            $row['before_change'] = $param['before_change'];
                        }
                    }

                    if ($row['od_status'] === '회수취소완료') {
                        throw new CustomException("{$row['od_id']} 이미 회수취소완료된 주문입니다.", '0010', [
                            'params_od_id' => $row['od_id'], 'order status' => $row['od_status']
                        ]);
                    }

                    if (!$isAdmin && $row['before_change'] !== '회수요청') {
                        throw new CustomException("{$row['od_id']} 회수요청단게 에서만 회수취소완료 처리가 가능합니다.", '0010', [
                            'params_od_id' => $row['od_id'], 'before_change' => $row['before_change'],
                        ]);
                    }

                    if (!$isAdmin && isset($restrictedStatuses[$row['od_status']])) {
                        throw new CustomException("{$row['od_id']} {$restrictedStatuses[$row['od_status']]}", '0010', [
                            'params_od_id' => $row['od_id'], 'order_status' => $row['od_status']
                        ]);
                    }

                    if ($isAdmin && $row['od_status'] === '입고완료' && !empty($row['barcode_od_id']) && $row['barcode_od_id'] !== $row['od_id']) {
                        throw new CustomException("{$row['od_id']}의 바코드가 입고된 후 다른 주문에 할당되어 있습니다.", '0010', [
                            'params_od_id' => $row['od_id'], 'barcode_od_id' => $row['barcode_od_id']
                        ]);
                    }

                    if ($row['od_status'] !== $row['before_change']) {
                        throw new CustomException("{$row['od_id']} 서버에 저장된 주문상태와 변경 전 주문상태가 서로 다릅니다. 새로고침 후 시도해주세요.", '0010', [
                            'params_od_id' => $row['od_id'], 'order status' => $row['od_status'], 'before_change' => $row['before_change'], $params
                        ]);
                    }
                }

                if ($orderStatusModel->updateOrdersReturnCancelComplete($orders)) {
                    return true;
                } else {
                    throw new CustomException("주문 상태값 변경 (회수취소완료) 실패", '0009', [
                        'params_od_id' => $od_ids
                    ]);
                }
            }
        );
    }
}
