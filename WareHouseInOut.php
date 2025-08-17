<?php
include_once('../common.php');
include_once('./subrental_base.php');
include_once(G5_PATH . '/subrental_api/services/OrderStatusService.php');

class WareHouseInOut extends subrental_base
{
    /**
     * client
     * error_result: code
     * 0000: success
     * 0009: 에러
     * 0010: 리스트 새로 고침
     * 0011: 페이지 새로 고침
     * 0012: 페이지 이동
     * 9999: 로그 아웃 처리
     */
    protected $loginMb;
    /**
     * @var WareHouseModel
     */
    protected $wareHouseModel;
    /**
     * @var WareHouseInOutModel
     */
    protected $inOutModel;
    /**
     * @var WareHouseStockModel
     */
    protected $stockModel;
    /**
     * @var OrderModel
     */
    protected $orderModel;
    protected $pageLimit;
    protected $downLimit;

    protected $inboundType;
    protected $outboundType;
    protected $outboundStatus;
    protected $inboundStatus;
    protected $cancelStatus;
    protected $action_required_text;

    protected function createInit()
    {
        global $member;
        $this->loginMb = $member;
        $this->wareHouseModel = new WareHouseModel();
        $this->inOutModel = new WareHouseInOutModel();
        $this->stockModel = new WareHouseStockModel();
        $this->orderModel = new OrderModel();
        $this->pageLimit = 20;
        $this->downLimit = 50;
        $this->inboundType = ['pending', 'completed'];
        $this->outboundType = ['install', 'parcel'];
        $this->outboundStatus = ['주문완료', '출고준비중', '출고완료'];
        //$this->inboundStatus = ['회수완료'];
        $this->action_required_text = ['none' => '없음', 'repair' => '수리', 'other' => '기타', 'to_be_disposed' => '폐기예정'];
    }

    protected function convCtStatus($status): string
    {
        switch ($status) {
            case '회수준비중':
            case '회수완료':
                return '회수';
            case '취소완료':
                return '취소요청';
            case '미처리':
                return '미처리';
            default:
                return '';
        }
    }

