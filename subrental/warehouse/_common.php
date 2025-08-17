<?php
include_once ("../_common.php");
include_once (G5_PATH."/subrental_api/modules/WareHouseModel.php");
if (is_mobile()) {
    goto_url(G5_URL."/subrental/mobile/warehouse/inbound");
}
/**
 * 입출고 관리
 * 권한 체크 mb_type = subrental & mb_level = 14 or super admin 만 접근가능
 */
$srMemberInfo = subRentalAuthCheck($member, '14');

/**
 * 관리자 > 창고 담당자 관리 > 창고 담당자 등록시 선택한 소속창고(리얼창고)는 mb_subrental_type 컬럼에 소속 창고 이름으로 저장됨
 */
$realName =  htmlspecialchars(trim($member['mb_subrental_type']));
if ($is_admin === 'super') {
    $realName = empty($realName) ? 'CMK파주본사창고' : $realName;
}

$whereModel = new WareHouseModel();
if (!$reaWh = $whereModel->getRealWareHouseByName($realName)) {
    $subRentalLogger->error([
        'page' => 'warehouse/_common.php',
        'message' => '생성된 창고가 없거나 접근 권한이 없습니다. 관리자에게 문의해 주세요.',
        'action' => '로그아웃처리',
        'error' => [
            'member' => $member,
            'realName' => $realName
        ]], 'subrental/client/warehouse');
    alert("생성된 창고가 없거나 접근 권한이 없습니다. 관리자에게 문의해 주세요.",G5_URL."/bbs/logout.php");
}

$g5['title'] = '이로움 창고담당자';

define('REAL_WAREHOUSE_ID', $reaWh['srw_id']);
define('REAL_WAREHOUSE_NAME', $reaWh['srw_name']);

add_javascript('<script src="'.G5_JS_URL.'/handlebars-v4.7.8.js?v='.SUB_RENTAL_CACHE.'"></script>', 0);
add_javascript('<script src="'.G5_JS_URL.'/jquery.fileDownload.js?v='.SUB_RENTAL_CACHE.'"></script>', 0);
add_javascript('<script src="'.G5_JS_URL.'/subrental/client/warehouse/warehouse_base.js?v='.SUB_RENTAL_CACHE.'"></script>', 0);
add_javascript('<script src="'.G5_JS_URL.'/subrental/client/warehouse/'.$JSClass.'.js?v='.time().'"></script>', 0);

