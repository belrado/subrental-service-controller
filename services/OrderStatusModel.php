<?php

require_once (G5_PATH.'/subrental_api/services/BaseModel.php');

/**
 * 트랜잭션은 서비스에서 실행함
 * 서비스에 종속된 모델은 해당 로직에 대한 처리 완료 수행
 */
class OrderStatusModel extends BaseModel
{
    private $currentTime;

    public function __construct($mysql, BaseService $baseService)
    {
        parent::__construct($mysql, $baseService);
        $this->currentTime = date('Y-m-d H:i:s');
    }

    /**
     * order_delivery_ready
     * 출고준비중(택배)
     * cron 주문완료 -> 출고준비중
     *
     * @param string $date 현재일
     * @return bool
     */
    public function updateOrderDeliveryReady(string $date): bool
    {
        $sqlLog = [];
        $currentTime = date('Y-m-d H:i:s', time());

        try {
            $sqlLog['update_outbound_ready_sql'] = "UPDATE g5_shop_order as gso 
            INNER JOIN g5_shop_cart as gsc ON gso.od_id = gsc.od_id 
            SET gso.od_status = '출고준비중',
                gsc.ct_status = '출고준비중',
                gsc.ct_move_date = '{$currentTime}' 
            WHERE gso.od_gb = '1' 
                AND gso.od_status = '주문완료'
                AND gsc.ct_status = '주문완료' 
                AND gso.od_id_cancel is null 
                AND gso.sr_is_visit_return = '0' 
                AND gsc.ct_move_date <= '{$date} 13:59:59'";
            if (!sql_query($sqlLog['update_outbound_ready_sql'])) {
                throw new Exception('update_item_qty_sql 제품별 판매 수량 업데이트 오류 롤백!');
            }

            $sqlLog['select_order_info'] = "SELECT gso.od_id, gsc.ct_delivery_company, gsc.ct_delivery_num FROM g5_shop_order gso 
                INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id 
                WHERE gso.od_status = '출고준비중' 
                  AND gsc.ct_move_date = '{$currentTime}' 
                  AND gso.od_id_cancel is null 
                  AND gso.sr_is_visit_return = '0'";
            $orderList = sql_select($sqlLog['select_order_info']);
            // 기록 남기기
            $insertOrderLogData = [];
            $insertDeliveryLogData = [];
            foreach ($orderList as $len => $row) {
                // 주문기록 - 출고준비중
                $insertOrderLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_status' => '출고준비중',
                    'mb_id' => 'system',
                    'ol_content' => '출고준비중',
                    'created_dt' => $currentTime
                ];
                // 배송기록 - 출고준비중
                $insertDeliveryLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'sr_install_partner' => '',
                    'ct_delivery_company' => $row['ct_delivery_company'] ?? '',
                    'ct_delivery_num' => $row['ct_delivery_num'] ?? '',
                    'dl_content' => '출고준비중',
                    'status' => '출고준비중',
                    'created_dt' => $currentTime,
                    'created_by' => 'system'
                ];
            }
            if (count($insertDeliveryLogData) > 0) {
                $insertColumn = $this->baseService->getKeys($insertOrderLogData[0]);
                $sqlLog['insert_sr_order_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_order_log', $insertColumn, $insertOrderLogData);
                if (!sql_query($sqlLog['insert_sr_order_log_sql'])) {
                    throw new Exception('출고준비중 sr_order_log 오류 롤백!');
                }
            }
            if (count($insertDeliveryLogData) > 0) {
                $insertColumn = $this->baseService->getKeys($insertDeliveryLogData[0]);
                $sqlLog['insert_sr_delivery_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_delivery_log', $insertColumn, $insertDeliveryLogData);
                if (!sql_query($sqlLog['insert_sr_delivery_log_sql'])) {
                    throw new Exception('출고준비중 sr_delivery_log 오류 롤백!');
                }
            }

            $this->baseService->logger->debug([
                'callType' => $this->callType,
                'type' => 'order_delivery_ready',
                'handler' => 'OrderDeliveryReady',
                'model' => 'updateOrderDeliveryReady',
                'ct_move_date <= date' => $date,
                'message' => '택배 회수완료 cron success (count: '. count($orderList). ')'], $this->logDir);

