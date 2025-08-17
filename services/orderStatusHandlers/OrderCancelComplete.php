<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");
require_once(G5_PATH . "/subrental_api/modules/OrderModel.php");

/**
 * 취소완료 [한건씩]
 *
 * 관리자 (callType: admin) ct_status 배송완료전 주문만 취소 가능
 * 사업소 (callType: company) ct_status 주문완료만 주문취소 가능
 * 설치업체/파트너 (callType: install) ct_status ??
 */
class OrderCancelComplete extends OrderStatusHandlerAbstract
{
    /**
     * @var OrderStatusModel
     */
    protected $orderStatusModel;
    protected $orderModel;

    public function __construct($orderStatusModel, $callType, BaseService $baseService)
    {
        parent::__construct($orderStatusModel, $callType, $baseService);
        $this->orderStatusModel = $orderStatusModel;
        $this->orderModel = new OrderModel();
    }

    /**
     * @param $od_id
     * @param array $params [before_change]
     * @param bool $useTransaction
     * @return array
     */
    public function handle($od_id, array $params, bool $useTransaction): array
    {
        $customParams = [];
        $required = ['action_type', 'before_change'];

        if (!$this->baseService->validateParams($required, $params)) {
            return $this->returnData("error", "네트워크 오류, 필수값이 없습니다.", [$params], "0009");
        }

        if ($this->orderModel->validateCancelRequestOrder($od_id)) {
            $errorMessage = "현재 회수진행중 또는 입고완료된 주문으로, 주문상태 변경이 불가능합니다.";
            return $this->returnData("error", $errorMessage, [], "0009");
        }

        return $this->processOrderUpdate(
            $od_id,
            $params,
            $customParams,
            $useTransaction,
            function ($orderStatusModel, $od_id, $params) {
                /**
                 * @var OrderStatusModel $orderStatusModel
                 */
                if (!$order = $this->orderModel->getOrdersByIds($od_id, "sism.sr_stock_id, sism.barcode_location_status, gsc.ct_status")) {
                    throw new CustomException('주문이 유효하지 않습니다.', '0009',
                        ['od_id' => $od_id, 'params' => $params]);
                }

                if ($order['ct_status'] === '취소완료') {
                    throw new CustomException('이미 취소 완료된 주문입니다.', '0010',
                        ['od_id' => $od_id, 'params' => $params, 'cart status' => $order['ct_status']]);
                }

                if ($order['ct_status'] !== $params['before_change']) {
                    throw new CustomException('서버에 저장된 주문상태와 변경 전 주문상태가 서로 다릅니다. 새로고침 후 시도해주세요.', '0010',
                        ['od_id' => $od_id, 'params' => $params, 'cart status' => $order['ct_status']]);
                }

                if (isset($params['action_type'])) {
                    // 사업소
                    if ($params['action_type'] === 'company' && ($order['ct_status'] !== '주문완료' || $params['before_change'] !== '주문완료')) {
                        throw new CustomException('주문완료단계가 아닌 주문은 취소할 수 없습니다.', '0010',
                            ['od_id' => $od_id, 'params' => $params, 'cart status' => $order['ct_status']]);
                    }
                    // 설치파트너/업체 확인단계 추가해야함
                }

                // ~ 관리자
                if ($order['ct_status'] === '배송완료' || $params['before_change'] === '배송완료') {
                    throw new CustomException('배송완료된 주문은 취소할 수 없습니다.', '0010',
                        ['od_id' => $od_id, 'params' => $params, 'cart status' => $order['ct_status']]);
                }

                $logMessage = "{$this->callType} - {$params['before_change']} > 취소완료 [주문번호: {$od_id}]";

                if ($orderStatusModel->updateOrderCancelComplete($od_id, [
                    'order' => $order,
                    'before_change' => $params['before_change'],
                    'mb_id' => $this->loginMb['mb_id'],
                    'log_message' => $logMessage,
                ])) {
                    return true;
                } else {
                    throw new CustomException("주문 상태값 변경 (취소완료) 실패", '0009', [
                        'od_id' => $od_id
                    ]);
                }
            }
        );
    }
}
