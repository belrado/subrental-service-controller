<!-- // 창고 이동 팝업 // -->
<script id="popup-move-rack-template" type="text/x-handlebars-template">
    <div class="modalTitle">
        <p>창고 이동</p>
        <a class="closeBtn" href="#">
            <img src="<?=G5_IMG_URL?>/subrental/xbox_icon.svg" alt="닫기" />
        </a>
    </div>
    <div class="modalContent">
        <div class="modalInfo">
            <ul>
                <li>
                    <span>상품명</span>
                    <p>
                    {{#each selectedData}}
                        {{it_name}}{{#unless @last}}<br />{{/unless}}
                    {{/each}}
                    </p>
                </li>
                <li>
                    <span>바코드</span>
                    <p>
                    {{#each selectedData}}
                        {{sr_it_barcode_no}}{{#unless @last}}<br />{{/unless}}
                    {{/each}}
                    </p>
                </li>
            </ul>
        </div>
        <div class="modalCon">
            <div class="contents" id="sel_move_rack_sec">
                <div class="c_flex">
                    <div class="DDY_select" id="sel_move_svw_id">
                        <div class="select font_c">
                            {{#eachLimit virtualWhList 1}}
                            <button type="button" value="{{svw_id}}">{{svw_name}}</button>
                            {{/eachLimit}}
                        </div>
                        <div class="option">
                            <ul>
                                {{#each virtualWhList}}
                                <li>
                                    <button type="button" value="{{svw_id}}">{{svw_name}}</button>
                                </li>
                                {{/each}}
                            </ul>
                        </div>
                    </div>
                    <div class="DDY_select w100" id="sel_move_svwr_id">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modalBtnWrap">
        <button class="DDY_btn default high_M" id="move_rack_submit">
            이동
        </button>
    </div>
</script>
<!-- // 창고 이동 팝업 - 렉 셀렉트 박스 옵션 // -->
<?php include_once(SUB_RENTAL_PATH."/warehouse/handlebars/handlebars.select_option_rack.php") ?>
