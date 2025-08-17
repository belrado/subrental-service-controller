<script id="popup-inbound-completed-info-template" type="text/x-handlebars-template">
    <div class="modalTitle">
        <p>입고처리 내역</p>
        <a class="closeBtn" href="javascript:void(0)">
            <img src="<?=G5_IMG_URL?>/subrental/xbox_icon.svg" alt="닫기" />
        </a>
    </div>
    <div class="modalContent modal_scroll">
        {{#info}}
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
                <li>
                    <span>입고담당자</span>
                    <p>{{ish_created_by}}</p>
                </li>
                <li>
                    <span>입고처리일</span>
                    <p>{{warehouse_put_dt}}</p>
                </li>
            </ul>
        </div>
        <div class="modalCon">
            <h4>하자체크</h4>
            <div class="contents">
                <ul class="modal_toggle change_text">
                    <li class="w50">
                        <p>작동 여부</p>
                        <p>{{barcode_status}}</p>
                    </li>
                    <li class="w50">
                        <p>부품 누락</p>
                        <p>{{missing_parts}}</p>
                    </li>
                    <li class="w100">
                        <p>필요 조치</p>
                        <p>{{action_required}}</p>
                    </li>
                </ul>
            </div>
        </div>
        <div class="modalCon">
            <h4>창고위치</h4>
            <div class="contents">
                <ul class="modal_toggle">
                    <li class="w50">
                        <p>창고</p>
                        <p>{{virtual_name}}</p>
                    </li>
                    <li class="w50">
                        <p>렉</p>
                        <p>{{rack_name}}</p>
                    </li>
                </ul>
            </div>
        </div>
        <div class="modalCon">
            <h4>상품 이미지</h4>
            <div class="contents">
                <div class="preview_g">
                    <div id="preview_list" class="view">
                        {{#each ../photo}}
                        <div class="imgWrap">
                            <img src="<?=G5_URL?>/data/sub_rental/inbound/{{isip_dir_year_name}}/{{isip_dir_month_name}}/{{isip_dir_name}}/{{isip_photo}}" alt="{{isip_photo_name}}" />
                        </div>
                        {{/each}}
                    </div>
                </div>
            </div>
        </div>
        <div class="modalCon">
            <h4>메모</h4>
            <div class="contents">
                <p>{{{ish_memo}}}</p>
            </div>
        </div>
        {{/info}}
    </div>
    <div class="modalBtnWrap">
        <button class="DDY_btn default high_M" id="btn_inbound_completed_modal_close">
            확인
        </button>
    </div>
</script>