    /**
     * 입고 처리 데이터 목록 세팅
     * @param int $srw_id
     * @param array $params
     * @param string $pagination
     * @param bool $mobile
     * @return array
     */
    protected function getInboundBarcodeListData(int $srw_id, array $params = [], string $pagination = 'more', bool $mobile = false): array
    {
        $limit = $this->pageLimit;
        $page = (int) ($params['page_no'] ?? 1);
        $sort = $params['sort'] ?? 'desc';
        if ($pagination === 'more') {
            $limit = 50;
            $getLimit = $limit + 1;
            $offset = (($page - 1 <= 0) ? 0 : ($page - 1)) * $limit;
        } else if($pagination === 'excel') {
            $offset = 0;
            $getLimit = 0;
        } else {
            // default: page
            $getLimit = $limit;
            $offset = (($page - 1 <= 0) ? 0 : ($page - 1)) * $limit;
        }

        $response = [
            'data' => [],
            'next' => false
        ];
        // 공통 검색
        // ? 택배 : 회수준비중 + 3일 -> 운송장번호가 없어도??? 입고처리??? 꼭 크론 필요한지??
        $whereAndQuery = [];
        // 상품 유형
        $whereAndQuery[] = $this->setCategoryQuery($params['category'], "gsi.ca_id");
        // 바코드 해지 여부
        if (!empty($params['barcode_cancel'])) {
            if (count($params['barcode_cancel']) == 1) {
                if (in_array('Y', $params['barcode_cancel'])) {
                    // 바코드 해제
                    $whereAndQuery[] = " sism.barcode_cancel_yn = 'Y' ";
                } else if (in_array('N', $params['barcode_cancel'])) {
                    // 바코드 해제 전
                    $whereAndQuery[] = " sism.barcode_cancel_yn = 'N' ";
                }
            }
        }
        // 검색어
        if (!empty($params['search_key'])) {
            $searchText= clean_xss_tags(trim($params['search_text']));
            if (trim($params['search_key']) !== 'all' && $searchText !== '') {
                $whereAndQuery[] = " ".clean_xss_tags(trim($params['search_key']))." like '%${searchText}%' ";
            } else if (trim($params['search_key']) === 'all' && $searchText !== '') {
                $whereAndQuery[] = "(
                    gsi.it_name like '%${searchText}%' 
                    or sism.sr_it_barcode_no LIKE '%${searchText}%'
                    or gso.od_name like '%${searchText}%' 
                )";
            }
        }
        // 입고 경로
        if (count($params['inbound_route']) == 1) {
            if ($params['type'] === 'pending') {
                if (in_array('install', $params['inbound_route'])) {
                    // 설치
                    $whereAndQuery[] = " (gsor.sr_is_visit_return = '1' or (gsor.od_id is null and gso.sr_is_visit_return = '1')) ";
                } else if (in_array('parcel', $params['inbound_route'])) {
                    // 택배
                    $whereAndQuery[] = " (gsor.sr_is_visit_return = '0' or (gsor.od_id is null and gso.sr_is_visit_return = '0')) ";
                }
            } else if ($params['type'] === 'completed') {
                if (in_array('install', $params['inbound_route'])) {
                    // 설치
                    $whereAndQuery[] = " (gso.sr_is_visit_return = '1') ";
                } else if (in_array('parcel', $params['inbound_route'])) {
                    // 택배
                    $whereAndQuery[] = " (gso.sr_is_visit_return = '0') ";
                }
            }
        }
        // 입고 사유
        if (count($params['inbound_reason']) > 0) {
            $orQuery = [];
            $ct_status = [];
            if ($params['type'] === 'pending') {
                if (in_array('return', $params['inbound_reason'])) {
                    // 회수
                    //$orQuery[] = " gscr.ct_status IN ('".implode("', '", $this->inboundStatus)."') ";
                    $orQuery[] = " (
                        (gsor.sr_is_visit_return = 1 AND gscr.ct_status = '회수완료') OR
                        (gsor.sr_is_visit_return = 0 AND gscr.ct_status IN ('회수완료', '회수준비중'))
                    ) ";
                }
                if (in_array('miss', $params['inbound_reason'])) {
                    // 미처리
                    $ct_status[] = "'미처리'";
                }
                if (in_array('cancel', $params['inbound_reason'])) {
                    // 취소요청
                    $ct_status[] = "'취소완료'";
                }
                if (count($ct_status) > 0) {
                    $orQuery[] = " gsc.ct_status in (".implode(", ", $ct_status).") ";
                }
            } else {
                if (in_array('return', $params['inbound_reason'])) {
                    // 회수
                    $orQuery[] = " gso.od_id_cancel is not null ";
                }
                if (in_array('miss', $params['inbound_reason'])) {
                    // 미처리
                    $ct_status[] = "'미처리'";
                }
                if (in_array('cancel', $params['inbound_reason'])) {
                    // 취소요청
                    $ct_status[] = "'취소완료'";
                }
                if (count($ct_status) > 0) {
                    $orQuery[] = " sish.return_ct_status in (".implode(", ", $ct_status).") ";
                }
            }
            $whereAndQuery[] = "(".implode(" or ", $orQuery).") ";
        }

        $whereQuery = "1=1";
        if (count($whereAndQuery) > 0) {
            $whereQuery = implode(" and ", $whereAndQuery);
        }

        switch ($params['type']) {
            case 'completed':
                // 입고완료
                $total = $this->inOutModel->getInboundCompletedCount($srw_id, $whereQuery);
                $response['total'] = $total['cnt'] ?? 0;
                $orderBy = "sish.warehouse_put_dt ${sort}";
                $data = $this->inOutModel->getInboundCompletedList($srw_id, $whereQuery, $offset, $getLimit, $orderBy);
                break;
            case 'pending':
                // 입고대기
                $total = $this->inOutModel->getInboundPendingCount($srw_id, $whereQuery);
                $response['total'] = $total['cnt'] ?? 0;
                $orderBy = "return_od_time ${sort}";
                $data = $this->inOutModel->getInboundPendingList($srw_id, $whereQuery, $offset, $getLimit, $orderBy);
                break;
            default :
                $response['total'] = 0;
                $data = [];
        }

        if ($data) {
            $response['next'] = count($data) > $limit;
            foreach ($data as $len => $row) {
                if ($pagination === 'more' && $len >= $limit) continue;
                $response['data'][$len] = $row;
                foreach ($row as $key => $val) {
                    $response['data'][$len][$key] = htmlspecialchars($val);
                }

                if (!empty($row['return_od_id'])) {
                    if ($row['return_sr_is_visit_return'] == '1') {
                        $response['data'][$len]['inbound_path'] = '설치업체';
                        $response['data'][$len]['install_or_delivery'] = htmlspecialchars($row['return_ic_name'])." ".htmlspecialchars($row['return_ip_name'])."<br>".htmlspecialchars($row['return_ip_phone']);
                        $response['data'][$len]['install_or_delivery_name'] = htmlspecialchars($row['return_ic_name']);
                    } else {
                        $response['data'][$len]['inbound_path'] = '택배';
                        $delivery = $this->getDeliveryCompanyRow($row['return_ct_delivery_company'], 'val');
                        $response['data'][$len]['install_or_delivery'] = $delivery['name']."<br>".htmlspecialchars($row['return_ct_delivery_num']);
                        $response['data'][$len]['install_or_delivery_name'] = $delivery['name'];
                    }
                    $response['data'][$len]['return_reason'] = $this->convCtStatus($response['data'][$len]['return_ct_status']);
                } else {
                    if ($row['sr_is_visit_return'] === '1') {
                        $response['data'][$len]['inbound_path'] = '설치업체';
                        $response['data'][$len]['install_or_delivery'] = htmlspecialchars($row['ic_name'])." ".htmlspecialchars($row['ip_name'])."<br>".htmlspecialchars($row['ip_phone']);
                        $response['data'][$len]['install_or_delivery_name'] = htmlspecialchars($row['ic_name']);
                    } else {
                        $response['data'][$len]['inbound_path'] = '택배';
                        $delivery = $this->getDeliveryCompanyRow($row['ct_delivery_company'], 'val');
                        $response['data'][$len]['install_or_delivery'] = $delivery['name']."<br>".htmlspecialchars($row['ct_delivery_num']);
                        $response['data'][$len]['install_or_delivery_name'] = $delivery['name'];
                    }
                    $response['data'][$len]['return_reason'] = $this->convCtStatus($response['data'][$len]['ct_status']);
                }

                if ($params['type'] === 'completed') {
                    $response['data'][$len]['barcode_status'] = $row['damaged_yn'] === 'Y' ? '비정상' : '정상';
                    $response['data'][$len]['uniqueId'] = $row['ish_id'];
                    $response['data'][$len]['return_reason'] = $this->convCtStatus($response['data'][$len]['return_ct_status']);
                } else {
                    $response['data'][$len]['uniqueId'] = $row['request_od_id'];
                }
                $response['data'][$len]['idx'] = 'auto_check_box';
            }
        }

        $response['pagination'] = $this->getPagination($response['total'], $page, $limit);
        $response['ui_pagination'] = $this->getUiPagination($response['total'], $page, $limit);

        return $response;
    }

    public function getClientInBoundInit($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundInit', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }
        // 상품 유형
        $response['category'] = $this->convHtmlSpecialCharsList($this->wareHouseModel->getCategoryList());
        if (empty($response['category'])) {
            return $this->error_result('네트워크 에러 잠시 후 다시 시도해주세요.', '0009');
        }
        // 데이터 리스트
        $response['list'] = $this->getInboundBarcodeListData($params['srw_id'], $params, 'page');

        return $this->set_rv($response);
    }

    public function getClientMobileInBoundInit(): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            // 페이지 시작시 설치 파트너 목록 & 주문 상태값 목록 & 상품 분류 목록
            global $is_admin;
            if ($is_admin === 'super') {
                $srw_name = $this->fixedRealWarehouseName;
            } else {
                $srw_name = $this->loginMb['mb_subrental_type'];
            }

            if (!$this->wareHouseModel->getRealWareHouseByName($srw_name)) {
                return $this->error_result('담당 창고 정보가 없습니다.', '9999');
            }

            // 상품 분류 목록
            $response['category'] = $this->convHtmlSpecialCharsList($this->wareHouseModel->getCategoryList());
            if (empty($response['category'])) {
                return $this->error_result('네트워크 에러 잠시 후 다시 시도해주세요.', '0009');
            }

            return $this->set_rv($response);
        }
    }

    public function getClientInBoundList($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }

        if (!in_array($params['type'], $this->inboundType)) {
            return $this->error_result('접근 키값이 없습니다.', '0009');
        }

        $response['list'] = $this->getInboundBarcodeListData($params['srw_id'], $params, 'page');
        return $this->set_rv($response);
    }

    public function getClientMobileInBoundList($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            if (!is_numeric($params['srw_id'])
                || !in_array($params['type'], $this->inboundType)
                || !is_array($params['category'])
                || !is_array($params['inbound_route'])
                || !is_array($params['inbound_reason'])) {
                return $this->error_result('접근 키값이 없습니다.', '0009');
            }

            $response['list'] = $this->getInboundBarcodeListData($params['srw_id'], $params, 'more', true);
            return $this->set_rv($response);
        }
    }

    /**
     * 모바일 상세 (입고 완료 상세 입고 대기 상세)
     * @param $params
     * @return string
     */
    public function getClientMobileInboundDetail($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            if (!is_numeric($params['srw_id'])
                || !is_numeric($params['uniqueId'])
                || !in_array($params['type'], $this->inboundType)) {
                return $this->error_result('접근 키값이 없습니다.', '0009');
            }

            if ($params['type'] === 'completed') {
                $result = $this->inOutModel->getInboundCompletedList($params['srw_id'], " sish.ish_id = '${params['uniqueId']}' ");
                $detail = $this->convHtmlSpecialChars($result[0]);
            } else {
                $result = $this->inOutModel->getInboundPendingOrder($params['srw_id'], [$params['uniqueId']]);
                $detail = $this->convHtmlSpecialChars($result);
            }
            $response['detail'] = $detail;

            if (empty($response['detail'])) {
                return $this->error_result('데이터가 없습니다.', '0012', 200, ['redirect' => '/subrental/mobile/warehouse/inbound']);
            }

            if ($detail['sr_is_visit_return'] === '1') {
                $response['detail']['inbound_path'] = '설치업체';
                $response['detail']['install_or_delivery_name'] = $detail['ic_name'];
            } else {
                $response['detail']['inbound_path'] = '택배';
                $delivery = $this->getDeliveryCompanyRow($detail['ct_delivery_company'], 'val');
                $response['detail']['install_or_delivery_name'] = $delivery['name'];
            }
            $response['detail']['return_reason'] = $this->convCtStatus($detail['ct_status']);

            if ($params['type'] === 'completed') {
                $response['detail']['barcode_status'] = $detail['damaged_yn'] === 'Y' ? '비정상' : '정상';
                $response['detail']['uniqueId'] = $detail['ish_id'];
                $response['detail']['return_reason'] = $this->convCtStatus($detail['return_ct_status']);
            } else {
                $response['detail']['uniqueId'] = $detail['request_od_id'];
            }

            return $this->set_rv($response);
        }
    }

    /**
     * 입고처리 모달 1건씩만 처리함
     * @param $params
     * @return string
     */
    public function getClientInBoundProcessModalData($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }

        function returnTypeFnc($_this, $isMobile, $message) {
            if (!empty($isMobile)) {
                return $_this->error_result($message, '0012', 200, ['redirect' => '/subrental/mobile/warehouse/inbound']);
            } else {
                return $_this->error_result($message, '0010');
            }
        }

        if (!is_numeric($params['od_id'])) {
            return returnTypeFnc($this, $params['isMobile'], '입고 처리 대상 키값이 없습니다.');
        }

        if (!$pendingData = $this->inOutModel->getInboundPendingOrder($params['srw_id'], [$params['od_id']])) {
            return returnTypeFnc($this, $params['isMobile'], '입고 처리할 상품이 없습니다.');
        }

        if ($pendingData['sr_is_visit_return'] === '0') {
            if ($pendingData['ct_status'] !== '회수완료'
                && $pendingData['ct_status'] !== '회수준비중'
                && $pendingData['ct_status'] !== '취소완료'
                && $pendingData['ct_status'] !== '미처리') {
                return returnTypeFnc($this, $params['isMobile'], '이미 입고완료 되었거나 입고 처리 대상이 아닙니다.');
            }
        } else {
            if ($pendingData['ct_status'] !== '회수완료'
                && $pendingData['ct_status'] !== '취소완료'
                && $pendingData['ct_status'] !== '미처리') {
                return returnTypeFnc($this, $params['isMobile'], '이미 입고완료 되었거나 입고 처리 대상이 아닙니다.');
            }
        }

        if ($pendingData['ct_status'] === '취소완료' || $pendingData['ct_status'] === '미처리') {
            $used = false;
        } else {
            $used = true;
        }

        if ($used) {
            if ($pendingData['sr_is_visit_return'] === '0') {
                // 택배
                if (empty($pendingData['ct_delivery_company']) || preg_replace('/\s+/', '', $pendingData['ct_delivery_num']) === '') {
                    return returnTypeFnc($this, $params['isMobile'], '택배 정보가 없습니다.');
                }
            } else {
                // 방문
                if (empty($pendingData['sr_install_partner_id']) || empty($pendingData['ip_name'])) {
                    return returnTypeFnc($this, $params['isMobile'], '회수업체 정보가 없습니다.');
                }
                if ($pendingData['sr_happy_call_yn'] === 'N') {
                    return returnTypeFnc($this, $params['isMobile'], '해당 목록은 해피콜 연결 전입니다.');
                }
            }
        }

        if ($pendingData['barcode_location_status'] === 'warehouse') {
            return returnTypeFnc($this, $params['isMobile'], '해당 항목은 이미 입고 완료 되었습니다.');
        }

        $data = $this->wareHouseModel->getRackUsageByReal($params['srw_id']);
        $virtual = [];
        $rack = [];
        $tempId = "";
        foreach ($data as $row) {
            if ($tempId != $row['svw_id']) {
                $tempId = $row['svw_id'];
                $virtual[] = $row;
            }
        }
        foreach ($virtual as $vRow) {
            $rack[$vRow['svw_id']] = [];
            foreach ($data as $row) {
                if ($vRow['svw_id'] === $row['svw_id']) {
                    $rack[$vRow['svw_id']][] = $row;
                }
            }
        }
        $response['dataInfo'] = $pendingData;
        $response['virtual'] = $virtual;
        $response['rack'] = $rack;

        // 미리보기로 임시저장된 파일이 있다면 불러옴
        $orderStatusService = new OrderStatusService();
        $tempFiles = $orderStatusService->getTempFiles($params['od_id'], 'inbound');
        $response['files'] = $tempFiles;

        return $this->set_rv($response);
    }

    public function getClientMobileInBoundProcessModalData($params): string
    {
        if (!$this->checkWareHouseAuth($params)) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            $params['isMobile'] = true;
            return $this->getClientInBoundProcessModalData($params);
        }
    }

    /**
     * 입고 처리할 파일 미리보기 가져오기 (페이지 접근시)
     * @param $params
     * @return string
     */
    public function getInBoundTempPhotos($params): string
    {
        if (!is_numeric($params['od_id'])) {
            return $this->error_result('주문코드가 없습니다.', '0009');
        }

        $orderStatusService = new OrderStatusService();
        $tempFiles = $orderStatusService->getTempFiles($params['od_id'], 'inbound');

        return $this->set_rv(['files' => $tempFiles]);
    }

    /**
     * 입고처리할 파일 미리보기 임시 저장하기
     * @param $params
     * @return string
     */
    public function updateInBoundTempPhotoFormData($params): string
    {
        if (!is_numeric($params['od_id'])) {
            return $this->error_result('주문코드가 없습니다.', '0009');
        }

        $orderStatusService = new OrderStatusService();
        $result = $orderStatusService->updateTempFilesFormData($params['od_id'], $params['files'], 'inbound');

        if ($result['status'] === 'success') {
            return $this->set_rv(['files' => $result['result']]);
        } else {
            return $this->error_result($result['message'], $result['code'], 200, $result['result']);
        }
    }

    /**
     * 미리보기 이미지 한건씩 삭제
     * @param $params
     * @return string
     */
    public function deleteInBoundTempPhoto($params): string
    {
        if (!is_numeric($params['od_id']) || empty($params['filePath'])) {
            return $this->error_result('주문코드 또는 삭제할 이미지가 없습니다.', '0009');
        }

        $orderStatusService = new OrderStatusService();
        $result = $orderStatusService->removeTempFile($params['od_id'], $params['filePath']);

        if ($result['status'] === 'success') {
            return $this->set_rv(null);
        } else {
            return $this->error_result($result['message'], $result['code'], 200, $result['result']);
        }
    }

    /**
     * 입고 처리 submit
     * @param $params
     * @return string
     */
    public function inboundOrderProcess($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }

        if (!is_numeric($params['od_id'])
            || !is_numeric($params['svwr_id'])
            || empty($params['not_working'])
            || empty($params['missing_parts'])
            || empty($params['action_required'])
            || !isset($params['ish_memo'])
            || !is_array($params['files'])) {
            return $this->error_result('입고 처리 대상 키값이 없습니다.', '0009');
        }

        // 서비스로직 추가 (주문에 관한 상태값변경은 OrderStatusService 에서 관리)
        $orderStatusService = new OrderStatusService();
        $result = $orderStatusService->updateStatus('order_inbound', '입출고창고', $params['od_id'], $params, true);
        if ($result['status'] === 'success') {
            return $this->set_rv(null);
        } else {
            return $this->error_result($result['message'], $result['code'], 200, $result['result']);
        }
    }

    public function mobileInboundOrderProcess($params): string
    {
        if (!$this->checkWareHouseAuth($params)) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            $params['isMobile'] = true;
            return $this->inboundOrderProcess($params);
        }
    }

    /**
     * 입고 처리 내역 모달 보기
     * @param $params
     * @return string
     */
    public function getInboundCompletedInfo($params): string
    {
        if (!is_numeric($params['srw_id']) || !is_numeric($params['ish_id'])) {
            return $this->error_result('입고 처리내역 키값이 없습니다.', '0010');
        }
        $whereQuery = " sish.ish_id = ${params['ish_id']} ";
        $response['info'] = $this->convHtmlSpecialCharsList($this->inOutModel->getInboundCompletedList($params['srw_id'], $whereQuery));
        if($response['info'][0]) {
            $response['info'][0]['barcode_status'] = $response['info'][0]['not_working'] === 'Y' ? '비정상' : '정상';
            $response['info'][0]['missing_parts'] = $response['info'][0]['missing_parts'] === 'Y' ? '있음' : '없음';
            $response['info'][0]['action_required'] = $this->action_required_text[$response['info'][0]['action_required']];
            $warehouse = explode("|", $response['info'][0]['ish_location_text']);
            $response['info'][0]['virtual_name'] = $warehouse[0];
            $response['info'][0]['rack_name'] = $warehouse[1];
            $response['info'][0]['ish_memo'] = nl2br($response['info'][0]['ish_memo']);
        }
        $response['photo'] = $this->convHtmlSpecialCharsList($this->inOutModel->getInboundCompletedPhotos($params['ish_id']));

        return $this->set_rv($response);
    }

    /**
     * 사용자 화면 입고 처리 내역 모달 보기
     * @param $params
     * @return string
     */
    public function getClientInboundCompletedInfo($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }
        return $this->getInboundCompletedInfo($params);
    }

    public function getClientMobileInboundCompletedInfo($params): string
    {
        if (!$this->checkWareHouseAuth($params)) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            return $this->getClientInboundCompletedInfo($params);
        }
    }

    /**
     * 출고 처리 데이터 목록 세팅 ('주문완료', '출고준비중', '출고완료')
     * @param int $srw_id
     * @param array $params
     * @param string $pagination
     * @param bool $mobile
     * @return array
     */
    protected function getOutBoundBarcodeListData(int $srw_id, array $params = [], string $pagination = 'more', bool $mobile = false): array
    {
        $limit = $this->pageLimit;
        $page = (int) ($params['page_no'] ?? 1);
        $sort = $params['sort'] ?? 'desc';
        if ($pagination === 'more') {
            $limit = 50;
            $getLimit = $limit + 1;
        } else if ($pagination === 'excel') {
            $getLimit = 0;
        } else {
            $getLimit = $limit;
        }
        $offset = (($page - 1 <= 0) ? 0 : ($page - 1)) * $limit;

        $response = [
            'data' => [],
            'next' => false,
            'total' => 0
        ];

        $whereAndQuery = [];
        $whereAndQuery[] = " gsc.ct_status in ('".implode("','", $this->outboundStatus)."') ";

        if ($params['type'] === 'install') {
            // 설치 업체 - 설치 상품은 주문서에 설치업체 정보가 들어가고 리얼창고가 등록되어있음
            $whereAndQuery[] = " sic.assigned_srw_id = ${srw_id} ";
            $whereAndQuery[] = " gso.sr_is_visit_return = '1' ";
            // 설치 목록 정렬
            $orderBy = " gso.sr_delivery_hope_dt ${sort}, gso.od_time desc ";

            if (!empty($params['sr_install_partner_id']) && $params['sr_install_partner_id'] != '') {
                $whereAndQuery[] = " gso.sr_install_partner_id = '".clean_xss_tags($params['sr_install_partner_id'])."' ";
            }
        } else {
            //@todo: 택배는 현재 상태에선 "CMK파주본사창고"에서만 보냄 추후 여러업체에서 보낼수있도록 작업할지도?
            // 택배 - 택배건은 전부 $this->fixedRealWarehouseName 에서 보냄
            // srw_id 를 가지고 $this->fixedRealWarehouseName 매칭시켜서 $this->fixedRealWarehouseName 리스트를 가져오고 아니면 [] 리턴
            $whereAndQuery[] = " gso.sr_is_visit_return = '0' ";
            if (!$warehouse = $this->wareHouseModel->getRealWareHouseNameBySrwId($srw_id)) {
                return $response;
            }
            if ($warehouse['srw_name'] !== $this->fixedRealWarehouseName) {
                return $response;
            }
            // 택배 목록 정렬
            $orderBy = " gso.od_time ${sort} ";
        }

        if (!empty($params['start_date'])) {
            $whereAndQuery[] = " gso.od_time >= '".clean_xss_tags($params['start_date'])." 00:00:00' ";
        }
        if (!empty($params['end_date'])) {
            $whereAndQuery[] = " gso.od_time <= '".clean_xss_tags($params['end_date'])." 23:59:59' ";
        }

        // 상품 유형
        $whereAndQuery[] = $this->setCategoryQuery($params['category'], "gsi.ca_id");
        // 상품 상태
        if(count($params['order_status']) > 0) {
            $orQuery = [];
            if (in_array('주문완료', $params['order_status'])) {
                // 소독 완료: 창고에 보관중인 제품중 임대이력 있고 소독완료된 자산
                $orQuery[] = " (gsc.ct_status = '주문완료') ";
            }
            if (in_array('출고준비중', $params['order_status'])) {
                // 소독 완료: 창고에 보관중인 제품중 임대이력 있고 소독완료된 자산
                $orQuery[] = " (gsc.ct_status = '출고준비중') ";
            }
            if (in_array('출고완료', $params['order_status'])) {
                // 소독 완료: 창고에 보관중인 제품중 임대이력 있고 소독완료된 자산
                $orQuery[] = " (gsc.ct_status = '출고완료') ";
            }
            if (count($orQuery) > 0) {
                $whereAndQuery[] = " (".implode(" or ", $orQuery).") ";
            }
        } else {
            $whereAndQuery[] = " (gsc.ct_status in ('주문완료', '출고준비중', '출고완료')) ";
        }

        // 검색어
        $searchText= clean_xss_tags(trim($params['search_text']));
        if (trim($params['search_key']) !== 'all' && $searchText !== '') {
            $whereAndQuery[] = " ".clean_xss_tags(trim($params['search_key']))." like '%${searchText}%' ";
        } else if (trim($params['search_key']) === 'all' && $searchText !== '') {
            $whereAndQuery[] = "(
                gsi.it_name like '%${searchText}%' 
                or gso.sr_it_barcode_no LIKE '%${searchText}%'
                or gso.od_name like '%${searchText}%' 
            )";
        }

        $whereQuery = implode(" and ", $whereAndQuery);

        $total = $this->inOutModel->getOutboundOrderCount($srw_id, $whereQuery);
        $response['total'] = $total['cnt'] ?? 0;

        if ($data = $this->inOutModel->getOutboundOrderList($srw_id, $whereQuery, $offset, $getLimit, $orderBy)) {
            $response['next'] = count($data) > $limit;
            foreach ($data as $len => $row) {
                if ($pagination === 'more' && $len >= $limit) continue;
                foreach ($row as $key => $val) {
                    $response['data'][$len][$key] = htmlspecialchars($val);
                }
                $convRow = $response['data'][$len];

                if (!empty($convRow['sr_it_barcode_no'])) {
                    if ($convRow['barcode_location_status'] === 'warehouse') {
                        if ($convRow['svwr_id']) {
                            $response['data'][$len]['warehouse_location_text'] = $convRow['svw_name']." | ".$convRow['svwr_name']."렉";
                        } else {
                            $response['data'][$len]['warehouse_location_text'] = '창고 미등록';
                        }
                    } else if ($convRow['barcode_location_status'] === 'rental') {
                        $response['data'][$len]['warehouse_location_text'] = "출고완료";
                    } else {
                        $response['data'][$len]['warehouse_location_text'] = "-";
                    }
                } else {
                    $response['data'][$len]['sr_it_barcode_no'] = "";
                    $response['data'][$len]['warehouse_location_text'] = "-";
                }
                if ($convRow['ct_delivery_num'] != '' && !empty($convRow['ct_delivery_company'])) {
                    $delivery = $this->getDeliveryCompanyRow($convRow['ct_delivery_company'], 'val');
                    $response['data'][$len]['ct_delivery'] = $delivery['name']."<br>".$convRow['ct_delivery_num'];
                    $response['data'][$len]['delivery_company_name'] = $delivery['name'];
                } else if (!empty($convRow['ct_delivery_num'])) {
                    $response['data'][$len]['ct_delivery'] = $convRow['ct_delivery_num'];
                } else if (!empty($convRow['ct_delivery_company'])) {
                    $delivery = $this->getDeliveryCompanyRow($convRow['ct_delivery_company'], 'val');
                    $response['data'][$len]['ct_delivery'] = $delivery['name'];
                    $response['data'][$len]['delivery_company_name'] = $delivery['name'];
                } else {
                    $response['data'][$len]['ct_delivery'] = "입력";
                }

                $response['data'][$len]['uniqueId'] = $row['od_id'];
            }
        }

        $response['pagination'] = $this->getPagination($response['total'], $page, $limit);
        $response['ui_pagination'] = $this->getUiPagination($response['total'], $page, $limit);

        return $response;
    }

    /**
     * 출고 처리 init (상품 유형 + 출고 리스트)
     * @param $params
     * @return string
     */
    public function getClientOutBoundInit($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }
        if (!in_array($params['type'], $this->outboundType)
            || !is_array($params['category'])
            || !is_array($params['order_status'])
            || empty($params['start_date'])
            || empty($params['end_date'])
            || empty($params['search_key'])
            || !isset($params['search_text'])) {
            return $this->error_result('접근 키값이 없습니다.', '0009');
        }
        // 상품 유형
        $response['category'] = $this->convHtmlSpecialCharsList($this->wareHouseModel->getCategoryList());
        if (empty($response['category'])) {
            return $this->error_result('네트워크 에러 잠시 후 다시 시도해주세요.', '0009');
        }
        // 데이터 리스트
        $response['list'] = $this->getOutBoundBarcodeListData($params['srw_id'], $params, 'page');

        return $this->set_rv($response);
    }

    /**
     * 모바일 next js 서버 컴포넌트 init 데이터
     * 필요 설정값만 불러옴
     *
     * @param $params
     * @return string
     */
    public function getClientMobileOutBoundInit($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            // 페이지 시작시 설치 파트너 목록 & 주문 상태값 목록 & 상품 분류 목록
            global $is_admin;
            if ($is_admin === 'super') {
                $srw_name = $this->fixedRealWarehouseName;
            } else {
                $srw_name = $this->loginMb['mb_subrental_type'];
            }
            if (!$real = $this->wareHouseModel->getRealWareHouseByName($srw_name)) {
                return $this->error_result('담당 창고 정보가 없습니다..', '9999');
            }

            // 상품 분류 목록
            $response['category'] = $this->convHtmlSpecialCharsList($this->wareHouseModel->getCategoryList());
            if (empty($response['category'])) {
                return $this->error_result('네트워크 에러 잠시 후 다시 시도해주세요.', '0009');
            }
            // 주문 상태값 목록
            $response['odStatus'] = $this->outboundStatus;
            // 설치 업체 목록
            $response['installPartnerList'] = $this->inOutModel->getInstallPartnerList($real['srw_id']);

            return $this->set_rv($response);
        }
    }

    /**
     * 출고 리스트
     * @param $params
     * @return string
     */
    public function getClientOutBoundList($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }

        if (!in_array($params['type'], $this->outboundType)
            || !is_array($params['category'])
            || !is_array($params['order_status'])
            || empty($params['start_date'])
            || empty($params['end_date'])
            || empty($params['search_key'])
            || !isset($params['search_text'])) {
            return $this->error_result('접근 키값이 없습니다.', '0009');
        }

        // 데이터 리스트
        $response['list'] = $this->getOutBoundBarcodeListData($params['srw_id'], $params, 'page');

        return $this->set_rv($response);
    }

    /**
     * 모바일 출고 리스트
     * @param $params
     * @return string
     */
    public function getClientMobileOutBoundList($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            if (!is_numeric($params['srw_id'])
                || !in_array($params['type'], $this->outboundType)
                || !is_array($params['category'])
                || !is_array($params['order_status'])
                || empty($params['start_date'])
                || empty($params['end_date'])
                || !isset($params['search_text'])) {
                return $this->error_result('접근 키값이 없습니다.', '0009');
            }

            // 데이터 리스트
            $response['list'] = $this->getOutBoundBarcodeListData($params['srw_id'], $params, 'more', true);

            return $this->set_rv($response);
        }
    }

    /**
     * 모바일 출고 대기 상세
     * @param $params
     * @return string
     */
    public function getClientMobileOutBoundDetail($params): string
    {
        $srw_id = $this->getRealWarehouseId();
        if (!$this->checkWareHouseAuth() || !$srw_id) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            if (!is_numeric($params['od_id'])) {
                return $this->error_result('접근 키값이 없습니다.', '0012');
            }
            // 출고 대기 상세 데이터
            if ($orderDetail = $this->inOutModel->getOutboundOrder($params['od_id'])) {
                $orderDetail = $this->convHtmlSpecialChars($orderDetail);
                $orderDetail['od_time'] = date('Y-m-d', strtotime($orderDetail['od_time']));
                if (!empty($orderDetail['od_memo'])) {
                    $orderDetail['od_memo'] = nl2br($orderDetail['od_memo']);
                }
                $orderDetail['uniqueId'] = $orderDetail['od_id'];
                if ($orderDetail['sr_is_visit_return'] === '0') {
                    // 택배
                    if ($delivery = $this->getDeliveryCompanyRow($orderDetail['ct_delivery_company'], 'val')) {
                        $orderDetail['delivery_company_name'] = $delivery['name'];
                    } else {
                        $orderDetail['delivery_company_name'] = '';
                    }
                }
                return $this->set_rv($orderDetail);
            } else {
                $this->logger->error([
                    'class' => 'WareHouseInOut::getClientMobileOutBoundDetail',
                    'message' => '상세 데이터가 없습니다.',
                    'error' => ['params' => $params, 'member' => $this->loginMb['mb_id']]], 'subrental/api/mobile/warehouse_inout');
                return $this->error_result('데이터가 없습니다.', '0012');
            }
        }
    }

    /**
     * 주문서에 등록할 바코드 가져 오기
     * @param $params
     * @return string
     */
    public function getAvailableBarcodeList($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }

        if (!is_numeric($params['od_id'])) {
            return $this->error_result('출고 주문건에 대한 키값이 없습니다.', '0009');
        }

        // 바코드가 등록되어있는지 확인
        if (!$order = $this->inOutModel->getOutboundOrder($params['od_id'])) {
            return $this->error_result('네트워크 오류, 주문 정보를 불러오지 못했습니다.', '0009');
        }
        if ($order['ct_status'] === "출고완료") {
            return $this->error_result('출고완료 처리된 주문입니다.', '0010');
        }
        if (!empty($order['sr_it_barcode_no'])) {
            return $this->error_result('이미 바코드가 입력되었습니다.', '0010');
        }

        $response = [
            'order' => $this->convHtmlSpecialChars($order),
            'barcode' => []
        ];
        // 해당 주문에 대한 프로모션 (새제품)이 있는지 확인 & ca_id = 9010 전동침대만
        if (in_array('새제품', explode(",", $order['sp_display_type']))) {
            $response['barcode'] = $barcodeList = $this->inOutModel->getAvailableNewBarcodeListByItId($params['srw_id'], $order['it_id']);
        } else {
            // 소독 Y & 하자 N & 소독일자 빠른순으로 20개 & 출고대기 리스트에 입력된 바코드 제외
            $response['barcode'] = $this->inOutModel->getAvailableBarcodeListByItId($params['srw_id'], $order['it_id']);
        }

        return $this->set_rv($response);
    }

    /**
     * 모바일 출고 대기 상세 > 주문서에 등록할 바코드 가져 오기
     * @param $params
     * @return string
     */
    public function getClientMobileAvailableBarcodeList($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            return $this->getAvailableBarcodeList($params);
        }
    }

    /**
     * 주문서에 바코드 입력
     * @param $params
     * @return string
     */
    public function updateOrderBarcode($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }
        if (!is_numeric($params['od_id'])
            || empty($params['sr_it_barcode_no'])
            || empty($params['type'])) {
            return $this->error_result('등록할 바코드가 없습니다.', '0010');
        }

        // 서비스로직 추가 (주문에 관한 상태값변경은 OrderStatusService 에서 관리)
        $orderStatusService = new OrderStatusService();
        $result = $orderStatusService->updateStatus('order_barcode_match', '입출고창고', $params['od_id'], $params, true);
        if ($result['status'] === 'success') {
            return $this->set_rv(null);
        } else {
            return $this->error_result($result['message'], $result['code']);
        }
    }

    /**
     * 모바일 주문서에 바코드 등록
     * @param $params
     * @return string
     */
    public function updateClientMobileOrderBarcode($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            return $this->updateOrderBarcode($params);
        }
    }

    /**
     * 모바일 주문서에 바코드 & 택배정보 등록 (택배사 && 운송장번호)
     * @param $params
     * @return string
     */
    public function updateClientMobileOrderBarcodeAndDelivery($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            if (!is_numeric($params['od_id'])) {
                return $this->error_result('주문서 정보가 없습니다.', '0012', 200, ['redirect' => '/subrental/mobile/warehouse/outbound']);
            }

            // 간략 정보 불러오기
            if (!$order = $this->inOutModel->getOutboundOrder($params['od_id'])) {
                return $this->error_result('네트워크 오류, 주문 정보를 불로오지 못했습니다.', '0010');
            }

            if ($order['ct_status'] !== "주문완료" && $order['ct_status'] !== "출고준비중") {
                return $this->error_result('출고 처리된 주문입니다.', '0010');
            }

            if (!empty($params['sr_it_barcode_no']) && $params['sr_it_barcode_no'] !== '') {
                $barcodeResponse = json_decode($this->updateOrderBarcode($params));
            }

            if (!empty($params['ct_delivery_company']) && $params['ct_delivery_company'] !== ''
                && !empty($params['ct_delivery_num']) && $params['ct_delivery_num'] !== '') {
                $deliveryResponse = json_decode($this->updateOrderDelivery($params));
            }

            if (isset($barcodeResponse) && isset($deliveryResponse)) {
                if ($barcodeResponse->error_msg === 'success' && $deliveryResponse->error_msg === 'success') {
                    return $this->set_rv(null);
                } else {
                    if ($barcodeResponse->error_msg === 'success') {
                        return json_encode($deliveryResponse);
                    } else {
                        return json_encode($barcodeResponse);
                    }
                }
            } else if (isset($barcodeResponse) && !isset($deliveryResponse)) {
                return json_encode($barcodeResponse);
            } else if (!isset($barcodeResponse) && isset($deliveryResponse)) {
                return json_encode($deliveryResponse);
            } else {
                return $this->error_result('수정할 내용이 없습니다.', '0010');
            }
        }
    }

    public function getDeliveryCompanyList($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            global $delivery_companys;
            return $this->set_rv($delivery_companys);
        }
    }

    /**
     * 택배 정보 모달
     * @param $params
     * @return string
     */
    public function getOrderDeliveryInfo($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }
        if (!is_numeric($params['od_id'])) {
            return $this->error_result('택배 운송장을 입력할 주문이 없습니다.', '0010');
        }
        // 간략 정보 불러오기
        if (!$order = $this->inOutModel->getOutboundOrder($params['od_id'])) {
            return $this->error_result('네트워크 오류, 주문 정보를 불로오지 못했습니다.', '0009');
        }
        if (!empty($order['barcode_location_status']) && $order['barcode_location_status'] === 'rental') {
            return $this->error_result('촐고완료된 주문 입니다.', '0010');
        }
        global $delivery_companys;
        $response = [
            'info' => $order,
            'delivery' => $delivery_companys
        ];
        return $this->set_rv($response);
    }

    /**
     * 출고 전 택배 정보 등록
     * @param $params
     * @return string
     */
    public function updateOrderDelivery($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }
        if (!is_numeric($params['od_id'])
            || empty($params['ct_delivery_company'])
            || !isset($params['ct_delivery_num'])
            || preg_replace('/\s+/', '', $params['ct_delivery_num']) === '') {
            return $this->error_result("누락된 운송장 정보가 있습니다.", "0009");
        }
        global $delivery_companys;
        $ct_delivery_company = trim($params['ct_delivery_company']);
        $ct_delivery_num = trim($params['ct_delivery_num']);

        $flag = false;
        foreach ($delivery_companys as $company) {
            if ($company['val'] == $ct_delivery_company) {
                $flag = true;
                break;
            }
        }
        if (!$flag) {
            return $this->error_result('택배사 정보 오류', '0009');
        }

        // 간략 정보 불러오기
        if (!$order = $this->inOutModel->getOutboundOrder($params['od_id'])) {
            return $this->error_result('네트워크 오류, 주문 정보를 불로오지 못했습니다.', '0009');
        }

        if (!empty($order['barcode_location_status']) && $order['barcode_location_status'] === 'rental') {
            return $this->error_result('촐고완료된 주문 입니다.', '0009');
        }

        if ($order['ct_delivery_company'] == $ct_delivery_company && $order['ct_delivery_num'] == $ct_delivery_num) {
            return $this->error_result('택배 정보가 동일합니다.', '0009');
        }

        if ($order['ct_delivery_company'] !== 'etc') {
            $ct_delivery_num = preg_replace('/\s+/', '', $ct_delivery_num);
            if (!preg_match('/^\d+$/', $ct_delivery_num)) {
                return $this->error_result('송장번호는 숫자만 등록 가능합니다.', '0009');
            }
        }

        $updateData = [];
        $updateData[0] = [
            'od_id' => $order['od_id'],
            'ct_id' => $order['ct_id'],
            'ct_delivery_company' => $params['ct_delivery_company'],
            'ct_delivery_num' => $params['ct_delivery_num'],
            'ct_status' => $order['ct_status']
        ];

        if ($this->orderModel->updateOrderDeliveryInfo($updateData, $this->loginMb['mb_id'], "입출고창고 출고처리 택배정보 등록")) {
            return $this->set_rv(null);
        } else {
            return $this->error_result('운송장 정보 등록 실패', '0009');
        }
    }

    /**
     * 촐고 처리 submit
     * @param $params
     * @return string
     */
    public function outboundOrderProcess($params): string
    {
        if (!$this->checkClientMember($params['mb_no'], $params['srw_id'], 'getClientInBoundList', 'warehouse_inout')) {
            return $this->error_result("계정 정보가 없습니다. 다시 로그인하시기 바랍니다.", "9999");
        }

        if (!is_array($params['od_ids'])
            || empty($params['od_ids'])
            || !in_array($params['type'], $this->outboundType)) {
            return $this->error_result('출고 주문건에 대한 키값이 없습니다.', '0009');
        }

        // 서비스로직 추가 (주문에 관한 상태값변경은 OrderStatusService 에서 관리)
        $orderStatusService = new OrderStatusService();
        $result = $orderStatusService->updateStatus('order_outbound', '입출고창고', $params['od_ids'], $params, true);
        if ($result['status'] === 'success') {
            // 택배건만 알림톡 발송
            if ($orders = $this->orderModel->getOrdersByIds($params['od_ids'], "gso.od_id, gso.sr_is_visit_return")) {
                $sendAlimTalkOdIds = [];
                foreach ($orders as $row) {
                    if ($row['sr_is_visit_return'] === '0') {
                        $sendAlimTalkOdIds[] = $row['od_id'];
                    }
                }
                if (!empty($sendAlimTalkOdIds)) {
                    if(!outboundOrderNoticeSend($sendAlimTalkOdIds)) {
                        $this->logger->error([
                            'class' => 'outboundOrderProcess',
                            'message' => '출고완료 알림톡전송실패',
                            'error' => ['send alimTalk od_id' => $sendAlimTalkOdIds]], 'subrental/api/warehouse_inout');
                    }
                }
            }
            return $this->set_rv(null);
        } else {
            return $this->error_result($result['message'], $result['code']);
        }
    }

    public function outboundMobileOrderProcess($params): string
    {
        if (!$this->checkWareHouseAuth()) {
            return $this->error_result('계정 정보가 없습니다. 다시 로그인하시기 바랍니다.', '9999');
        } else {
            $params['isMobile'] = true;
            return $this->outboundOrderProcess($params);
        }
    }
}
