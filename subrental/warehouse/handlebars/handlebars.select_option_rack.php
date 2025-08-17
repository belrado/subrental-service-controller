<!-- // 창고 이동 팝업 - 렉 셀렉트 박스 옵션 // -->
<script id="popup-move-rack-list-template" type="text/x-handlebars-template">
    <div class="select font_c">
        {{#if rackList}}
        {{#eachLimit rackList 1}}
        <button type="button" value="{{svwr_id}}" data-available="{{available_rack}}"> {{svwr_name}} (사용중 : {{used_rack}}, 사용가능: {{available_rack}})</button>
        {{/eachLimit}}
        {{else}}
        <button type="button" value="" disabled>생성된 렉이 없습니다.</button>
        {{/if}}
    </div>
    <div class="option">
        {{#if rackList}}
        <ul>
            {{#each rackList}}
            <li>
                <button type="button" value="{{svwr_id}}" data-available="{{available_rack}}">
                    {{svwr_name}} (사용중 : {{used_rack}}, 사용가능: {{available_rack}})
                </button>
            </li>
            {{/each}}
        </ul>
        {{/if}}
    </div>
</script>