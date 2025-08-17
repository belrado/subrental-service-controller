<?php

require_once(G5_PATH . "/subrental_api/services/orderStatusHandlers/OrderStatusHandlerAbstract.php");
require_once(G5_PATH . "/subrental_api/modules/WareHouseInOutModel.php");
require_once(G5_PATH . "/subrental_api/modules/WareHouseModel.php");

/**
 * 입고완료
 *
 */
class OrderInbound extends OrderStatusHandlerAbstract
{
    protected $orderStatusService;
    /**
     * @var OrderStatusModel
     */
    protected $orderStatusModel;
    protected $inOutModel;
    protected $wareHouseModel;

    public function __construct($orderStatusModel, $callType, BaseService $baseService, OrderStatusService $serviceInstance)
    {
        parent::__construct($orderStatusModel, $callType, $baseService);
        $this->orderStatusService = $serviceInstance;
        $this->orderStatusModel = $orderStatusModel;
        $this->inOutModel = new WareHouseInOutModel();
        $this->wareHouseModel = new WareHouseModel();
    }

    public function handle($od_id, array $params, bool $useTransaction): array
    {
        $currentTime = date('Y-m-d H:i:s', time());
        $uploadedFilePath = [];
        $insertImgData = [];
        $customParams = [];

        $required = ['srw_id', 'svwr_id', 'not_working', 'missing_parts', 'action_required', 'ish_memo', 'files'];
        if (!$this->baseService->validateParams($required, $params) || !is_numeric($od_id)) {
            return $this->returnData("error", "네트워크 오류, 필수값이 없습니다.", ['params' =>$params], "0009");
        }

        if (!$checkData = $this->inOutModel->getInboundPendingOrder($params['srw_id'], [$od_id])) {
            return $this->returnType($params['isMobile'], '입고 처리할 항목이 없습니다.', ['params_od_id' => $od_id]);
        }
        // 미처리 & 취소 요청은 소독 Y new 상품이면 그대로 new
        // 입고대기 = 미처리 상태값 + 회수요청, 회수준비중, 회수완료 -> 입고처리시 해피콜 Y 인지, 택배 송장번호가 있는지 확인
        if ($checkData['od_status'] === '취소완료' || $checkData['od_status'] === '미처리') {
            $used = false;
        } else {
            $used = true;
        }

        if ($used) {
            if ($checkData['sr_is_visit_return'] === '0') {
                // 택배
                if (empty($checkData['ct_delivery_company']) || preg_replace('/\s+/', '', $checkData['ct_delivery_num']) === '') {
                    return $this->returnData('error', '택배 정보가 없습니다.', ['params_od_id' => $od_id], '0010');
                }
            } else {
                // 방문
                if (empty($checkData['sr_install_partner_id']) || empty($checkData['ip_name'])) {
                    return $this->returnData('error', '회수업체 정보가 없습니다.', ['params_od_id' => $od_id], '0010');
                }
                if ($checkData['sr_happy_call_yn'] === 'N') {
                    return $this->returnData('error', '해당 목록은 해피콜 연결 전입니다.', ['params_od_id' => $od_id], '0010');
                }
            }
        }

        if ($checkData['od_status'] === '입고완료' || $checkData['barcode_location_status'] !== 'rental') {
            return $this->returnType($params['isMobile'], '해당 항목은 이미 입고 완료 되었습니다.', ['params_od_id' => $od_id, 'od_status' => $checkData['od_status'], 'barcode_location_status' => $checkData['barcode_location_status']]);
        }

        /**
         * 파일 저장 - 앱 웹뷰 이슈로 미리보기 방식을 서버 저장으로 변경 (오래된 디바이스에서 웹뷰가 FileReader 에 대한 처리를 제대로 하지 못함 - 추후 expo 버전업 또는 업로드는 네이티브에서 진행할수도있음)
         * 미리보기에서 저장된 임시 파일을 저장 폴더로 옮기고 db 에도 저장 후 해당 임시폴더 삭제 - 2시간마다 크론으로도 삭제
         */
        $orderYear = date('Y', strtotime($checkData['od_time']));
        $orderMonth = date('m', strtotime($checkData['od_time']));
        $odId= $params['od_id'];
        $uploadDir = G5_PATH."/data/sub_rental/inbound/$orderYear/$orderMonth/$odId/";

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (count($params['files']) < 2) {
            return $this->returnData('error', '상품 사진은 2개 이상 등록해야 합니다.', ['params_od_id' => $od_id, 'files' => $params['files']], '0009');
        }

        foreach ($params['files'] as $file) {
            $tmpPath = G5_PATH . $file['path'];
            if (!file_exists($tmpPath)) {
                return $this->returnData('error', '임시 저장된 이미지의 유효시간은 2시간입니다. 다시 등록해주세요.', ['path' => $file['path']], '0020');
            }

            if ($file['size'] > $this->baseService->maxFileSizeBytes) {
                return $this->returnData('error', basename($file['name']).' 파일의 크기가 '.$this->baseService->maxFIleSizeMb.'MB를 초과합니다.', ['params_od_id' => $od_id,'files' => $params['files']],  '0009');
            }

            $fileName = basename($file['name']);
            $targetPath = $uploadDir . $fileName;

            if (!rename($tmpPath, $targetPath)) {
                foreach ($uploadedFilePath as $uploaded) {
                    if (file_exists($uploaded)) {
                        @unlink($uploaded);
                    }
                }
                @unlink($tmpPath);
                return $this->returnData('error', '상품 이미지 파일 등록 실패', ['file' => $fileName], '0009');
            }

            // 업로드된 파일 경로 저장
            $uploadedFilePath[] = $targetPath;
            // 삽입할 값 준비
            $insertImgData[] = [
                'isip_photo_name' => $fileName,
                'isip_photo' => $fileName,
                'isip_photo_path' => $targetPath,
                'isip_dir_name' => $odId,
                'isip_dir_year_name' => $orderYear,
                'isip_dir_month_name' => $orderMonth,
                'isip_updated_at' => $currentTime,
                'isip_updated_by' => $this->loginMb['mb_id']
            ];
        }

        $customParams['checkData'] = $checkData;
        $customParams['used'] = $used;
        $customParams['insertImgData'] = $insertImgData;
        $customParams['mb_id'] = $this->loginMb['mb_id'];

        $result = $this->processOrderUpdate(
            $od_id,
            $params,
            $customParams,
            $useTransaction,
            function ($orderStatusModel, $od_id, $params, $customParams) {
                /**
                 * @var OrderStatusModel $orderStatusModel
                 */
                // 20241028 추가:  해당 렉에 입고가 가능한 생태인지 (렉 이용개수 확인)
                if (!$rack = $this->wareHouseModel->getRackUsageInfoByRackId($params['svwr_id'])) {
                    throw new CustomException("선택한 렉에 대한 정보가 없습니다.", "0009",
                        ['params_od_id' => $od_id, 'svwr_id' => $params['svwr_id']]);
                }
                if (1 > $rack['available_rack']) {
                    throw new CustomException("선택한 렉에 입고 가능한 수량은 {$rack['available_rack']}건 입니다.", "0009",
                        ['params_od_id' => $od_id, 'svwr_id' => $params['svwr_id']]);
                }

                if ($orderStatusModel->updateInboundOrder($od_id, [
                    'order' => $customParams['checkData'],
                    'used' => $customParams['used'],
                    'insertImgData' => $customParams['insertImgData'],
                    'srw_id' => $params['srw_id'],
                    'svwr_id' => $params['svwr_id'],
                    'not_working' => $params['not_working'],
                    'missing_parts' => $params['missing_parts'],
                    'action_required' => $params['action_required'],
                    'ish_memo' => $params['ish_memo'],
                    'mb_id' => $this->loginMb['mb_id']
                ])) {
                    return true;
                } else {
                    throw new CustomException("입고처리 실패", '0009', [
                        'params_od_id' => $od_id
                    ]);
                }
            }
        );

        if ($result['status'] !== 'success') {
            // 파일 제거
            foreach ($uploadedFilePath as $filePath) {
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }
        }

        $this->orderStatusService->removeAllTempFiles($odId, 'inbound');

        return $result;
    }

    private function returnType($isMobile, string $message, array $logData): array
    {
        if (!empty($isMobile)) {
            $data = array_merge($logData, ['redirect' => '/subrental/mobile/warehouse/inbound']);
            return $this->returnData('error', $message, $data, '0012');
        } else {
            return $this->returnData('error', $message, $logData,'0009');
        }
    }
}