            return true;

        } catch (Exception $e) {
            $this->baseService->logger->error([
                'callType' => $this->callType,
                'type' => 'order_delivery_ready',
                'handler' => 'OrderDeliveryReady',
                'model' => 'updateOrderDeliveryReady',
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

     /**
     * order_delivery_ready
     * 출고준비중(설치)
     *
     * @param int $od_id
     * @param array $params [array order, array member, string before_change]
     * @return bool
     */
    public function updateOrderInstallDeliveryReady(int $od_id, array $params): bool
    {
        $sqlLog = [];

        $requiredKeys = ['od_status', 'mb_id'];
        try {
            if (!$this->baseService->validateParams($requiredKeys, $params)) {
                $sqlLog = ['params' => array_keys($params), 'requiredKeys' => $requiredKeys];
                throw new Exception('필수 데이터가 없습니다.');
            }

            // 주문 (order, cart) 출고준비중 처리
            $sqlLog['update_order'] = "UPDATE g5_shop_order gso
                INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id
                SET gso.od_status = '출고준비중',
                    gso.sr_delivery_complete_dt = null,
                    gsc.ct_status = '출고준비중'
                WHERE gso.od_id = '{$od_id}' 
                  AND gso.od_status = '{$params['od_status']}'
                  AND gsc.ct_status = '{$params['od_status']}'";

            if (!sql_query($sqlLog['update_order'])) {
                throw new Exception('update g5_shop_order & g5_shop_cart 오류');
            }
            if (!$this->baseService->validateAffectedRows()) {
                throw new Exception('update 실패 mysqli_affected_rows = 0');
            }

            // 주문 이력 테이블에 저장
            $content = "{$this->callType} {$params['od_status']} -> 출고준비중 변경";
            $sqlLog['insert_order_log'] = "INSERT INTO sr_order_log 
                    SET od_id = '{$od_id}',
                        ct_status = '출고준비중',
                        mb_id = '{$params['mb_id']}',
                        ol_content = '{$content}',
                        created_dt = '{$this->currentTime}' 
                    ";
            if (!sql_query($sqlLog['insert_order_log'])) {
                throw new Exception('inert sr_item_stock_history 오류');
            }

            return true;
        } catch (Exception $e) {
            $this->baseService->logger->error([
                'callType' => $this->callType,
                'type' => 'order_install_delivery_ready',
                'handler' => 'OrderInstallDeliveryReady',
                'model' => 'updateOrderInstallDeliveryReady',
                'od_id' => $od_id,
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

    /**
     * order_cancel_complete
     * 취소완료
     * 주문 취소완료 처리 (출고완료된 주문은 바코드 출고 기록 변경)
     *
     * @param int $od_id
     * @param array $params [array order, array member, string before_change]
     * @return bool
     */
    public function updateOrderCancelComplete(int $od_id, array $params): bool
    {
        $sqlLog = [];

        $requiredKeys = ['order', 'before_change', 'mb_id', 'log_message'];
        try {
            if (!$this->baseService->validateParams($requiredKeys, $params)) {
                $sqlLog = ['params' => array_keys($params), 'requiredKeys' => $requiredKeys];
                throw new Exception('필수 데이터가 없습니다.');
            }

            if (!empty($params['order']['sr_stock_id'])) {
                // 주문에 바코드가 매칭됨
                if ($params['order']['barcode_location_status'] === 'rental') {
                    // 출고된 재고의 취소완료 처리는 입출고창고 입고처리에서 담당
                    $sqlLog['update_order'] = "UPDATE g5_shop_order gso
                        INNER JOIN g5_shop_cart gsc ON gsc.od_id = gso.od_id
                        INNER JOIN sr_item_stock_manage sism ON sism.od_id = gso.od_id
                        SET gso.od_status = '취소완료',
                            gsc.ct_status = '취소완료', 
                            gsc.ct_move_date = '{$this->currentTime}' 
                        WHERE gso.od_id = '{$od_id}' 
                          AND gso.od_status = '{$params['before_change']}'
                          AND gsc.ct_status = '{$params['before_change']}' 
                          AND sism.barcode_location_status = 'rental'";
                } else {
                    $sqlLog['update_order'] = "UPDATE g5_shop_order gso
                        INNER JOIN g5_shop_cart gsc ON gsc.od_id = gso.od_id  
                        INNER JOIN sr_item_stock_manage sism ON sism.od_id = gso.od_id
                        SET gso.od_status = '취소완료',
                            gso.sr_install_partner_id = null,
                            gso.sr_delivery_hope_dt = null,
                            gso.sr_it_barcode_no = null,
                            gso.sr_barcode_reg_time = null,
                            gsc.ct_status = '취소완료', 
                            gsc.ct_delivery_company = null,
                            gsc.ct_delivery_num = null,
                            gsc.ct_move_date = '{$this->currentTime}',  
                            sism.od_id = null
                        WHERE gso.od_id = '{$od_id}' 
                          AND sism.od_id is not null 
                          AND sism.barcode_location_status != 'rental' 
                          AND gso.od_status = '{$params['before_change']}'
                          AND gsc.ct_status = '{$params['before_change']}'";
                }

            } else {
                // 주문에 바코드가 매칭 안됨
                $sqlLog['update_order'] = "UPDATE g5_shop_order gso
                    INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id
                    SET gso.od_status = '취소완료',
                        gso.sr_install_partner_id = null,
                        gso.sr_delivery_hope_dt = null,
                        gso.sr_it_barcode_no = null,
                        gsc.ct_status = '취소완료', 
                        gsc.ct_delivery_company = null,
                        gsc.ct_delivery_num = null,
                        gsc.ct_move_date = '{$this->currentTime}'  
                    WHERE gso.od_id = '{$od_id}' 
                      AND gso.od_status = '{$params['before_change']}'
                      AND gsc.ct_status = '{$params['before_change']}' 
                      AND gso.sr_it_barcode_no IS NULL";
            }

            if (!sql_query($sqlLog['update_order'])) {
                throw new Exception('update_order 오류');
            }
            if (!$this->baseService->validateAffectedRows()) {
                throw new Exception('update_order 실패 mysqli_affected_rows = 0');
            }

            if (!empty($params['order']['sr_stock_id'])) {
                // 바코드 이동이력 (상태변경 - 취소완료)
                $insertStockHistoryData = [];
                $insertStockHistoryData[0] = [
                    'sr_stock_id' => $params['order']['sr_stock_id'],
                    'ish_history_type' => 'status_change',
                    'ish_memo' => "{$params['log_message']}",
                    'ish_created_by' => $params['mb_id'],
                    'ish_created_at' => $this->currentTime,
                    'od_id' => $od_id
                ];
                $sqlLog['insert_item_stock_history'] = $this->baseService->setInsertMultipleBarcodeHistoryQuery($insertStockHistoryData);
                if (!sql_query($sqlLog['insert_item_stock_history'])) {
                    throw new Exception('inert sr_item_stock_history 오류');
                }
            }

            // 주문 이력 테이블에 저장
            $sqlLog['insert_order_log'] = "INSERT INTO sr_order_log 
                    SET od_id = '{$od_id}',
                        ct_status = '취소완료',
                        mb_id = '{$params['mb_id']}',
                        ol_content = '{$params['log_message']}',
                        created_dt = NOW()
                    ";
            if (!sql_query($sqlLog['insert_order_log'])) {
                throw new Exception('inert sr_item_stock_history 오류');
            }

            return true;
        } catch (Exception $e) {
            $this->baseService->logger->error([
                'callType' => $this->callType,
                'type' => 'order_cancel',
                'handler' => 'OrderCancel',
                'model' => 'updateOrderCancelComplete',
                'od_id' => $od_id,
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

     /**
     * order_return
     * 미처리
     * @param int $od_id
     * @param array $params
     * @return bool
     */
    public function updateOrderPending(int $od_id, array $params): bool
    {
      $sqlLog = [];

      $requiredKeys = ['od_status', 'mb_id'];
      try {
          if (!$this->baseService->validateParams($requiredKeys, $params)) {
              $sqlLog = ['params' => array_keys($params), 'requiredKeys' => $requiredKeys];
              throw new Exception('필수 데이터가 없습니다.2');
          }

          // 주문 (order, cart) 미처리 업데이트
          $sqlLog['update_order'] = "UPDATE g5_shop_order gso
              INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id
              SET gso.od_status = '미처리',
                  gso.sr_delivery_complete_dt = null,
                  gsc.ct_status = '미처리', 
                  gsc.ct_move_date = '{$this->currentTime}'  
              WHERE gso.od_id = '{$od_id}' 
                AND gso.od_status = '{$params['od_status']}'
                AND gsc.ct_status = '{$params['od_status']}'";

          if (!sql_query($sqlLog['update_order'])) {
              throw new Exception('update g5_shop_order & g5_shop_cart 오류');
          }
          if (!$this->baseService->validateAffectedRows()) {
              throw new Exception('update 실패 mysqli_affected_rows = 0');
          }

          // 주문 이력 테이블에 저장
          $content = "{$this->callType} {$params['od_status']} -> 미처리 변경";
          $sqlLog['insert_order_log'] = "INSERT INTO sr_order_log 
                  SET od_id = '{$od_id}',
                      ct_status = '미처리',
                      mb_id = '{$params['mb_id']}',
                      ol_content = '{$content}',
                      created_dt = '{$this->currentTime}'
                  ";

          if (!sql_query($sqlLog['insert_order_log'])) {
              throw new Exception('inert sr_order_log 오류');
          }

          $insertDeliveryLogData[0] = [
            'od_id' => $od_id,
            'dl_content' => '미처리',
            'status' => '미처리',
            'created_dt' => $this->currentTime,
            'created_by' => $params['mb_id']
          ];

          $insertColumn = $this->baseService->getKeys($insertDeliveryLogData[0]);
          $sqlLog['insert_sr_delivery_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_delivery_log', $insertColumn, $insertDeliveryLogData);
          if (!sql_query($sqlLog['insert_sr_delivery_log_sql'])) {
              throw new Exception('insert_sr_delivery_log_sql 오류 롤백!');
          }

          return true;
      } catch (Exception $e) {
          $this->baseService->logger->error([
              'callType' => $this->callType,
              'type' => 'order_pending',
              'handler' => 'OrderPending',
              'model' => 'updateOrderPending',
              'od_id' => $od_id,
              'message' => $e->getMessage(),
              'error' => $sqlLog], $this->logDir);

          return false;
      }
    }

     /**
     * order_return
     * 회수요청 처리(설치업체/파트너/관리자 회수일정 관리에서 변경처리 시) - 회수요청 주문 생성은 별도
     * @param int $od_id
     * @param array $params
     * @return bool
     */
    public function updateOrderReturnChange(int $od_id, array $params): bool
    {
      $sqlLog = [];

      $requiredKeys = ['od_status', 'od_id_cancel', 'mb_id'];
      try {
          if (!$this->baseService->validateParams($requiredKeys, $params)) {
              $sqlLog = ['params' => array_keys($params), 'requiredKeys' => $requiredKeys];
              throw new Exception('필수 데이터가 없습니다.');
          }

          // 주문 (order, cart) 회수요청 업데이트
          $sqlLog['update_order'] = "UPDATE g5_shop_order gso
              INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id
              SET gso.od_status = '회수요청',
                  gso.sr_delivery_complete_dt = null,
                  gsc.ct_status = '회수요청', 
                  gsc.ct_move_date = '{$this->currentTime}'  
              WHERE gso.od_id = '{$od_id}' 
                AND gso.od_status = '{$params['od_status']}'
                AND gsc.ct_status = '{$params['od_status']}'";

          if (!sql_query($sqlLog['update_order'])) {
              throw new Exception('update g5_shop_order & g5_shop_cart 오류');
          }
          if (!$this->baseService->validateAffectedRows()) {
              throw new Exception('update 실패 mysqli_affected_rows = 0');
          }

          // 주문 이력 테이블에 저장
          $content = "{$this->callType} {$params['od_status']} -> 회수요청 변경";
          $sqlLog['insert_order_log'] = "INSERT INTO sr_order_log 
                  SET od_id = '{$od_id}',
                      ct_status = '회수요청',
                      mb_id = '{$params['mb_id']}',
                      ol_content = '{$content}',
                      od_id_cancel = '{$params['od_id_cancel']}',
                      created_dt = NOW()
                  ";
          if (!sql_query($sqlLog['insert_order_log'])) {
              throw new Exception('inert sr_order_log 오류');
          }

          return true;
      } catch (Exception $e) {
          $this->baseService->logger->error([
              'callType' => $this->callType,
              'type' => 'order_return_change',
              'handler' => 'OrderReturnChange',
              'model' => 'updateOrderReturnChange',
              'od_id' => $od_id,
              'message' => $e->getMessage(),
              'error' => $sqlLog], $this->logDir);

          return false;
      }
    }

     /**
     * order_return
     * 회수준비중 처리
     * @param int $od_id
     * @param array $params
     * @return bool
     */
    public function updateOrderReturnReady(int $od_id, array $params): bool
    {
      $sqlLog = [];

      $requiredKeys = ['od_status', 'od_id_cancel', 'mb_id'];
      try {
          if (!$this->baseService->validateParams($requiredKeys, $params)) {
              $sqlLog = ['params' => array_keys($params), 'requiredKeys' => $requiredKeys];
              throw new Exception('필수 데이터가 없습니다.');
          }

          // 주문 (order, cart) 회수요청 업데이트
          $sqlLog['update_order'] = "UPDATE g5_shop_order gso
              INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id
              SET gso.od_status = '회수준비중',
                  gso.sr_delivery_complete_dt = null,
                  gsc.ct_status = '회수준비중', 
                  gsc.ct_move_date = '{$this->currentTime}'  
              WHERE gso.od_id = '{$od_id}' 
                AND gso.od_status = '{$params['od_status']}'
                AND gsc.ct_status = '{$params['od_status']}'";

          if (!sql_query($sqlLog['update_order'])) {
              throw new Exception('update g5_shop_order & g5_shop_cart 오류');
          }
          if (!$this->baseService->validateAffectedRows()) {
              throw new Exception('update 실패 mysqli_affected_rows = 0');
          }

          // 주문 이력 테이블에 저장
          $content = "{$this->callType} {$params['od_status']} -> 회수준비중 변경";
          $sqlLog['insert_order_log'] = "INSERT INTO sr_order_log 
                  SET od_id = '{$od_id}',
                      ct_status = '회수준비중',
                      mb_id = '{$params['mb_id']}',
                      ol_content = '{$content}',
                      od_id_cancel = '{$params['od_id_cancel']}',
                      created_dt = '{$this->currentTime}' 
                  ";
          if (!sql_query($sqlLog['insert_order_log'])) {
              throw new Exception('inert sr_order_log 오류');
          }

          return true;
      } catch (Exception $e) {
          $this->baseService->logger->error([
              'callType' => $this->callType,
              'type' => 'order_return_ready',
              'handler' => 'OrderReturnReady',
              'model' => 'updateOrderReturnReady',
              'od_id' => $od_id,
              'message' => $e->getMessage(),
              'error' => $sqlLog], $this->logDir);

          return false;
      }
    }

    /**
     *  order_return_cancel_complete
     *  회수취소완료 처리
     *
     * @param array $params [array [od_id', 'od_status', 'od_id_cancel', 'before_change', 'od_time', 'sr_is_visit_return', 'sr_stock_id', 'mb_id'], ...]
     * @return bool
     */
    public function updateOrdersReturnCancelComplete(array $params): bool
    {
        $sqlLog = [];

        $requiredKeys = ['od_id', 'od_status', 'od_id_cancel', 'before_change', 'od_time', 'sr_is_visit_return', 'sr_stock_id', 'mb_id'];
        try {
            $sqlLog['member'] = "SELECT mb_level FROM g5_member WHERE mb_id = '{$params[0]['mb_id']}'";
            if (!$member = sql_fetch($sqlLog['member'])) {
                throw new Exception('select g5_member 오류');
            }

            $deleteInboundFiles = [];
            $deleteRequest = [];
            $barcodeLog = [];
            $orderLog = [];
            $deliveryLog = [];

            foreach ($params as $len => $row) {
                if (!$this->baseService->validateParams($requiredKeys, $row)) {
                    $sqlLog['require'] = ['params' => array_keys($row), 'requiredKeys' => $requiredKeys];
                    throw new Exception('필수 데이터가 없습니다.');
                }

                if ($member['mb_level'] === '11' && $row['before_change'] === "입고완료") {
                    $sqlLog['order'][$len]['select_barcode'] = "SELECT 
                        sism.sr_stock_id,
                        sism.sr_it_barcode_no,
                        sism.warehouse_put_dt,
                        sism.sdm_id,
                        sic.assigned_srw_id,
                        sish.warehouse_put_dt as outbound_warehouse_put_dt,
                        sish.sr_sto_status as outbound_sr_sto_status
                    FROM g5_shop_order gso 
                    INNER JOIN sr_item_stock_manage sism ON sism.sr_it_barcode_no = gso.sr_it_barcode_no 
                    LEFT JOIN sr_install_partner sip ON gso.sr_install_partner_id = sip.ip_id
                    LEFT JOIN sr_install_company sic ON sip.ip_ic_id = sic.ic_id
                    LEFT JOIN sr_item_stock_history sish ON sism.sr_stock_id = sish.sr_stock_id AND sish.ish_history_type = 'outbound' AND sish.od_id = gso.od_id
                    WHERE gso.od_id = '{$row['od_id_cancel']}' 
                      AND gso.od_status = '배송완료' 
                      AND sism.od_id is null 
                      AND sism.barcode_location_status in ('warehouse', 'disinfection')";
                    if (!$barcode = sql_fetch($sqlLog['order'][$len]['select_barcode'])) {
                        throw new Exception('select sr_item_stock_manage 오류');
                    }

                    if (empty($barcode['assigned_srw_id'])) {
                        // 비어있다면 택배 (택배는 현재 [cmk파주본사창고] 에서만 보내고있음)
                        $sqlLog['order'][$len]['select_default_srw_id'] = "SELECT srw_id FROM sr_real_warehouse 
                        WHERE srw_name = '{$this->baseService->fixedRealWarehouseName}'";
                        if (!$real = sql_fetch($sqlLog['order'][$len]['select_default_srw_id'])) {
                            throw new Exception('select sr_real_warehouse 오류');
                        }
                        $srw_id = $real['srw_id'];
                    } else {
                        $srw_id = $barcode['assigned_srw_id'];
                    }

                    // 소독이력 삭제 warehouse_put_dt 이후 done, terminated sdm_id select, warehouse_put_dt 이후 기록 삭제
                    $sqlLog['order'][$len]['select_disinfection'] = "SELECT sdm_id FROM sr_disinfection_room_manage 
                        WHERE sr_stock_id = '{$row['sr_stock_id']}' AND sdm_status in ('done', 'terminated')";
                    $disinfection = sql_fetch($sqlLog['order'][$len]['select_disinfection']);
                    if ($disinfection === false) {
                        throw new Exception('select sr_disinfection_room_manage 오류');
                    }
                    if (!empty($disinfection['sdm_id'])) {
                        // 소독이력 삭제
                        $sqlLog['order'][$len]['delete_disinfection'] = "DELETE FROM sr_disinfection_room_manage 
                            WHERE sr_stock_id = '{$row['sr_stock_id']}' AND sdm_created_at >= '{$row['warehouse_put_dt']}'";
                        if(!sql_query($sqlLog['order'][$len]['delete_disinfection'])) {
                            throw new Exception('delete sr_disinfection_room_manage 오류');
                        }
                        // 소독필증 삭제 warehouse_put_dt 이후 done, terminated sdm_id select 가져온 아이디로 삭제
                        $sqlLog['order'][$len]['delete_certificate'] = "DELETE FROM sr_disinfection_certificate_history 
                            WHERE sdm_id = '{$disinfection['sdm_id']}'";
                        if(!sql_query($sqlLog['order'][$len]['delete_certificate'])) {
                            throw new Exception('delete sr_disinfection_certificate_history 오류');
                        }
                    }

                    // 입고사진기록 삭제 od_id 로 기록 inbound ish_id select, ish_id 로 포토 isip_photo_path 가져온뒤 ish_id 해당기록 삭제
                    $sqlLog['order'][$len]['select_photo'] = "SELECT isip_photo_path FROM sr_item_stock_inbound_photos 
                       WHERE ish_id = (
                           SELECT ish_id 
                           FROM sr_item_stock_history 
                           WHERE sr_stock_id = '{$row['sr_stock_id']}' 
                             AND od_id = '{$row['od_id']}'
                             AND return_ct_status = '입고완료'
                             AND ish_history_type = 'inbound'
                             AND ish_created_at = '{$barcode['warehouse_put_dt']}'
                       )";
                    $deleteInboundFiles = sql_select($sqlLog['order'][$len]['select_photo']);
                    $sqlLog['order'][$len]['delete_photo'] = "DELETE FROM sr_item_stock_inbound_photos 
                        WHERE ish_id = (
                            SELECT ish_id 
                            FROM sr_item_stock_history 
                            WHERE sr_stock_id = '{$row['sr_stock_id']}' 
                              AND od_id = '{$row['od_id']}' 
                              AND return_ct_status = '입고완료' 
                              AND ish_history_type = 'inbound' 
                              AND ish_created_at = '{$barcode['warehouse_put_dt']}'
                       )";
                    if (!sql_query($sqlLog['order'][$len]['delete_photo'])) {
                        throw new Exception('select sr_item_stock_inbound_photos 오류');
                    }

                    // 바코드 없데이트
                    $setClause = "sism.srw_id = '{$srw_id}' ";
                    if (!empty($barcode['outbound_warehouse_put_dt'])) {
                        $setClause .= ", sism.warehouse_put_dt = '{$barcode['outbound_warehouse_put_dt']}', sism.svwr_put_dt = '{$barcode['outbound_warehouse_put_dt']}' ";
                    }
                    if (!empty($barcode['sr_sto_status'])) {
                        $setClause .= ", sism.sr_sto_status = '{$barcode['sr_sto_status']}'";
                    }
                    $sqlLog['order'][$len]['update_barcode'] = "UPDATE sr_item_stock_manage sism 
                        INNER JOIN g5_shop_order gso ON gso.od_gb = '1' AND gso.sr_it_barcode_no = sism.sr_it_barcode_no 
                        SET sism.barcode_location_status = 'rental',
                            sism.od_id = '{$row['od_id_cancel']}',
                            sism.damaged_yn = 'N',
                            sism.missing_parts = 'N',
                            sism.action_required = 'none',
                            sism.barcode_cancel_yn = 'N',
                            sism.disinfection_yn = 'N',
                            sism.sdm_id = null,
                            sism.svwr_id = null,
                            {$setClause} 
                        WHERE gso.od_id = '{$row['od_id']}' 
                          AND sism.od_id is null 
                          AND sism.barcode_location_status in ('warehouse', 'disinfection')";

                    if(!sql_query($sqlLog['order'][$len]['update_barcode'])) {
                        throw new Exception('update sr_item_stock_manage 오류');
                    }
                    if (!$this->baseService->validateAffectedRows()) {
                        throw new Exception('update_order 실패 mysqli_affected_rows = 0');
                    }
                }

                // 로그기록 처리 회수주문 od_time 이후 기록 ish_use_history = N 처리
                $sqlLog['order'][$len]['update_history'] = "UPDATE sr_item_stock_history 
                    SET ish_use_history = 'N' 
                    WHERE sr_stock_id = '{$row['sr_stock_id']}' 
                      AND ish_created_at >= '{$row['od_time']}'";
                if (!sql_query($sqlLog['order'][$len]['update_history'])) {
                    throw new Exception('update sr_item_stock_history 오류');
                }

                // 최종적으로 주문서 회수요청취소 업데이트
                $sqlLog['order'][$len]['update_order'] = "UPDATE g5_shop_order gsor 
                    INNER JOIN g5_shop_cart gscr ON gsor.od_id = gscr.od_id 
                    INNER JOIN g5_shop_cart gsc ON gsor.od_id_cancel = gsc.od_id 
                    SET gsor.od_status = '회수취소완료',
                        gsor.sr_delivery_hope_dt = null,
                        gsor.sr_visit_time = null,
                        gsor.sr_install_partner_id = null,
                        gsor.sr_delivery_complete_dt = null,
                        gsor.sr_need_revisit = null,
                        gsor.sr_happy_call_yn = 'N',
                        gscr.ct_status = '회수취소완료', 
                        gscr.ordLendEndDtm = null,
                        gscr.ct_delivery_company = null,
                        gscr.ct_delivery_num = null,
                        gscr.ct_move_date = '{$this->currentTime}',
                        gsc.ordLendEndDtm = null 
                    WHERE gsor.od_id = '{$row['od_id']}' 
                      AND gsor.od_status = '{$row['before_change']}' 
                      AND gscr.ct_status = '{$row['before_change']}'
                      ";
                if (!sql_query($sqlLog['order'][$len]['update_order'])) {
                    throw new Exception('update order 오류');
                }
                if (!$this->baseService->validateAffectedRows()) {
                    throw new Exception('update_order 실패 mysqli_affected_rows = 0');
                }

                // 회수요청 테이블에서 회수주문번호 삭제
                $deleteRequest[$len] = $row['od_id_cancel'];

                // 로그기록 추가
                $logMessage = "{$this->callType} {$row['before_change']} > 회수취소완료 (주문번호:{$row['od_id_cancel']} / 회수번호:{$row['od_id']})";
                $orderLog[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_status' => '회수취소완료',
                    'mb_id' => $row['mb_id'],
                    'ol_content' => $logMessage,
                    'od_id_cancel' => $row['od_id_cancel'],
                    'created_dt' => $this->currentTime
                ];
                $deliveryLog[$len] = [
                    'od_id' => $row['od_id'],
                    'dl_content' => $logMessage,
                    'sr_install_partner' => '',
                    'ct_delivery_company' => '',
                    'ct_delivery_num' => '',
                    'is_visit' => $row['sr_is_visit_return'],
                    'created_by' => $row['mb_id'],
                    'status' => '회수취소완료',
                    'created_dt' => $this->currentTime
                ];
                $barcodeLog[$len] = [
                    'sr_stock_id' => $row['sr_stock_id'],
                    'ish_history_type' => 'status_change',
                    'ish_memo' => $logMessage,
                    'ish_created_by' => $row['mb_id'],
                    'ish_created_at' => $this->currentTime,
                    'od_id' => $row['od_id'],
                    'return_ct_status' => '회수취소완료'
                ];
            }

            // 회수취소요청 테이블에서 기록 삭제
            if (!empty($deleteRequest)) {
                $sqlLog['delete_cancel_request'] = "DELETE FROM sr_shop_order_cancel_request
                    WHERE od_id IN ('" . implode("','", $deleteRequest) . "')";
                if (!sql_query($sqlLog['delete_cancel_request'])) {
                    throw new Exception('delete sr_shop_order_cancel_request 오류');
                }
            }

            if (!empty($orderLog)) {
                $insertColumn = $this->baseService->getKeys($orderLog[0]);
                $sqlLog['insert_sr_order_log'] = $this->baseService->setInsertMultipleQuery('sr_order_log', $insertColumn, $orderLog);
                if (!sql_query($sqlLog['insert_sr_order_log'])) {
                    throw new Exception('sr_delivery_log 오류 롤백!');
                }
            }

            if (!empty($deliveryLog)) {
                $insertColumn = $this->baseService->getKeys($deliveryLog[0]);
                $sqlLog['insert_sr_delivery_log'] = $this->baseService->setInsertMultipleQuery('sr_delivery_log', $insertColumn, $deliveryLog);
                if (!sql_query($sqlLog['insert_sr_delivery_log'])) {
                    throw new Exception('sr_delivery_log 오류 롤백!');
                }
            }

            if (!empty($barcodeLog)) {
                $sqlLog['insert_item_stock_history'] = $this->baseService->setInsertMultipleBarcodeHistoryQuery($barcodeLog);
                if (!sql_query($sqlLog['insert_item_stock_history'])) {
                    throw new Exception('sr_item_stock_history 오류 롤백!');
                }
            }

            // 최종적으로 파일 삭제
            if (!empty($deleteInboundFiles)) {
                foreach ($deleteInboundFiles as $row) {
                    foreach ($row as  $filePath) {
                        if (file_exists($filePath)) {
                            @unlink($filePath);
                        }
                    }
                }
            }

            return true;

        } catch (Exception $e) {
            $this->baseService->logger->error([
                'callType' => $this->callType,
                'type' => 'order_return_cancel_complete',
                'handler' => 'OrderReturnCancelComplete',
                'model' => 'updateOrdersReturnCancelComplete',
                'od_ids' => $params['od_id'],
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

    /**
     * order_barcode_match
     * 출고 리스트 주문 (od_status 주문완료, 출고준비중)에 바코드 매칭
     *
     * @param int $od_id
     * @param string $sr_it_barcode_no
     * @param array $params [array $barcode, array $order, enum $type (install | parcel)]
     * @return bool
     */
    public function updateOutboundOrderBarcode(int $od_id, string $sr_it_barcode_no, array $params): bool
    {
        $sqlLog = [];
        $required = ['barcode', 'order', 'type', 'mb_id', 'srw_id'];
        try {
            if (!$this->baseService->validateParams($required, $params)) {
                $sqlLog = ['params' => array_keys($params), 'required' => $required];
                throw new Exception('필수 데이터가 없습니다.');
            }

            // (주문완료, 출고준비중) 주문에 바코드 & 바코드 등록일 등록
            if ($params['type'] === '설치') {
                // 설치건
                $sqlLog['update_order'] = "
                    UPDATE g5_shop_order gso
                    INNER JOIN sr_install_partner sip ON sip.ip_id = gso.sr_install_partner_id
                    INNER JOIN sr_install_company sic ON sic.ic_id = sip.ip_ic_id
                    SET gso.sr_it_barcode_no = '{$sr_it_barcode_no}', 
                        gso.sr_barcode_reg_time = '{$this->currentTime}' 
                    WHERE gso.od_id = '{$od_id}' 
                      AND gso.sr_is_visit_return = '1' 
                      AND gso.od_gb = '1' 
                      AND gso.od_status IN ('주문완료', '출고준비중')
                      AND gso.od_del_yn = 'N'
                      AND (gso.sr_it_barcode_no IS NULL OR gso.sr_it_barcode_no = '') 
                      AND sic.assigned_srw_id = '{$params['srw_id']}'";
            } else {
                // 택배건
                $sqlLog['update_order'] = "
                    UPDATE g5_shop_order 
                    SET sr_it_barcode_no = '{$sr_it_barcode_no}', 
                        sr_barcode_reg_time = '{$this->currentTime}' 
                    WHERE od_id = '{$od_id}' 
                      AND sr_is_visit_return = '0'
                      AND od_gb = '1' 
                      AND od_status in ('주문완료', '출고준비중')
                      AND od_del_yn = 'N' 
                      AND (sr_it_barcode_no is null or sr_it_barcode_no = '')";
            }

            if (!sql_query($sqlLog['update_order'])) {
                throw new Exception('update g5_shop_order 오류');
            }
            if (!$this->baseService->validateAffectedRows()) {
                throw new Exception('update g5_shop_order 실패 mysqli_affected_rows = 0'.$params['type']);
            }

            // 바코드에 주문코드 등록
            $sqlLog['update_item_stock'] = "
                update sr_item_stock_manage
                set od_id = {$od_id}
                where sr_it_barcode_no = '{$sr_it_barcode_no}' 
                  and barcode_location_status in ('warehouse', 'disinfection') 
                  and disinfection_yn = 'Y'
                  and barcode_cancel_yn = 'Y'
                  and damaged_yn = 'N' 
                  and use_yn = 'Y'
                  and (od_id is null or od_id = '')";
            if (!sql_query($sqlLog['update_item_stock'])) {
                throw new Exception('update sr_item_stock_manage 오류');
            }
            if (!$this->baseService->validateAffectedRows()) {
                throw new Exception('update sr_item_stock_manage 실패 mysqli_affected_rows = 0');
            }

            // 바코드 이동 이력 등록
            $insertStockHistoryData[0] = [
                'sr_stock_id' => $params['barcode']['sr_stock_id'],
                'ish_history_type' => 'add_cart',
                'ish_memo' => $params['type']." 주문에 바코드 등록",
                'ish_created_by' => $params['mb_id'],
                'ish_created_at' => $this->currentTime,
                'od_id' => $od_id
            ];
            $sqlLog['insert_item_stock_history'] = $this->baseService->setInsertMultipleBarcodeHistoryQuery($insertStockHistoryData);
            if (!sql_query($sqlLog['insert_item_stock_history'])) {
                throw new Exception('inert sr_item_stock_history 오류');
            }

            // 주문 로그 등록
            $insertOrderLogData[0] = [
                'od_id' => $od_id,
                'ct_status' => $params['order']['ct_status'],
                'mb_id' => $params['mb_id'],
                'ol_content' => '바코드 등록',
                'created_dt' => $this->currentTime,
            ];
            $insertColumn = $this->baseService->getKeys($insertOrderLogData[0]);
            $sqlLog['insert_sr_order_log'] = $this->baseService->setInsertMultipleQuery('sr_order_log', $insertColumn, $insertOrderLogData);
            if (!sql_query($sqlLog['insert_sr_order_log'])) {
                throw new Exception('insert sr_order_log 오류');
            }

            return true;
        } catch (Exception $e) {
            $this->baseService->logger->error([
                'callType' => $this->callType,
                'type' => 'order_barcode_match',
                'handler' => 'OrderBarcodeMatch',
                'model' => 'updateOutboundOrderBarcode',
                'od_id' => $od_id,
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

    /**
     * order_outbound
     * 출고완료
     * 입출고창고 출고처리
     *
     * @param array $od_ids : 주문아이디
     * @param array $params : [array checkOrders (주문아이디로 다시 검증완료한 주문 데이터), string type (install | parcel), string mb_id (출고처리자) ]
     * @return bool
     */
    public function updateOutboundOrder(array $od_ids, array $params): bool
    {
        $sqlLog = [];
        $required = ['checkOrders', 'type', 'mb_id'];

        $updateOutboundData = [];
        $insertStockHistoryData = [];
        $insertOrderLogData = [];
        $insertDeliveryLogData = [];

        try {
            if (!$this->baseService->validateParams($required, $params)) {
                $sqlLog = ['params' => array_keys($params), 'required' => $required];
                throw new Exception('필수 데이터가 없습니다.');
            }

            foreach ($params['checkOrders'] as $len => $row) {
                $updateOutboundData[$len] = [
                    'gso.od_id' => $row['od_id'],
                    'gso.od_status' => '출고완료',
                    'gso.od_release_manager' => $params['mb_id'],
                    'gso.od_release_date' => $this->currentTime,
                    'gso.od_ex_date' => $this->currentTime,
                    'gsc.ct_status' => '출고완료',
                    'gsc.ct_ex_date' => $this->currentTime,
                    'gsc.ct_move_date' => $this->currentTime,
                    'gsc.ct_manager' => $params['mb_id'],
                    'sism.barcode_location_status' => 'rental',
                    'sism.svwr_id' => null,
                    'sism.disinfection_issue_yn' => 'N'
                ];
                if ($params['type'] === 'install') {
                    $updateOutboundData[$len]['gso.od_delivery_yn'] = 'N';
                }

                $insertStockHistoryData[$len] = [
                    'sr_stock_id' => $row['sr_stock_id'],
                    'ish_history_type' => 'outbound',
                    'ish_memo' => "{$params['type']} 주문 출고 완료",
                    'ish_created_by' => $params['mb_id'],
                    'ish_created_at' => $this->currentTime,
                    'barcode_location_status' => 'rental',
                    'svwr_id' => null,
                    'od_id' => $row['od_id']
                ];

                $insertOrderLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_status' => '출고완료',
                    'mb_id' => $params['mb_id'],
                    'ol_content' => $row['sr_is_visit_return'] === '1' ? '설치 출고처리' : '택배 출고처리',
                    'created_dt' => $this->currentTime,
                ];

                $insertDeliveryLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_delivery_company' => $row['ct_delivery_company'] ?? '',
                    'ct_delivery_num' => $row['ct_delivery_num'] ?? '',
                    'sr_install_partner' => $row['sr_install_partner_id'] ?? '',
                    'dl_content' => $row['sr_is_visit_return'] === '1' ? '설치 출고처리' : '택배 출고처리',
                    'status' => '출고완료',
                    'created_dt' => $this->currentTime,
                    'created_by' => $params['mb_id'],
                    'is_visit' => $row['sr_is_visit_return'] === '1' ? '1' : '0'
                ];
            }

            if (count($updateOutboundData) > 0) {
                $joinTables = [
                    'gsc' => ['table' => 'g5_shop_cart', 'on' => 'gsc.od_id = gso.od_id'],
                    'sism' => ['table' => 'sr_item_stock_manage', 'on' => 'sism.od_id = gso.od_id']
                ];
                $outboundConditions = [
                    "gso.od_gb = '1'",
                    "gso.od_del_yn = 'N'",
                    "gso.od_status in ('주문완료', '출고준비중')",
                    "gso.sr_it_barcode_no is not null",
                    "sism.barcode_location_status != 'rental'",
                    "sism.od_id is not null",
                    "sism.damaged_yn = 'N'",
                    "sism.disinfection_yn = 'Y'",
                    "sism.barcode_cancel_yn = 'Y'"
                ];
                $sqlLog['update_outbound_order_sql'] = $this->baseService->setUpdateMultipleInnerJoinedTablesQuery('g5_shop_order as gso', $joinTables, 'gso.od_id', $updateOutboundData, $outboundConditions);
                if (!sql_query($sqlLog['update_outbound_order_sql'])) {
                    throw new Exception('출고 처리 오류 롤백!');
                }

                $sqlLog['insert_item_stock_history_sql'] = $this->baseService->setInsertMultipleBarcodeHistoryQuery($insertStockHistoryData);
                if (!sql_query($sqlLog['insert_item_stock_history_sql'])) {
                    throw new Exception('sr_item_stock_history 출고 처리 오류 롤백!');
                }

                $insertColumn = $this->baseService->getKeys($insertOrderLogData[0]);
                $sqlLog['insert_sr_order_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_order_log', $insertColumn, $insertOrderLogData);
                if (!sql_query($sqlLog['insert_sr_order_log_sql'])) {
                    throw new Exception('insert_sr_order_log_sql 오류 롤백!');
                }

                $insertColumn = $this->baseService->getKeys($insertDeliveryLogData[0]);
                $sqlLog['insert_sr_delivery_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_delivery_log', $insertColumn, $insertDeliveryLogData);
                if (!sql_query($sqlLog['insert_sr_delivery_log_sql'])) {
                    throw new Exception('insert_sr_delivery_log_sql 오류 롤백!');
                }
            }

            return true;

        } catch (Exception $e) {
            $this->baseService->logger->error([
                'callType' => $this->callType,
                'type' => 'order_outbound',
                'handler' => 'OrderOutbound',
                'model' => 'updateOutboundOrder',
                'od_ids' => $od_ids,
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

    /**
     * order_delivery_complete
     * 택배 - 배송완료
     * cron - 출고완료 -> 배송완료
     *
     * @param string $date 현재일 - 3일
     * @return bool
     */
    public function updateOrderDeliveryComplete(string $date): bool
    {
        $sqlLog = [];
        $currentTime = date('Y-m-d H:i:s', time());
        $currentDate = date('Y-m-d', time());

        try {
            $sqlLog['update_order_sql'] = "UPDATE g5_shop_order AS gso 
                INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id 
                INNER JOIN sr_item_stock_manage sism ON sism.od_id = gso.od_id
                SET 
                    gso.od_status = '배송완료', 
                    gsc.ct_status = '배송완료', 
                    gso.sr_delivery_complete_dt = '{$currentDate}',
                    gsc.ct_move_date = '{$currentTime}',
                    gsc.ordLendStrDtm = '{$currentTime}',
                    sism.barcode_cancel_yn = 'N',
                    sism.sr_it_barcode_open_dt = IF(sism.sr_sto_status = 'new', '{$currentDate}', sism.sr_it_barcode_open_dt)
                WHERE gso.od_gb = '1' 
                  AND gso.od_del_yn = 'N'
                  AND gso.sr_it_barcode_no IS NOT NULL 
                  AND sism.barcode_location_status = 'rental'
                  AND gso.sr_is_visit_return = '0'
                  AND gso.od_status = '출고완료' 
                  AND gsc.ct_status = '출고완료' 
                  AND gso.od_id_cancel IS NULL 
                  AND gso.od_release_date <= '{$date}'";
            if (!sql_query($sqlLog['update_order_sql'])) {
                throw new Exception('update_order_sql 택배 완료 정보 업데이트 오류 롤백!');
            }

            $sqlLog['select_order_info'] = " SELECT sism.sr_stock_id, gso.od_id, gsc.ct_delivery_company, gsc.ct_delivery_num
                        FROM g5_shop_order gso
                        INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id
                        INNER JOIN sr_item_stock_manage sism ON sism.od_id = gso.od_id
                        WHERE gso.od_gb = '1'
                          AND gso.od_del_yn = 'N'
                          AND gso.od_id_cancel IS NULL 
                          AND gso.od_status = '배송완료' 
                          AND gso.sr_it_barcode_no IS NOT NULL 
                          AND gso.sr_is_visit_return = '0' 
                          AND gso.sr_delivery_complete_dt = '{$currentDate}' 
                          AND gsc.ordLendStrDtm = '{$currentTime}'
                          AND sism.barcode_cancel_yn = 'N'
                          AND sism.barcode_location_status = 'rental'";
            $srStockIdList = sql_select($sqlLog['select_order_info']);
            // 기록 남기기
            $insertItemStockHistory = [];
            $insertOrderLogData = [];
            $insertDeliveryLogData = [];
            foreach ($srStockIdList as $len => $row) {
                // 바코드 이동이력 - 배송완료
                $insertItemStockHistory[$len] = [
                    "sr_stock_id" => $row['sr_stock_id'],
                    "svwr_id" => null,
                    "ish_history_type" => "status_change",
                    "ish_created_at" => $currentTime,
                    "ish_created_by" => 'system',
                    "ish_memo" => "배송완료"
                ];
                // 주문기록 - 배송완료
                $insertOrderLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_status' => '배송완료',
                    'mb_id' => 'system',
                    'ol_content' => '배송완료',
                    'created_dt' => $currentTime
                ];
                // 배송기록 - 배송완료
                $insertDeliveryLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_delivery_company' => $row['ct_delivery_company'],
                    'ct_delivery_num' => $row['ct_delivery_num'],
                    'dl_content' => '배송완료',
                    'status' => '배송완료',
                    'created_dt' => $currentTime,
                    'created_by' => 'system'
                ];
            }
            if(count($insertItemStockHistory) > 0 ){
                $sqlLog['insert_it_stock_history'] = $this->baseService->setInsertMultipleBarcodeHistoryQuery($insertItemStockHistory);
                if (!sql_query($sqlLog['insert_it_stock_history'])) {
                    throw new Exception('배송완료 바코드 이동 기록 등록 오류 롤백!');
                }
            }
            if (count($insertOrderLogData) > 0) {
                $insertColumn = $this->baseService->getKeys($insertDeliveryLogData[0]);
                $sqlLog['insert_sr_delivery_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_delivery_log', $insertColumn, $insertDeliveryLogData);
                if (!sql_query($sqlLog['insert_sr_delivery_log_sql'])) {
                    throw new Exception('insert_sr_delivery_log_sql 오류 롤백!');
                }
            }
            if (count($insertDeliveryLogData) > 0) {
                $insertColumn = $this->baseService->getKeys($insertOrderLogData[0]);
                $sqlLog['insert_sr_order_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_order_log', $insertColumn, $insertOrderLogData);
                if (!sql_query($sqlLog['insert_sr_order_log_sql'])) {
                    throw new Exception('insert_sr_order_log_sql 오류 롤백!');
                }
            }

            $this->baseService->logger->debug([
                'callType' => $this->callType,
                'type' => 'order_delivery_complete',
                'handler' => 'OrderDeliveryComplete',
                'model' => 'updateOrderDeliveryComplete',
                'od_release_date <= date' => $date,
                'message' => '택배 배송완료 cron success (count: '. count($srStockIdList). ')'], $this->logDir);

            return true;

        } catch (Exception $e) {
            $this->baseService->logger->error([
                'callType' => $this->callType,
                'type' => 'order_delivery_complete',
                'handler' => 'OrderDeliveryComplete',
                'model' => 'updateOrderDeliveryComplete',
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

    /**
     * order_install_complete
     * 설치 - 배송완료
     * 출고완료 -> 배송완료
     */
    public function updateOrderInstallComplete(int $od_id, array $params): bool
    {
        $sqlLog = [];
        $requiredKeys = ['ct_status', 'currentTime'];
        $currentTime = $params['currentTime'];
        $hopeDt = !empty($params['hope_date']) ? "gso.sr_delivery_hope_dt = '{$params['hope_date']}'," : "";

        try {
            if (!$this->baseService->validateParams($requiredKeys, $params)) {
                $sqlLog = ['params' => array_keys($params), 'requiredKeys' => $requiredKeys];
                throw new Exception('필수 데이터가 없습니다.');
            }

            $sqlLog['update_order'] = "UPDATE g5_shop_order gso 
                INNER JOIN g5_shop_cart gsc on gsc.od_id = gso.od_id 
                INNER JOIN sr_item_stock_manage sism on sism.od_id = gso.od_id
                SET gso.od_status = '{$params['ct_status']}',
                    gso.sr_need_revisit = NULL,
                    {$hopeDt}
                    gso.sr_delivery_complete_dt = '{$currentTime}',
                    gsc.ordLendStrDtm = '{$currentTime}',
                    gsc.ct_status = '{$params['ct_status']}',
                    sism.barcode_cancel_yn = 'N', 
                    sism.sr_it_barcode_open_dt = IF(sism.sr_sto_status = 'new', '{$currentTime}', sism.sr_it_barcode_open_dt)
                WHERE gso.od_id = '{$od_id}' 
                  AND gso.od_del_yn = 'N'
                  AND gso.od_gb = '1' 
                  AND gso.sr_is_visit_return = '1'
                  AND gso.od_status = '출고완료' 
                  AND sism.barcode_location_status = 'rental'";

            if (!sql_query($sqlLog['update_order'])) {
                throw new Exception('update g5_shop_order & g5_shop_cart & sr_item_stock_manage 오류');
            }
            if (!$this->baseService->validateAffectedRows()) {
                throw new Exception('update 실패 mysqli_affected_rows = 0');
            }

            $sqlLog['select_barcode_id'] = " SELECT sr_stock_id
                        FROM sr_item_stock_manage  ON sism.od_id = gso.od_id
                        WHERE od_id = '{$od_id}'";
            $srStockId = sql_select($sqlLog['select_barcode_id']);

            // 기록 남기기
            $insertItemStockHistory = [];
            $insertOrderLogData = [];
            $insertDeliveryLogData = [];
            foreach ($srStockId as $len => $row) {
                // 바코드 이동이력 - 배송완료
                $insertItemStockHistory[$len] = [
                    "sr_stock_id" => $row['sr_stock_id'],
                    "svwr_id" => null,
                    "ish_history_type" => "status_change",
                    "ish_created_at" => $currentTime,
                    "ish_created_by" => 'system',
                    "ish_memo" => "설치 배송완료"
                ];
                // 주문기록 - 배송완료
                $insertOrderLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_status' => '배송완료',
                    'mb_id' => 'system',
                    'ol_content' => '설치 배송완료',
                    'created_dt' => $currentTime
                ];
                // 배송기록 - 배송완료
                $insertDeliveryLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_delivery_company' => $row['ct_delivery_company'],
                    'ct_delivery_num' => $row['ct_delivery_num'],
                    'dl_content' => '설치 배송완료',
                    'status' => '배송완료',
                    'created_dt' => $currentTime,
                    'created_by' => 'system'
                ];
            }
            if(count($insertItemStockHistory) > 0 ){
                $sqlLog['insert_it_stock_history'] = $this->baseService->setInsertMultipleBarcodeHistoryQuery($insertItemStockHistory);
                if (!sql_query($sqlLog['insert_it_stock_history'])) {
                    throw new Exception('설치 - 배송완료 바코드 이동 기록 등록 오류 롤백!');
                }
            }
            if (count($insertOrderLogData) > 0) {
                $insertColumn = $this->baseService->getKeys($insertDeliveryLogData[0]);
                $sqlLog['insert_sr_delivery_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_delivery_log', $insertColumn, $insertDeliveryLogData);
                if (!sql_query($sqlLog['insert_sr_delivery_log_sql'])) {
                    throw new Exception('설치 - insert_sr_delivery_log_sql 오류 롤백!');
                }
            }
            if (count($insertDeliveryLogData) > 0) {
                $insertColumn = $this->baseService->getKeys($insertOrderLogData[0]);
                $sqlLog['insert_sr_order_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_order_log', $insertColumn, $insertOrderLogData);
                if (!sql_query($sqlLog['insert_sr_order_log_sql'])) {
                    throw new Exception('설치 - insert_sr_order_log_sql 오류 롤백!');
                }
            }

            return true;

        } catch (Exception $e) {
            $this->baseService->logger->error([
                'callType' => $this->callType,
                'type' => 'order_install_complete',
                'handler' => 'OrderInstallComplete',
                'model' => 'updateOrderInstallComplete',
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

    /**
     * order_delivery_return_complete
     * 회수완료
     * cron - 회수준비중 -> 회수완료
     *
     * @param string $date 현재일 -3일
     * @return bool
     */
    public function updateOrderDeliveryReturnComplete(string $date): bool
    {
        $sqlLog = [];
        $currentTime = date('Y-m-d H:i:s', time());
        $currentDate = date('Y-m-d', time());

        try {
            $sqlLog['update_return_order_status_sql'] = "UPDATE g5_shop_order AS gso 
                INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id 
                SET 
                    gso.od_status = '회수완료', 
                    gsc.ct_status = '회수완료', 
                    gso.sr_delivery_complete_dt = '{$currentDate}',
                    gsc.ct_move_date = '{$currentTime}'
                WHERE gso.od_gb = '1' 
                  AND gso.od_del_yn = 'N'
                  AND gso.sr_is_visit_return = '0'
                  AND gso.od_status = '회수준비중' 
                  AND gsc.ct_status = '회수준비중' 
                  AND gso.od_id_cancel IS NOT NULL 
                  AND gsc.ct_move_date <= '{$date} 23:59:59'";
            if (!sql_query($sqlLog['update_return_order_status_sql'])) {
                throw new Exception('update_return_order_status_sql 택배 회수 완료 정보 업데이트 오류 롤백!');
            }

            $sqlLog['update_item_qty_sql'] = "UPDATE g5_shop_item AS it, 
                (SELECT COUNT(it_id) AS cnt, it_id, it_name FROM g5_shop_cart WHERE ct_status = '배송완료' and ct_gb = '1' and ct_id_cancel is null GROUP BY it_id) AS sale
                SET it.it_sum_qty = sale.cnt WHERE it.it_id = sale.it_id";
            if (!sql_query($sqlLog['update_item_qty_sql'])) {
                throw new Exception('update_item_qty_sql 제품별 판매 수량 업데이트 오류 롤백!');
            }

            $sqlLog['select_order_info'] = " SELECT sism.sr_stock_id, gso.od_id, gso.od_id_cancel, gsc.ct_delivery_company, gsc.ct_delivery_num
                        FROM g5_shop_order gso
                        INNER JOIN g5_shop_cart gsc ON gso.od_id = gsc.od_id
                        INNER JOIN sr_item_stock_manage sism ON sism.od_id = gso.od_id_cancel
                        WHERE gso.od_gb = '1'
                          AND gso.od_del_yn = 'N'
                          AND gso.od_id_cancel IS NOT NULL 
                          AND gso.od_status = '회수완료' 
                          AND gso.sr_it_barcode_no IS NOT NULL 
                          AND gso.sr_is_visit_return = '0' 
                          AND gso.sr_delivery_complete_dt = '{$currentDate}' 
                          AND gsc.ct_move_date = '{$currentTime}'
                          AND sism.barcode_location_status = 'rental'";
            $srStockIdList = sql_select($sqlLog['select_order_info']);
            // 기록 남기기
            $insertItemStockHistory = [];
            $insertOrderLogData = [];
            $insertDeliveryLogData = [];
            foreach ($srStockIdList as $len => $row) {
                // 바코드 이동이력 - 회수완료
                $insertItemStockHistory[$len] = [
                    "sr_stock_id" => $row['sr_stock_id'],
                    "ish_history_type" => "status_change",
                    "ish_created_at" => $currentTime,
                    "ish_created_by" => 'system',
                    "ish_memo" => "회수완료",
                ];
                // 주문기록 - 회수완료
                $insertOrderLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'od_id_cancel' => $row['od_id_cancel'],
                    'ct_status' => '회수완료',
                    'mb_id' => 'system',
                    'ol_content' => '회수완료',
                    'created_dt' => $currentTime
                ];
                // 배송기록 - 회수완료
                $insertDeliveryLogData[$len] = [
                    'od_id' => $row['od_id'],
                    'ct_delivery_company' => $row['ct_delivery_company'],
                    'ct_delivery_num' => $row['ct_delivery_num'],
                    'dl_content' => '회수완료',
                    'status' => '회수완료',
                    'created_dt' => $currentTime,
                    'created_by' => 'system'
                ];
            }

            if(count($insertItemStockHistory) > 0 ){
                $sqlLog['insert_it_stock_history'] = $this->baseService->setInsertMultipleBarcodeHistoryQuery($insertItemStockHistory);
                if (!sql_query($sqlLog['insert_it_stock_history'])) {
                    throw new Exception('회수완료 바코드 이동 기록 등록 오류 롤백!');
                }
            }
            if (count($insertOrderLogData) > 0) {
                $insertColumn = $this->baseService->getKeys($insertDeliveryLogData[0]);
                $sqlLog['insert_sr_delivery_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_delivery_log', $insertColumn, $insertDeliveryLogData);
                if (!sql_query($sqlLog['insert_sr_delivery_log_sql'])) {
                    throw new Exception('회수완료 sr_delivery_log 오류 롤백!');
                }
            }
            if (count($insertDeliveryLogData) > 0) {
                $insertColumn = $this->baseService->getKeys($insertOrderLogData[0]);
                $sqlLog['insert_sr_order_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_order_log', $insertColumn, $insertOrderLogData);
                if (!sql_query($sqlLog['insert_sr_order_log_sql'])) {
                    throw new Exception('회수완료 sr_order_log 오류 롤백!');
                }
            }

            $this->baseService->logger->debug([
                'callType' => $this->callType,
                'type' => 'order_delivery_return_complete',
                'handler' => 'OrderDeliveryReturnComplete',
                'model' => 'updateOrderDeliveryReturnComplete',
                'ct_move_date <= date' => $date,
                'message' => '택배 회수완료 cron success (count: '. count($srStockIdList). ')'], $this->logDir);

            return true;

        } catch (Exception $e) {
            $this->baseService->logger->debug([
                'callType' => $this->callType,
                'type' => 'order_delivery_return_complete',
                'handler' => 'OrderDeliveryReturnComplete',
                'model' => 'updateOrderDeliveryReturnComplete',
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }

    /**
     * order_inbound
     * 입고완료 처리 (미처리, 취소완료, (설치: 회수완료, 택배: 회수완료, 회수준비중))
     * - 취소완료 => 그대로 취소완료 유지 (관리자에 주문목록에 노출)
     * - 미처리 => 출고준비중 으로 변경 (다시 출고 가능해야함)
     * - 회수준비중, 회수완료 => 입고완료 변경
     *
     * @param $od_id
     * @param array $params
     * @return bool
     */
    public function updateInboundOrder($od_id, array $params): bool
    {
        $sqlLog = [];

        $requiredKeys = ['order', 'used', 'srw_id', 'svwr_id', 'insertImgData', 'not_working', 'missing_parts', 'action_required', 'ish_memo', 'mb_id'];
        try {
            if (!$this->baseService->validateParams($requiredKeys, $params)) {
                $sqlLog = ['params' => array_keys($params), 'requiredKeys' => $requiredKeys, 'params data' => $params];
                throw new Exception('필수 데이터가 없습니다.');
            }

            $checkData = $params['order'];
            $inboundOrderStatus = '입고완료';

            switch ($checkData['od_status']) {
                case '취소완료':
                    $inboundOrderStatus = '취소완료';
                    $updateData = " gso.od_cancel_receive_admin = '{$params['mb_id']}', ";
                    $joinCondition = "sism.od_id = gso.od_id";
                    break;
                case '미처리':
                    $inboundOrderStatus = '출고준비중';
                    $updateData = " gso.sr_it_barcode_no = NULL, 
                        gso.od_cancel_receive_admin = '{$params['mb_id']}', ";
                    $joinCondition = "sism.od_id = gso.od_id";
                    break;
                case '회수완료':
                case '회수준비중':
                default:
                    $joinCondition = "sism.od_id = gso.od_id_cancel";
                    // 택배건 입고처리시 sr_delivery_complete_dt 값이 비어있다면 입고일 등록 (택배는 크론에서 회수완료처리시 업데이트 시킴, 회수준비중일경우 값이 비어있음)
                    if (isset($checkData['sr_is_visit_return'])
                        && $checkData['sr_is_visit_return'] === '0'
                        && empty($checkData['sr_delivery_complete_dt'])
                    ) {
                        $updateData = " gso.sr_delivery_complete_dt = '{$this->currentTime}', ";
                    } else {
                        $updateData = '';
                    }
            }

            if ($params['used']) {
                $updateData .= " sism.sr_sto_status = 'used', sism.disinfection_yn = 'N', ";
            } else {
                $updateData .= " sism.disinfection_yn = 'Y', ";
            }

            // 회수 cart 에 임대종료일이 없다면 회수 & 원주문에 회수건 생성일로 업데이트 회수완료, 회수준비중만처리함
            if (empty($checkData['ordLendEndDtm'])
                && ($checkData['ct_status'] === '회수완료'
                    || $checkData['ct_status'] === '회수준비중')) {
                $updateData .= " gsc.ordLendEndDtm = '{$checkData['od_time']}', ";

                // 임대종료날짜가 비어있어서 컨트롤러에서 등록시킨값이니 원 주문에도 임대종료 날짜 등록해준다.
                $sqlLog['update_order_cart'] = "UPDATE g5_shop_cart SET ordLendEndDtm = '{$checkData['od_time']}' WHERE od_id = '{$checkData['od_id_cancel']}'";
                if (!sql_query($sqlLog['update_order_cart'])) {
                    throw new Exception('update g5_shop_cart 오류 롤백!');
                }
            }

            $damagedYn = $params['not_working'] === 'Y' || $params['missing_parts'] === 'Y' ? 'Y' : 'N';

            $sqlLog['update_order'] = "UPDATE g5_shop_order gso 
                INNER JOIN g5_shop_cart gsc ON gsc.od_id = gso.od_id 
                INNER JOIN sr_item_stock_manage sism ON {$joinCondition} 
                SET gso.od_status = '{$inboundOrderStatus}', 
                    {$updateData}
                    gsc.ct_status = '{$inboundOrderStatus}', 
                    gsc.ct_move_date = '{$this->currentTime}',
                    sism.od_id = NULL,
                    sism.barcode_location_status = 'warehouse',
                    sism.srw_id = '{$params['srw_id']}',
                    sism.svwr_id = '{$params['svwr_id']}',
                    sism.damaged_yn = '{$damagedYn}',
                    sism.not_working = '{$params['not_working']}',
                    sism.missing_parts = '{$params['missing_parts']}',
                    sism.action_required = '{$params['action_required']}',
                    sism.warehouse_put_dt = '{$this->currentTime}',
                    sism.svwr_put_dt = '{$this->currentTime}',
                    sism.disinfection_issue_yn = 'N'
                WHERE gso.od_id = '{$od_id}' 
                  AND gso.od_gb = '1' 
                  AND gso.od_del_yn = 'N' 
                  AND gso.sr_it_barcode_no IS NOT NULL 
                  AND gso.od_status in ('미처리', '취소완료', '회수완료', '회수준비중') 
                  AND sism.barcode_location_status = 'rental'";

            if (!sql_query($sqlLog['update_order'])) {
                throw new Exception('update_order 오류');
            }
            if (!$this->baseService->validateAffectedRows()) {
                throw new Exception('update_order 실패 mysqli_affected_rows = 0');
            }

            // 바코드 이동 기록
            $insertStockHistoryData = [
                'sr_stock_id' => $checkData['sr_stock_id'],
                'ish_history_type' => 'inbound',
                'ish_memo' => $params['ish_memo'],
                'ish_created_by' => $params['mb_id'],
                'ish_created_at' => $this->currentTime,
                'svwr_id' => $params['svwr_id'],
                'od_id' => $od_id,
                'return_ct_status' => $checkData['ct_status']
            ];
            $sqlLog['insert_it_stock_history'] = $this->baseService->setInsertMultipleBarcodeHistoryQuery([$insertStockHistoryData]);
            if (!sql_query($sqlLog['insert_it_stock_history'])) {
                throw new Exception('바코드 이동 기록 등록 오류 롤백!');
            }

            // 입고 바코드 이동 기록과 매칭되는 입고 사진 내역
            $ish_id = sql_insert_id();
            foreach ($params['insertImgData'] as $len => $row) {
                $params['insertImgData'][$len]['ish_id'] = $ish_id;
            }
            $insertColumn = $this->baseService->getKeys($params['insertImgData'][0]);
            $sqlLog['insert_item_stock_photos_sql'] = $this->baseService->setInsertMultipleQuery('sr_item_stock_inbound_photos', $insertColumn, $params['insertImgData']);
            if (!sql_query($sqlLog['insert_item_stock_photos_sql'])) {
                throw new Exception('sr_item_stock_inbound_photos 입고 처리 오류 롤백!');
            }

            // 주문 로그
            $insertOrderLogData = [
                'od_id' => $checkData['od_id'],
                'ct_status' => $inboundOrderStatus,
                'mb_id' => $params['mb_id'],
                'ol_content' => '입고처리',
                'created_dt' => $this->currentTime,
                'od_id_cancel' => $checkData['od_id_cancel']
            ];
            $insertColumn = $this->baseService->getKeys($insertOrderLogData);
            $sqlLog['insert_sr_order_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_order_log', $insertColumn, [$insertOrderLogData]);
            if (!sql_query($sqlLog['insert_sr_order_log_sql'])) {
                throw new Exception('insert_sr_order_log_sql 오류 롤백!');
            }

            // 배송 로그
            $insertDeliveryLogData = [
                'od_id' => $checkData['od_id'],
                'ct_delivery_company' => $checkData['ct_delivery_company'] ?? '',
                'ct_delivery_num' => $checkData['ct_delivery_num'] ?? '',
                'sr_install_partner' => $checkData['sr_install_partner_id'] ?? '',
                'dl_content' => '입고처리',
                'status' => $inboundOrderStatus,
                'created_dt' => $this->currentTime,
                'created_by' => $params['mb_id']
            ];
            if ($params['used']) {
                $insertDeliveryLogData['is_visit'] = $checkData['sr_is_visit_return'];
            } else {
                if($checkData['sr_is_visit_return'] === '1') {
                    $insertDeliveryLogData['is_visit'] = '1';
                } else {
                    $insertDeliveryLogData['is_visit'] = '0';
                }
            }
            $insertColumn = $this->baseService->getKeys($insertDeliveryLogData);
            $sqlLog['insert_sr_delivery_log_sql'] = $this->baseService->setInsertMultipleQuery('sr_delivery_log', $insertColumn, [$insertDeliveryLogData]);
            if (!sql_query($sqlLog['insert_sr_delivery_log_sql'])) {
                throw new Exception('insert_sr_delivery_log_sql 오류 롤백!');
            }

            return true;

        } catch (Exception $e) {
            $this->baseService->logger->debug([
                'callType' => $this->callType,
                'type' => 'order_inbound',
                'handler' => 'OrderInbound',
                'model' => 'updateInboundOrder',
                'message' => $e->getMessage(),
                'error' => $sqlLog], $this->logDir);

            return false;
        }
    }
}
