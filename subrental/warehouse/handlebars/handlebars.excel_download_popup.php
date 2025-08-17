<script id="popup-excel-download-template" type="text/x-handlebars-template">
    <div class="modalTitle">
        <p>엑셀 다운로드</p>
        <a class="closeBtn" href="#">
            <img src="<?=G5_IMG_URL?>/subrental/xbox_icon.svg" alt="닫기" />
        </a>
    </div>
    <div class="modalContent">
        <p>
            {{{message}}}
        </p>
    </div>
    <div class="modalBtnWrap">
        <button class="DDY_btn secondary high_M" id="btn_excel_download_popup_close">
            취소
        </button>
        <button class="DDY_btn default high_M" id="btn_excel_download_submit">
            확인
        </button>
    </div>
</script>
<script>
$(document).off('click', '#btn_excel_download_popup_close').on('click', '#btn_excel_download_popup_close', function(e) {
  e.preventDefault();
  const modalObj = $(this).closest('.DDY_modal.active');
  modalObj.removeClass("active");
  modalObj.off().on({
    transitionend: function(e) {
      if (e.originalEvent.propertyName === 'opacity' && !$(this).hasClass('active')) {
        modalObj.remove();
      }
    }
  });
});
</script>