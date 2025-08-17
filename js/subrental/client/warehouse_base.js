class warehouse_base extends subrental_base {
  constructor(srw_id, mb_no) {
    super();
    this._srw_id = parseInt(srw_id);
    this._mb_no = parseInt(mb_no);

    this._virtualAddLimit = 7;
    this._rackKeepLimit = 6;
    this._rackLimit = 50;
    this._disinfectionAddLimit = 5;
    this._disinfectionLimit = 50;

    this.inOutSearchKey = [
      {text: '바코드', value: 'sism.sr_it_barcode_no'},
      {text: '상품명', value: 'it_name'},
      {text: '사업소명', value: 'company'},
    ];
  }

  // 소속 리얼창고 아이디 수정불가
  get srw_id () {
    return this._srw_id;
  }

  // 회원 유니크 키 수정불가
  get mb_no () {
    return this._mb_no;
  }

  get virtualAddLimit () {
    return this._virtualAddLimit;
  }
  get rackKeepLimit () {
    return this._rackKeepLimit;
  }

  get rackLimit () {
    return this._rackLimit;
  }

  get disinfectionAddLimit () {
    return this._disinfectionAddLimit;
  }

  get disinfectionLimit () {
    return this._disinfectionLimit;
  }

  /**
   * subrental_base::alertFormError override
   * @param message
   * @param elem
   * @returns {boolean}
   */
  alertFormError(message, elem = null) {
    this.clientAlertFnc(this, message, '0009');
    if (elem) {
      elem.focus();
    }
    return false;
  }

  /**
   * api response errorCode 처리 & 프론트 처리
   * @param ownerClass
   * @param error
   * @param code
   * @param goToUrl
   * @param mode
   */
  /*clientAlertFnc(ownerClass, error, code, goToUrl = false, mode = 'error') {
    switch (code) {
      case '9999':
        // 로그아웃
        alert(error);
        location.href = g5_url + '/bbs/logout.php';
        break;
      case '0011':
        // 페이지 새로고침
        alert(error);
        location.reload(true);
        break;
      case '0012':
        // 페이지 이동
        if (goToUrl) {
          location.href = goToUrl;
        } else {
          new SubRentalToast({ message: error, mode });
        }
        break;
      case '0010':
        new SubRentalToast({ message: error, mode });
        // 리스트 ajax 새로 고침
        if (typeof ownerClass.bindDataPageList === 'function') {
          ownerClass.bindDataPageList();
        }
        break;
      default:
        new SubRentalToast({ message: error, mode });
    }
  }*/

  /**
   * 리스트 선택시 아이디 저장
   * @param target
   * @returns {*[]}
   */
  getCheckedItems(target) {
    const itemsArr = [];
    $(target).find('input[name="auto_checkbox[]"]').each(function() {
      const elem =$(this);
      if(elem.is(":checked") && !elem.is(":disabled")){
        itemsArr.push(elem.val());
      }
    });
    return itemsArr;
  }

  /**
   * sec 초 가 지나야 다운로드 가능
   * @param owner
   * @param sec
   * @returns {boolean}
   */
  checkDownloadTime(owner, sec = 10) {
    const currentTime = Date.now();
    if (owner.downloadTime && currentTime - owner.downloadTime < (sec * 1000)) {
      owner.clientAlertFnc(owner, `${sec}초 후 다운로드 가능합니다.`, '0009');
      return false;
    }
    owner.downloadTime = currentTime;
    return true;
  }

  /**
   * 핸들바스 커스텀핼퍼
   * @param array
   * @param limit
   * @param options
   * @returns {*|string}
   */
  handlebarsFncEachLimit (array, limit, options) {
    if (!array || array.length === 0) {
      return options.inverse(this);
    }

    let result = '';
    for (let i = 0; i < limit && i < array.length; i++) {
      result += options.fn(array[i]);
    }
    return result;
  }

  /**
   * 핸들바스 커스텀 핼퍼
   * @param variable
   * @param char
   * @returns {string}
   */
  handlebarsFncBrackets (variable, char = '') {
    return "{" + char + variable + "}";
  }

  validateName (str) {
    const regex = RegExp(/^(?=.*[가-힣a-zA-Z])[가-힣a-zA-Z0-9\s]{2,10}$/);
    return regex.test(str);
  }
  validateSameNames (arr = []) {
    return arr.reduce(function(acc, curr, idx) {
      if (arr.indexOf(curr) !== idx && acc.indexOf(curr) === -1) {
        acc.push(curr);
      }
      return acc
    }, []);
  }

  selectHtml(arr) {
    return arr.map((i) => `<li><button value="${i}">${i}</button></li>`).join('')
  }

  //[{category: 10, name: '침대'}, {category: 11, name: '침대2'}]
  addUiCheckboxSection({target, id, itemList, txtName, valName, allCheck = true, allCheckText = '전체', className = 'searchWrap', title = ''}) {
    const owner = this;
    $(target).empty();
    const selectSectionHtml = `<div class="${className}" id="${id}">
        ${title !== '' ? `<span>${title}</span>` : ''}
        ${allCheck ? owner.getUiCheckboxHtml(id + '_all_check', true, 'all', allCheckText) : ''}
        ${itemList.map((item, idx) => owner.getUiCheckboxHtml(id + '_' + (idx+1),false, item[valName], item[txtName])).join('')}
    </div>`;
    $(target).append(selectSectionHtml);

    if (allCheck) {
      owner.bindUiCheckboxAllCheck(target);
    }
  };
  addUiCheckbox({target, id, itemList, name, txtName = "text", valName = "value", allCheck = true, allChecked = true, allCheckText = '전체', title = ''}) {
    const owner = this;
    $(target).empty();
    if (!id) {
      id = $(target).attr('id') ?? owner.getMicroTime();
    }
    const selectHtml = `
        ${title ? `<span>${title}</span>` : ''}
        ${allCheck ? owner.getUiCheckboxHtml(id + '_all_check', true, allChecked, 'all', allCheckText, name) : ''}
        ${itemList.map((item, idx) => owner.getUiCheckboxHtml(id + '_' + (idx+1), false, item?.checked, item[valName], item[txtName], name)).join('')}`;
    $(target).append(selectHtml);
    if (allCheck) {
      owner.bindUiCheckboxAllCheck(target);
    }
  };
  getUiCheckboxHtml(id, allCheck, checked, value, text, name) {
    return `<div class="DDY_checkBox ${allCheck ? 'checkAll' : ''}">
                <input type="checkbox" value="${value}" id="${id}" ${checked ? 'checked' : ''} ${name ? `name="${name}"` : ''} />
                <label for="${id}">${text}</label>
            </div>`;
  }
  bindUiCheckboxAllCheck(target) {
    $(target).find('.DDY_checkBox.checkAll').off().on("click", function(e) {
      const isChecked = $(this).find("input").is(":checked");
      $(this)
        .closest(".searchWrap")
        .find(".DDY_checkBox:not(.checkAll) input")
        .prop("checked", isChecked);
    });
    $(target).find(".DDY_checkBox:not(.checkAll) input").click(function () {
      const container = $(this).closest(".searchWrap");
      const allChecked =
        container.find(".DDY_checkBox:not(.checkAll) input").length ===
        container.find(".DDY_checkBox:not(.checkAll) input:checked").length;
        container.find(".DDY_checkBox.checkAll input").prop("checked", allChecked);
    });
  }

  drawCategoryCheckbox(categoryData, name) {
    let itemList = [];
    try {
       itemList = categoryData.map(v => ({text: v.ca_name, value: v.ca_id}));
    } catch (e) {}
    this.addUiCheckbox({
      target: $('#category_checkbox'),
      title: '상품 유형',
      itemList: itemList ?? [],
      txtName: 'text',
      valName: 'value',
      name
    });
  }

  checkboxReset(sectionId, allChecked = true) {
    $(`#${sectionId} input[type="checkbox"]`).prop('checked', false);
    if (allChecked) {
      $(`#${sectionId} .checkAll input[type="checkbox"]`).prop('checked', true);
    }
  }

  /**
   * 재고 관리
   * 창고 이동 모달 띄우기 전 api 에서 데이터 확인 및 가상 창고 & 렉 정보 불러옴
   * @param owner
   * @param sendData
   */
  getVirtualAndCheckMoveRackStockData(owner, sendData) {
    owner.callAPI(owner, 'WareHouseStock', 'getAllVirtualAndRackAndCheckData', sendData, (ownerClass, response) => {
      const res = response?.result_data;
      if(res?.virtual.length > 0 && res?.rack && Object.keys(res.rack).length > 0 && res?.selectedData.length >0) {
        owner.drawMoveRackPopup(owner, res);
      } else {
        owner.clientAlertFnc(owner, '사용할수있는 가상창고나 랙이 없습니다.', '0009');
      }
    }, (ownerClass, error, code) => ownerClass.clientAlertFnc(ownerClass, error, code));
  }

  /**
   * 소독실
   * 창고 이동 모달 띄우기 전 api 에서 데이터 확인 및 가상 창고 & 렉 정보 불러옴
   * @param owner
   * @param sendData
   */
  getVirtualAndCheckMoveRackDisinfectionData(owner, sendData) {
    owner.callAPI(owner, 'WareHouseDisinfection', 'getAllVirtualWareHouseAndRack', sendData, (ownerClass, response) => {
      const res = response?.result_data;
      if(res?.virtual.length > 0 && res?.rack && Object.keys(res.rack).length > 0 && res?.selectedData.length >0) {
        owner.drawMoveRackPopup(owner, res);
      } else {
        owner.clientAlertFnc(owner, '사용할수있는 가상창고나 랙이 없습니다.', '0009');
      }
    }, (ownerClass, error, errorCode) => owner.clientAlertFnc(ownerClass, error, errorCode));
  }

  /**
   * 창고 이동 핸들바스 모달 띄우기
   * @param owner
   * @param response (팝업 띄우기 전 api 에서 데이터 확인 및 창고이동할 데이터와 가상창고 렉 정보 가져옴)
   */
  drawMoveRackPopup(owner, response) {
    owner.renderClientUiModalWithHandleBars({
      modalWrapId: 'move_rack_modal',
      modalClass: 'modal_lg',
      handlebarsData: {swrName: response.virtual[0].srw_name, virtualWhList: response.virtual, selectedData: response.selectedData},
      handlebarsId: 'popup-move-rack-template',
    });
    owner.drawMoveRackSelectOption(response.rack, response.virtual[0].svw_id);
    owner.bindUiSelectEvent('sel_move_svw_id');
    owner.bindUiSelectEvent('sel_move_svwr_id', (select, option) => {
      const available = option.data('available');
      select.data('available', available);
    });
    $("#sel_move_svw_id .option button").off().on("click", function(e) {
      e.preventDefault();
      const _this = $(this);
      const svw_id = _this.val();
      const selRackListObj = $('#sel_move_svwr_id');
      owner.drawMoveRackSelectOptionAjax(owner, svw_id, 'WareHouseStock', 'getRackDataByVirtual', selRackListObj, () => {
        selRackListObj.empty();
      });
    });
  }

  /**
   * 재고 관리
   * 창고 이동 모달 - 가상 창고 셀렉트 박스 세팅
   * @param rack
   * @param virtualId
   */
  drawMoveRackSelectOption(rack, virtualId) {
    const handlebarsHtml = this.getHandlebarsTemplate('popup-move-rack-list-template', {rackList: rack[virtualId]});
    $('#move_rack_modal').find('#sel_move_svwr_id').append(handlebarsHtml);
  }

  /**
   * 창고 이동 모달 - 가상 창고 변경시 소속된 렉 가져 오기
   * @param owner
   * @param virtualId
   * @param apiClassName
   * @param apiMethodName
   * @param target
   * @param callback
   */
  drawMoveRackSelectOptionAjax(owner, virtualId, apiClassName, apiMethodName, target, callback) {
    const sendData = {
      token: $('input[name="token"]').val(),
      srw_id: owner.srw_id,
      svw_id: parseInt(virtualId),
    }
    owner.callAPI(owner, apiClassName, apiMethodName, sendData, (owner, response) => {
      const handlebarsHtml = this.getHandlebarsTemplate('popup-move-rack-list-template', {rackList: response.result_data});
      if (typeof callback === 'function') {
        callback();
      }
      $(target).empty();
      $(target).append(handlebarsHtml);
    }, (owner, error) => {
      new SubRentalToast({ message: error });
      owner.removeModalUiWrapperHtml('move_rack_modal');
    });
  }

  /**
   * 엑셀다운로드 리스트가 dom에서 remove되고 새로 생성된다면 다시 바인딩 걸어줘야함
   * @param selectedListTarget
   * @param dataList
   * @param module
   * @param action
   * @param moreSendData
   * @param searchSendData
   * @param modalMessage
   * @returns {boolean}
   */
  bindExcelDownload({ownerClass, selectedListTarget, dataList, module, action, moreSendData = {}, modalMessage}) {
    const owner = this;
    try {
      if (!selectedListTarget || !dataList || !module || !action) {
        console.log('bindExcelDownload setting error');
        return false;
      }
      $('#btn_excel_download').off().on({
        click: function(e) {
          e.preventDefault();
          if (selectedListTarget.find('tbody tr').length > 0) {
            const selectedList = owner.getCheckedItems(selectedListTarget).map(v => v);

            if (selectedList.length > 0) {
              if(!owner.checkDownloadTime(owner)) {
                return false;
              }
              owner.wareHouseExcelDownload(selectedList, module, action, moreSendData);
            } else {
              owner.renderClientUiModalWithHandleBars({
                modalWrapId: 'excel_download_modal',
                modalClass: 'modal_md',
                handlebarsData: {message: modalMessage},
                handlebarsId: 'popup-excel-download-template',
              });
            }
          } else {
            return owner.alertFormError('데이터가 존재하지 않습니다.');
          }
        }
      });
      // 전체 다운 로드
      $(document).off('click', '#btn_excel_download_submit').on('click', '#btn_excel_download_submit', function(e) {
        if(!owner.checkDownloadTime(owner)) {
          return false;
        }
        e.preventDefault();
        const uniqueIds = [];
        if (ownerClass) {
          const sendData = {
            ...moreSendData,
            ...ownerClass.searchStatus
          };
          owner.wareHouseExcelDownload(uniqueIds, module, action, sendData);
        } else {
          owner.wareHouseExcelDownload(uniqueIds, module, action, moreSendData);
        }
      });
    } catch(e) {
      console.log('bindExcelDownload error', e);
    }
  }

  decodeUnicode(str) {
    return decodeURIComponent(JSON.parse('"' + str.replace(/"/g, '\\"') + '"'));
  }

  wareHouseExcelDownload(unique_ids, module, action, moreSendData = {}) {
    const owner = this;
    owner.waitingShow();
    const sendData = {
      token: $('input[name="token"]').val(),
      mb_no: owner.mb_no,
      srw_id: owner.srw_id,
      unique_ids: unique_ids,
      ...moreSendData,
    }

    $.fileDownload(g5_url + `/subrental_api/?module=${module}&action=${action}`, {
      httpMethod: "POST", data: { ...sendData }
    }).fail(function(e) {
      if (e && typeof e === 'string') {
        const jsonString =e.replace(/<pre>|<\/pre>|<div.*?>|<\/div>/g, '').trim();
        try {
          const response = JSON.parse(jsonString);
          const errorMessage = owner.decodeUnicode(response.error_msg);
          owner.clientAlertFnc(owner, errorMessage, response.error_code);
        } catch (err) {
          owner.clientAlertFnc(owner, "JSON 파싱 오류", "0009");
        }
      } else {
        owner.clientAlertFnc(owner, "응답 텍스트가 정의되지 않았습니다.", "0009");
      }
    }).always(function(data) {
      owner.removeModalUiWrapperHtml('excel_download_modal');
      owner.waitingHide();
    });
  }

  showInboundCompletedInfoModal(owner, sendData) {
    owner.callAPI(owner, 'WareHouseInOut', 'getClientInboundCompletedInfo', sendData, (owner, response) => {
      const info = response?.result_data?.info ?? [];
      const photo = response?.result_data?.photo ?? [];
      owner.renderClientUiModalWithHandleBars({
        modalWrapId: 'inbound_completed_info_modal',
        modalClass: 'modal_lg',
        handlebarsData: {info: info, photo: photo},
        handlebarsId: 'popup-inbound-completed-info-template',
      });
      $('#btn_inbound_completed_modal_close').off().on({
        click: function() {
          owner.removeModalUiWrapperHtml('inbound_completed_info_modal');
        }
      });
      $('.imgWrap').off().on({
        click: function() {
          const imageUrl = $(this).find('img').attr('src');
          const newWindow = window.open("", "_blank", "width=800,height=600");
          newWindow.document.write(`
            <html>
            <head>
                <title>입고 처리 내역</title>
                <style>
                    body { display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                    img { max-width: 100%; max-height: 100%; }
                </style>
            </head>
            <body>
                <img src="${imageUrl}" alt="">
            </body>
            </html>
        `);
        }
      });
    }, (ownerClass, error, code) => owner.clientAlertFnc(ownerClass, error, code));
  }

  bindSortEvent(owner, callback) {
    $(document).off('click', '.table_sort').on('click', '.table_sort', function() {
      const _this = $(this);
      if (_this.hasClass('desc')) {
        _this.removeClass('desc').addClass('asc');
        owner.searchStatus.sort = 'asc';
      } else {
        _this.removeClass('asc').addClass('desc');
        owner.searchStatus.sort = 'desc';
      }
      if (typeof callback === 'function') {
        callback();
      }
    });
  }
}
