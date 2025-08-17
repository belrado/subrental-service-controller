<?php
include_once ("./_common.php");

include_once (SUB_RENTAL_LAYOUT_PATH."/subrental.head.php");
?>
    <main>
        <div class="DDY_title">
            <div>
                <h2>출고처리</h2>
                <ul class="indicator">
                    <li>입/출고 처리</li>
                    <li>출고 처리</li>
                </ul>
            </div>
        </div>
        <div class="DDY_list">
            <form action="">
                <div class="searchBox">
                    <div class="foldBtn DDY_btn secondary high_S">
                        상세 <img src="<?=G5_IMG_URL?>/subrental/DDY_fold_btn.svg" alt="닫기" />
                    </div>
                    <ul>
                        <li>
                            <div class="searchWrap w100">
                                <span>주문일</span>
                                <div class="c_flex">
                                    <div class="tui-datepicker-input tui-datetime-input tui-has-focus">
                                        <input
                                                id="start_picker"
                                                type="text"
                                                aria-label="Date" readonly
                                                name="sdm_start_date"
                                        />
                                        <span class="tui-ico-date"></span>
                                        <div id="start_picker_container" style="margin-left: -1px"></div>
                                    </div>
                                    <span>~</span>
                                    <div class="tui-datepicker-input tui-datetime-input tui-has-focus">
                                        <input
                                                id="end_picker"
                                                type="text"
                                                aria-label="Date" readonly
                                                name="sdm_end_date"
                                        />
                                        <span class="tui-ico-date"></span>
                                        <div id="end_picker_container" style="margin-left: -1px"></div>
                                    </div>
                                    <ul class="labelTab">
                                        <li class="select-date" data-date-type="today">오늘</li>
                                        <li class="select-date" data-date-type="yesterday">어제</li>
                                        <li class="select-date" data-date-type="weekAgo">일주일</li>
                                        <li class="select-date" data-date-type="prevMonth">지난달</li>
                                        <li class="select-date" data-date-type="ninetyDaysAgo">3개월</li>
                                    </ul>
                                </div>
                            </div>
                        </li>
                        <li>
                            <div class="searchWrap w50" id="category_checkbox"></div>
                            <div class="searchWrap w50" id="order_status_checkbox"></div>
                        </li>
                        <li>
                            <div class="searchWrap w50">
                                <span>검색어</span>
                                <div class="selectBox c_flex">
                                    <div class="DDY_select" id="sel_search" style="flex: 0">
                                        <div class="select"></div>
                                    </div>
                                    <div class="DDY_input">
                                        <input type="text" id="search_text" placeholder="검색어 입력" />
                                    </div>
                                </div>
                            </div>
                            <div class="searchWrap w50"></div>
                        </li>
                    </ul>
                    <div class="searchBtn">
                        <button class="DDY_btn default online high_S" id="btn_search_reset">초기화</button>
                        <button class="DDY_btn default high_S" id="btn_search_submit">검색</button>
                    </div>
                </div>
            </form>
            <div class="tableTabTop">
                <span class="outbound-tab active" data-page-mode="install">설치</span>
                <span class="outbound-tab" data-page-mode="parcel">택배</span>
            </div>
            <div class="tableCon">
                <div class="btnWrap">
                    <button class="DDY_btn default high_S" id="btn_outbound_process">
                        출고처리
                    </button>
                    <?php if (preg_replace('/\s+/', '',REAL_WAREHOUSE_NAME) === 'CMK파주본사창고') : ?>
                    <button class="DDY_btn default high_S" id="btn_bulk_parcel_excel_upload" style="display: none">
                        택배정보 일괄 업로드
                    </button>
                    <?php endif ?>
                    <button class="DDY_btn secondary online high_S" id="btn_excel_download">
                        엑셀다운로드
                        <img src="<?=G5_IMG_URL?>/subrental/download_icon.svg" alt="다운로드아이콘" />
                    </button>
                </div>
                <div id="outbound_item_list_sec"></div>
            </div>
        </div>
    </main>

    <!-- // 촐고 처리 팝업 // -->
    <script id="popup-outbound-process-template" type="text/x-handlebars-template">
        <div class="modalTitle">
            <p>출고처리</p>
            <a class="closeBtn" href="javascript:void(0)">
                <img src="<?=G5_IMG_URL?>/subrental/xbox_icon.svg" alt="닫기" />
            </a>
        </div>
        <div class="modalContent">
            <p>선택하신 <em>{{brackets selectCnt ''}}</em>건의 주문을 출고처리 하시겠습니까?</p>
        </div>
        <div class="modalBtnWrap">
            <button class="DDY_btn secondary high_M" id="btn_outbound_cancel">
                취소하기
            </button>
            <button class="DDY_btn default high_M" id="btn_outbound_submit">
                확인하기
            </button>
        </div>
    </script>
    <!-- // 택배 정보 일괄 업로드 팝업 // -->
    <script id="popup-parcel-excel-upload-template" type="text/x-handlebars-template">
        <div class="modalTitle">
            <p>택배정보 일괄 업로드</p>
            <a class="closeBtn" href="javascript:void(0)">
                <img src="<?=G5_IMG_URL?>/subrental/xbox_icon.svg" alt="닫기" />
            </a>
        </div>
        <div class="modalContent">
            <div class="modalCon">
                <div class="contents">
                    <form id="bulk_parcel_excel_form">
                        <div class="selectBox input_file">
                            <input type="file" name="datafile" accept=".xls, .xlsx" id="bizFile" />
                            <label for="bizFile" class="btn fileBtn DDY_btn default online high_M">파일선택</label>
                            <span id="fileName">선택된 파일없음</span>
                        </div>
                        <p class="description">
                            ※ 엑셀에 택배정보를 작성해서 업로드해주세요
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <div class="modalBtnWrap">
            <button class="DDY_btn default high_M" id="btn_bulk_parcel_excel_upload_submit">
                업로드
            </button>
        </div>
    </script>
    <!-- // 택배 운송장 번호 등록 팝업 // -->
    <script id="popup-delivery-template" type="text/x-handlebars-template">
        <div class="modalTitle">
            <p>택배 운송장 번호 등록</p>
            <a class="closeBtn" href="javascript:void(0)">
                <img src="<?=G5_IMG_URL?>/subrental/xbox_icon.svg" alt="닫기" />
            </a>
        </div>
        <div class="modalContent">
            {{#info}}
            <input type="hidden" name="order_delivery_od_id" value="{{od_id}}" />
            <div class="modalInfo">
                <ul>
                    <li>
                        <span>상품명</span>
                        <p>{{it_name}}</p>
                    </li>
                    <li>
                        <span>바코드</span>
                        <p>{{sr_it_barcode_no}}</p>
                    </li>
                </ul>
            </div>
            <div class="modalCon">
                <div class="contents">
                    <div class="c_flex">
                        <div class="DDY_select" id="sel_delivery">
                            <div class="select"></div>
                        </div>
                        <div class="DDY_input w100">
                            <input type="text" name="ct_delivery_num" placeholder="송장번호 입력" value="{{ct_delivery_num}}" />
                        </div>
                    </div>
                </div>
            </div>
            {{/info}}
        </div>
        <div class="modalBtnWrap">
            <button class="DDY_btn default high_M" id="btn_add_tracking_number">
                등록
            </button>
        </div>
    </script>
    <!-- // 바코드 등록 팝업 // -->
    <script id="popup-add-barcode-template" type="text/x-handlebars-template">
        <div class="modalTitle">
            <p>바코드 입력</p>
            <a class="closeBtn" href="javascript:void(0)">
                <img src="<?=G5_IMG_URL?>/subrental/xbox_icon.svg" alt="닫기" />
            </a>
        </div>
        <div class="modalContent modal_scroll">
            <div class="modalInfo">
                <ul>
                    <li>
                        <span>상품명</span>
                        {{#orderInfo}}
                        <input type="hidden" name="add_barcode_od_id" value="{{od_id}}" />
                        <input type="hidden" name="add_barcode_no" value="" />
                        <p>
                            <em class="DDY_badge squre_line DDY_badge_Red barcode-status-new" style="display: none">새상품</em>
                            <span>{{it_name}}</span>
                        </p>
                        {{/orderInfo}}
                    </li>
                </ul>
            </div>
            <div class="modalCon">
                <div class="contents">
                    <ul class="barcode_modal_list">
                        {{#each barcode}}
                        <li class="barcode-row">
                            <p>{{sr_it_barcode_no}}</p>
                            <ul class="DDY_chips chips_green size_S">
                                <li>
                                    <a href="#" class="btn_add_barcode_select" data-barcode-status="{{sr_sto_status}}" data-barcode-no="{{sr_it_barcode_no}}">
                                        <img src="<?=G5_IMG_URL?>/subrental/label_icon.svg" alt="라벨 체크아이콘" />바코드 선택
                                    </a>
                                </li>
                            </ul>
                        </li>
                        {{/each}}
                    </ul>
                    <p class="description">
                        ※ 소독이 완료된 바코드 or 새제품만 조회됩니다.
                    </p>
                </div>
            </div>
        </div>
        <div class="modalBtnWrap">
            <button class="DDY_btn default high_M" id="btn_add_barcode_submit">
                저장
            </button>
        </div>
    </script>
    <!-- // 바코드 창고 이동 팝업 // -->
    <?php include_once (SUB_RENTAL_PATH."/warehouse/handlebars/handlebars.move_rack_popup.php"); ?>
    <!-- // 엑셀 다운 로드 팝업 // -->
    <?php include_once (SUB_RENTAL_PATH."/warehouse/handlebars/handlebars.excel_download_popup.php"); ?>
    <script>
      const jsClass = new <?=$JSClass?>("<?=REAL_WAREHOUSE_ID?>", "<?=$member['mb_no']?>");
      var data_list_row_idx = 1;
      var page = 1;
      $(function() {
        jsClass.init();
        Handlebars.registerHelper('eachLimit', jsClass.handlebarsFncEachLimit);
        Handlebars.registerHelper('brackets', jsClass.handlebarsFncBrackets);
      });
    </script>
<?php
include_once(SUB_RENTAL_LAYOUT_PATH."/subrental.tail.php");
