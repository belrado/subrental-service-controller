<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");
require_once(G5_PATH . "/subrental_api/modules/WareHouseInOutModel.php");

/**
 * 회수완료 (택배건)
 *
 * 회수준비중 > 회수완료
 * 매일 00:01 크론 처리
 */
class OrderDeliveryReturnComplete extends OrderStatusHandlerAbstract
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

    public function handle($od_id, array $params, bool $useTransaction): array
    {
        $customParams = [];
        $required = ['date'];

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

                if ($orderStatusModel->updateOrderDeliveryReturnComplete($params['date'])) {
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
