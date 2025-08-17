<?php

require_once(G5_PATH . '/subrental_api/services/BaseService.php');
require_once(G5_PATH . '/subrental_api/services/OrderStatusModel.php');

/**
 *
 * 대대여 주문 / 회수 상태 변경 & 비즈니스로직 처리하는 서비스 클래스
 * (재고와 주문의 상태값의 요구조건이 많아지고 관리를 여러군데서 하다보니 상태값 누락이 많아져 상태변경에 대해서는 한군데에서 관리할 필요가 생김)
 *
 * 대대여 주문 / 회수 상태
 * -https://thkco.atlassian.net/wiki/spaces/PlatformDev/pages/179666981
 *
 */
class OrderStatusService extends BaseService
{
    protected $orderStatusModel;
    protected $loginMb;

    public function __construct()
    {
        parent::__construct();
        global $member;
        $this->orderStatusModel = new OrderStatusModel($this->mysql, $this);
        $this->loginMb = $member;
    }

    /**
     * @param string $type
     * @param string $callType
     * @param $od_id
     * @param array $params
     * @param bool $useTransaction
     * @return array [status ('success', 'error'), message (string), result (array), code (string)]
     */
    public function updateStatus(string $type, string $callType, $od_id = null, array $params = [], bool $useTransaction = true): array
    {
        $this->orderLogDir = "subrental/api/orderStatusService/".$type;
        $this->orderStatusModel->logDir = $this->orderLogDir;
        try {
            $handler = loadHandler("orderStatus", $type, $callType, $this->orderStatusModel, $this, $this);
            return $handler->handle($od_id, $params, $useTransaction);
        } catch (Exception $e) {
            return $this->returnData("error", $e->getMessage(), [], '9999');
        }
    }

    /**
     * 미리보기용 임시파일 가져오기
     * @param int $od_id
     * @param string $type
     * @param string $baseDir
     * @return array
     */
    public function getTempFiles(int $od_id, string $type, string $baseDir = '/data/sub_rental/temp'): array
    {
        $mb_no = (string)$this->loginMb['mb_no'];
        $result = [];

        if (!is_numeric($od_id) || empty($type)) {
            return $result;
        }

        $folders = glob(G5_PATH . $baseDir . '/*', GLOB_ONLYDIR);
        if (!$folders) return $result;

        rsort($folders);
        $latestFolder = $folders[0] ?? null;
        if (!$latestFolder) return $result;

        $targetDir = $latestFolder . '/' . $type . '/' . $od_id;
        if (!is_dir($targetDir)) return $result;

        $files = glob($targetDir . '/' . $mb_no . '_*');

        foreach ($files as $filePath) {
            if (!is_file($filePath)) continue;

            $basename = basename($filePath);
            $ext = pathinfo($basename, PATHINFO_EXTENSION);

            $result[] = [
                'filePath'  => str_replace(G5_PATH, '', $filePath),
                'fileUuid'  => $basename,
                'fileName'  => preg_replace('/^' . preg_quote($mb_no . '_', '/') . '/', '', $basename), // 원본 이름
                'fileSize'  => filesize($filePath),
                'fileExt'   => $ext,
            ];
        }

        usort($result, function ($a, $b) {
            return $b['modified'] - $a['modified'];
        });

        $latestTen = array_slice($result, 0, 10);

        usort($latestTen, function ($a, $b) {
            return $a['modified'] - $b['modified']; // 오래된 것부터 정렬
        });

        return $latestTen;
    }

