<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");

class OrderInstallComplete extends OrderStatusHandlerAbstract
{
    protected $orderStatusService;
    /**
     * @var OrderStatusModel
     */
    protected $orderStatusModel;

    public function __construct($orderStatusModel, $callType, BaseService $baseService, OrderStatusService $serviceInstance)
    {
        parent::__construct($orderStatusModel, $callType, $baseService);
        $this->orderStatusService = $serviceInstance;
        $this->orderStatusModel = $orderStatusModel;
    }

    public function handle($od_id, array $params, bool $useTransaction): array
    {
        $customParams = [];
        $required = ['ct_status'];

        return $this->processOrderUpdate(
            $od_id,
            $params,
            $customParams,
            $useTransaction,
            function ($orderStatusModel, $od_id, $params) {
                /**
                 * @var OrderStatusModel $orderStatusModel
                 */

                if ($orderStatusModel->updateOrderInstallComplete($od_id, $params)) {
                    return true;
                } else {
                    throw new CustomException("주문 상태값 변경 (설치 - 배송완료) 실패", '0009', [
                        'od_id' => $od_id
                    ]);
                }
            }
        );
    }
}
