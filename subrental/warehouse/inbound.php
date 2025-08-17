<?php
include_once ("./_common.php");

include_once (SUB_RENTAL_LAYOUT_PATH."/subrental.head.php");
?>
    <main>
        <!-- s -->
        <div class="DDY_title">
            <div>
                <h2 id="test_title">입고처리</h2>
                <ul class="indicator">
                    <li>입/출고 처리</li>
                    <li>입고 처리</li>
                </ul>
            </div>
        </div>
        <div class="DDY_list">
            <div class="searchBox">
                <div class="foldBtn DDY_btn secondary high_S">
                    상세 <img src="<?=G5_IMG_URL?>/subrental/DDY_fold_btn.svg" alt="닫기" />
                </div>
                <ul>
                    <li>
                        <div class="searchWrap w50" id="category_checkbox"></div>
                        <div class="searchWrap w50" id="barcode_cancel_checkbox"></div>
                    </li>
                    <li>
                        <div class="searchWrap w50" id="inbound_route_checkbox"></div>
                        <div class="searchWrap w50" id="inbound_reason_checkbox"></div>
                    </li>
                    <li>
                        <div class="searchWrap w50">
                            <span>검색어</span>
                            <div class="selectBox c_flex">
                                <div class="DDY_select" style="flex: 0" id="sel_search">
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
            <div class="tableTabTop">
                <span class="inbound-tab active" data-page-mode="pending">입고대기</span>
                <span class="inbound-tab" data-page-mode="completed">입고완료</span>
            </div>
            <div class="tableCon">
                <div class="btnWrap">
                    <button class="DDY_btn default high_S" id="btn_inbound_process">
                        입고처리
                    </button>
                    <button class="DDY_btn secondary online high_S" id="btn_excel_download">
                        엑셀다운로드
                        <img src="<?=G5_IMG_URL?>/subrental/download_icon.svg" id="btn_excel_download" alt="다운로드아이콘" />
                    </button>
                </div>
                <div id="inbound_item_list_sec"></div>
            </div>
        </div>
        <!-- e -->
    </main>
    <!-- // 입고 처리 내역 // -->
    <?php include_once (SUB_RENTAL_PATH."/warehouse/handlebars/handlebars.inbound_completed_info_popup.php"); ?>
    <!-- // 입고 처리 // -->
    <script id="popup-inbound-process-template" type="text/x-handlebars-template">
        <div class="modalTitle">
            <p>입고 처리</p>
            <a class="closeBtn" href="javascript:void(0)">
                <img src="<?=G5_IMG_URL?>/subrental/xbox_icon.svg" alt="닫기" />
            </a>
        </div>
        <div class="modalContent modal_scroll">
            <form id="inbound_process_form">
                {{#dataInfo}}
                <input type="hidden" name="inbound_process_od_id" value="{{od_id}}" />
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
                {{/dataInfo}}
                <div class="modalCon">
                    <h4>하자 체크</h4>
                    <div class="contents">
                        <ul class="modal_toggle">
                            <li class="w50">
                                <p>작동 여부</p>
                                <div class="toggleWrap" id="inbound_damaged_yn">
                                    <div class="tableTabTopcustom">
                                        <span class="status on active" data-status="normal">정상</span>
                                        <span class="status" data-status="error">비정상</span>
                                        <div class="bar"></div>
                                    </div>
                                </div>
                            </li>
                            <li class="w50">
                                <p>부품 누락</p>
                                <div class="toggleWrap" id="inbound_missing_parts">
                                    <div class="tableTabTopcustom">
                                        <span class="status" data-status="error">있음</span>
                                        <span class="status on active" data-status="normal">없음</span>
                                        <div class="bar"></div>
                                    </div>
                                </div>
                            </li>
                            <li class="w100" id="inbound_action_required_sec" style="display: none">
                                <p>필요 조치</p>
                                <div class="toggleWrap" id="inbound_action_required">
                                    <div class="tableTabTopcustom">
                                        <span class="status on active" data-status="repair">A/S 필요</span>
                                        <span class="status" data-status="other">기타</span>
                                        <span class="status" data-status="to_be_disposed">폐기 예정</span>
                                        <div class="bar"></div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="modalCon">
                    <h4>창고 위치</h4>
                    <div class="contents">
                        <div class="c_flex">
                            <div class="DDY_select w50" id="sel_inbound_svw_id">
                                <div class="select"></div>
                            </div>
                            <div class="DDY_select w50" id="sel_inbound_svwr_id">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modalCon">
                    <h4>상품 이미지</h4>
                    <div class="contents">
                        <div class="preview_g">
                            <div id="previewWrap">
                                <label for="fileimage"></label>
                                <input type="file" id="fileimage" accept="image/*" multiple="multiple" />
                            </div>
                            <div id="preview_list"></div>
                        </div>
                        <p class="description">
                            ※ 최소 2건 이상 등록 필수 (최대 10건) <br />※ 용량: 1건 당
                            최대 20MB, 등록 가능 확장자: jpg, jpeg, png
                        </p>
                    </div>
                </div>
                <div class="modalCon">
                    <h4>메모</h4>
                    <div class="contents">
                        <div class="textarea_wrap">
                            <div class="textLengthWrap">
                                <p class="textCount" id="memo_count">0</p>
                                <p class="textTotal">/200자</p>
                            </div>
                            <textarea class="textarea" id="inbound_memo" placeholder="하자 체크 - 작동여부 비정상 or 부품누락 있음 선택 상태에서는 메모 입력이 필수입니다."></textarea>
                            <p class="errorText">
                                <img src="<?=G5_IMG_URL?>/subrental/error_icon.svg" alt="오류메세지" />메모를 입력해 주세요.
                            </p>
                        </div>
                    </div>
                </div>
            </form>

        </div>
        <div class="modalBtnWrap">
            <button class="DDY_btn default high_M" id="btn_inbound_process_submit">
                저장
            </button>
        </div>
    </script>
    <!-- // 창고 이동 팝업 - 렉 셀렉트 박스 옵션 // -->
    <?php include_once(SUB_RENTAL_PATH."/warehouse/handlebars/handlebars.select_option_rack.php") ?>
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