    /**
     * 미리보기용 임시파일 저장 크론으로 2시간마다 돌면서 2시간 전 폴더는 삭제한다.
     * @param int $od_id
     * @param array $files`
     * @param string $type
     * @return array
     */
    public function updateTempFilesFormData(int $od_id, array $files, string $type): array
    {
        if (!is_numeric($od_id)) {
            return $this->returnData("error", '주문 코드가 없습니다.', [], '0009');
        }

        if (empty($files['tmp_name']) || empty($type)) {
            return $this->returnData("error", '업로드할 파일 또는 경로가 없습니다.', [], '0009');
        }

        // 업로드시 실행시간 & 메모리 임시 증가
        ini_set('max_execution_time', 180);
        ini_set('max_input_time', 180);
        ini_set('memory_limit', '512M');

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        // 시간단위 1, 2, 3 ...
        $now = time();
        $interval = 2;
        $hour = (int)date('H', $now);
        $roundedHour = floor($hour / $interval) * $interval;
        $tempDate = date('Ymd', $now) . str_pad($roundedHour, 2, '0', STR_PAD_LEFT);


        /*
        // 분단위 10, 20, 30 ... (테스트용)
        $now = time();
        $interval = 10;
        $hour = (int)date('H', $now);
        $minute = (int)date('i', $now);
        $totalMinutes = $hour * 60 + $minute;
        $roundedMinutes = floor($totalMinutes / $interval) * $interval;
        $roundedHour = floor($roundedMinutes / 60);
        $roundedMinute = $roundedMinutes % 60;
        $tempDate = date('Ymd', $now) . str_pad($roundedHour, 2, '0', STR_PAD_LEFT) . str_pad($roundedMinute, 2, '0', STR_PAD_LEFT);
        */

        $uploadDir = "/data/sub_rental/temp/{$tempDate}/{$type}/{$od_id}";
        $fullUploadDir = G5_PATH . $uploadDir;

        if (!is_dir($fullUploadDir)) {
            mkdir($fullUploadDir, 0755, true);
        }

        $mb_no = (string) $this->loginMb['mb_no'];
        $uploadedFiles = [];
        $errorMessage = '';

        foreach ($files['tmp_name'] as $i => $tmpPath) {
            $originalName = basename($files['name'][$i]);
            $mimeType = $files['type'][$i];
            $fileSize = $files['size'][$i];
            $errorCode = $files['error'][$i];

            if ($errorCode !== UPLOAD_ERR_OK) {
                $errorMessage = "$originalName 업로드 중 오류 발생";
                break;
            }

            if ($fileSize > $this->maxFileSizeBytes) {
                $errorMessage = "$originalName 파일 크기가 {$this->maxFIleSizeMb}MB를 초과합니다.";
                break;
            }

            if (!in_array($mimeType, $allowedMimeTypes)) {
                $errorMessage = "$originalName 파일 형식이 허용되지 않습니다.";
                break;
            }

            $ext = pathinfo($originalName, PATHINFO_EXTENSION);
            $newFileName = $mb_no . '_' . $originalName;
            $fullFilePath = $fullUploadDir . '/' . $newFileName;
            $filePath = $uploadDir . '/' . $newFileName;

            // 이미 존재하면 skip
            if (file_exists($fullFilePath)) {
                $uploadedFiles[] = [
                    'filePath' => $filePath,
                    'fileUuid' => $newFileName,
                    'fileName' => $originalName,
                    'fileSize' => $fileSize,
                    'fileExt'  => $ext,
                ];
                continue;
            }

            if (!move_uploaded_file($tmpPath, $fullFilePath)) {
                $errorMessage = "$originalName 파일 저장 실패";
                break;
            }

            $uploadedFiles[] = [
                'filePath' => $filePath,
                'fileUuid' => $newFileName,
                'fileName' => $originalName,
                'fileSize' => $fileSize,
                'fileExt'  => $ext,
            ];
        }

        if ($errorMessage !== '') {
            foreach ($uploadedFiles as $file) {
                $fullPath = G5_PATH . $file['filePath'];
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
            return $this->returnData("error", $errorMessage, [], '0009');
        }

        $userFiles = glob($fullUploadDir . '/' . $mb_no . '_*');
        if (count($userFiles) > 10) {
            usort($userFiles, function ($a, $b) {
                return filemtime($a) - filemtime($b); // 오래된 순
            });
            $toDelete = array_slice($userFiles, 0, count($userFiles) - 10);
            foreach ($toDelete as $oldFile) {
                @unlink($oldFile);
            }
        }

        return $this->returnData("success", '', $uploadedFiles);
    }

    /**
     * 미리보기용 임시파일 한건씩 삭제
     * @param int $od_id
     * @param string $filePath
     * @return array
     */
    public function removeTempFile(int $od_id, string $filePath): array
    {
        if (!is_numeric($od_id)) {
            return $this->returnData("error", '주문 코드가 없습니다.', [], '0009');
        }

        if (empty($filePath)) {
            return $this->returnData("error", '파일이 없습니다.', [], '0009');
        }

        $fullPath = G5_PATH . $filePath;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }

        return $this->returnData();
    }

    /**
     * 미리보기용 임시파일 폴더까지 전부 삭제 - 저장시 미리보기가 더이상 필요없어짐
     * @param int $od_id
     * @param string $type
     * @param string $baseDir
     * @return void
     */
    public function removeAllTempFiles(int $od_id, string $type, string $baseDir = '/data/sub_rental/temp')
    {
        $basePath = G5_PATH . $baseDir;
        if (!is_dir($basePath)) return;

        $timeDirs = glob($basePath . '/*', GLOB_ONLYDIR);
        if (!$timeDirs) return;

        foreach ($timeDirs as $timeDir) {
            $targetDir = $timeDir . '/' . $type . '/' . $od_id;
            if (is_dir($targetDir)) {
                $files = glob($targetDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($targetDir);
            }
        }
    }
}
