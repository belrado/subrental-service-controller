<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");
require_once(G5_PATH . "/subrental_api/modules/OrderModel.php");

/**
 * 회수요청 변경 처리 (회수주문 생성은 별도)
 */
class OrderReturnChange extends OrderStatusHandlerAbstract
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
            return $this->returnData("error", "네트워크 오류, 필수값이 없습니다.1", [$params], "0009");
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

                if ($order['od_status'] === '회수요청취소') {
                  throw new CustomException('이미 회수요청취소된 주문입니다.', '0010',
                      ['od_id' => $od_id, 'params' => $params, 'order status' => $order['od_status']]);
                }

                if ($order['od_status'] === '입고완료') {
                  throw new CustomException('이미 입고완료된 주문입니다.', '0010',
                      ['od_id' => $od_id, 'params' => $params, 'order status' => $order['od_status']]);
                }

                if ($order['od_status'] !== $params['before_change']) {
                  throw new CustomException('서버에 저장된 주문상태와 변경 전 주문상태가 서로 다릅니다. 새로고침 후 시도해주세요.', '0010',
                      ['od_id' => $od_id, 'params' => $params, 'order status' => $order['od_status']]);
                }

                if ($orderStatusModel->updateOrderReturnChange($od_id, [
                    'od_status' => $order['od_status'],
                    'od_id_cancel' => $order['od_id_cancel'],
                    'mb_id' => $this->loginMb['mb_id']
                ])) {
                    return true;
                } else {
                    throw new CustomException("주문 상태값 변경 (회수요청취소) 실패", '0009', [
                        'od_id' => $od_id
                    ]);
                }
            }
        );
    }
}
