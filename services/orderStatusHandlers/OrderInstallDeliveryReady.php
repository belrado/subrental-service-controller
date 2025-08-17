<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");
require_once(G5_PATH . "/subrental_api/modules/OrderModel.php");

/**
 * 출고준비중(설치)
 */
class OrderInstallDeliveryReady extends OrderStatusHandlerAbstract
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

        if (!$this->baseService->validateParams($required, $params)) {
            return $this->returnData("error", "네트워크 오류, 필수값이 없습니다.", [$params], "0009");
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

                if (!$order = $this->orderModel->getOrdersByIds($od_id)) {
                    throw new CustomException('주문 ID가 유효하지 않습니다.', '0009',
                      ['od_id' => $od_id, 'params' => $params]);
                }

                if ($order['od_status'] === '미처리') {
                    throw new CustomException('미처리된 주문입니다.', '0010',
                      ['od_id' => $od_id, 'params' => $params, 'order status' => $order['od_status']]);
                }

                if ($order['od_status'] === '출고완료') {
                    throw new CustomException('이미 출고완료 처리된 주문입니다.', '0010',
                      ['od_id' => $od_id, 'params' => $params, 'order status' => $order['od_status']]);
                }

                if ($order['od_status'] === '배송완료') {
                    throw new CustomException('이미 배송완료 처리된 주문입니다.', '0010',
                      ['od_id' => $od_id, 'params' => $params, 'order status' => $order['od_status']]);
                }

                if ($order['od_status'] !== $params['before_change']) {
                    throw new CustomException('서버에 저장된 주문상태와 변경 전 주문상태가 서로 다릅니다. 새로고침 후 시도해주세요.', '0010',
                      ['od_id' => $od_id, 'params' => $params, 'order status' => $order['od_status']]);
                }

                if ($orderStatusModel->updateOrderInstallDeliveryReady($od_id, [
                    'od_status' => $order['od_status'],
                    'mb_id' => $this->loginMb['mb_id']
                ])) {
                    return true;
                } else {
                    throw new CustomException("주문 상태값 변경 (출고준비중) 실패", '0009', [
                        'od_id' => $od_id
                    ]);
                }
            }
        );
    }
}
