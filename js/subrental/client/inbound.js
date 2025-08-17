class inbound extends warehouse_base {
  constructor(srw_id, mb_no) {
    super(srw_id, mb_no);

    this.pageMode = "pending";
    this.barcodeCancelCheckboxData = [
      { text: "Y", value: "Y" },
      { text: "N", value: "N" },
    ];
    this.inboundRouteCheckboxData = [
      { text: "설치업체", value: "install" },
      { text: "택배", value: "parcel" },
    ];
    this.inboundReasonCheckboxData = [
      { text: "회수", value: "return" },
      { text: "미처리", value: "miss" },
      { text: "취소요청", value: "cancel" },
    ];
    this.fixedTablePendingColList = [
      { colWidth: "50px", txt: "선택", colName: "", className: "Tcenter" },
      { colWidth: "240px", txt: "바코드", colName: "sr_it_barcode_no" },
      { colWidth: "200px", txt: "상품명", colName: "it_name" },
    ];
    this.fixedTableCompletedColList = [
      {
        colWidth: "50px",
        txt: "선택",
        colName: "auto_check_box",
        className: "Tcenter",
      },
      { colWidth: "240px", txt: "바코드", colName: "sr_it_barcode_no" },
      { colWidth: "200px", txt: "상품명", colName: "it_name" },
    ];
    this.searchKeyData = [
      { text: "상품명", value: "gsi.it_name" },
      { text: "바코드", value: "sism.sr_it_barcode_no" },
      { text: "사업소명", value: "gm.mb_giup_bname" },
    ];
    this.initialSearchStatus = {
      mb_no: this.mb_no,
      srw_id: this.srw_id,
      category: [],
      barcode_cancel: [],
      inbound_route: [],
      inbound_reason: [],
      search_key: "all",
      search_text: "",
      sort: "desc",
    };
    this.searchStatus = {
      ...this.initialSearchStatus,
    };
    this.inboundListData = [];
    // 서버로 파일 보내기위한 용도
    this.inboundPhots = [];
    // 중복파일 확인 알림 용도
    this.uploadedFiles = [];
    this.inboundInfo = [];
  }

  drawBasic() {
    const owner = this;
    // 대대여 상품 유형
    owner.drawCategoryCheckbox();
    owner.addUiCheckbox({
      target: $("#barcode_cancel_checkbox"),
      title: "바코드 해지 여부",
      itemList: owner.barcodeCancelCheckboxData,
    });
    owner.addUiCheckbox({
      target: $("#inbound_route_checkbox"),
      title: "입고 경로",
      itemList: owner.inboundRouteCheckboxData,
    });
    owner.addUiCheckbox({
      target: $("#inbound_reason_checkbox"),
      title: "입고 사유",
      itemList: owner.inboundReasonCheckboxData,
    });
    owner.addUiSelect({
      target: $("#sel_search"),
      itemArray: owner.searchKeyData,
      txtName: "text",
      valName: "value",
    });
    // 테이블 thead 세팅
    owner[
      "draw" + owner.capitalizeFirstLetter(owner.pageMode) + "DoubleListHeader"
    ]();

    const sendData = {
      token: $('input[name="token"]').val(),
      page_no: owner.page_no,
      type: owner.pageMode,
      ...owner.searchStatus,
    };

    owner.callAPI(
      owner,
      "WareHouseInOut",
      "getClientInBoundInit",
      sendData,
      owner.success_get_init_info,
      (ownerClass, error, code) => this.clientAlertFnc(ownerClass, error, code)
    );

    owner.init_event();
  }

  success_get_init_info(owner, response) {
    // 대대여 상품 유형
    const category = response?.result_data?.category ?? [];
    owner.drawCategoryCheckbox(category);
    owner.pageSetting(owner, response);
    owner.init_event();
  }

  init_event() {
    const owner = this;

    // 탭 버튼
    $(".inbound-tab").off().on({
      click: function (e) {
        e.preventDefault();
        const _this = $(this);
        $(".inbound-tab").removeClass("active");
        _this.addClass("active");
        owner.pageMode = _this.data("pageMode");
        owner.page_no = 1;
        owner[
          "draw" +
            owner.capitalizeFirstLetter(owner.pageMode) +
            "DoubleListHeader"
        ]();
        if (owner.pageMode === "pending") {
          $("#btn_inbound_process").show();
        } else {
          $("#btn_inbound_process").hide();
        }
        owner.searchStatus.sort = "desc";
        owner.bindDataPageList();
      },
    });

    // 검색 - 초기화
    $("#btn_search_reset").off().on({
      click: function (e) {
        e.preventDefault();
        owner.searchStatus = {
          ...owner.initialSearchStatus,
        };
        Object.keys(owner.searchStatus).forEach((v) => {
          if ($(`#${v}_checkbox`).length > 0) {
            owner.checkboxReset(`${v}_checkbox`);
          }
        });
        owner.setUISelVal($("#sel_search"), "전체", "all", false);
        $("#search_text").val("");
        $(".table_sort").removeClass("asc").addClass("desc");
      },
    });

    // 검색 - submit
    $("#btn_search_submit").off().on({
      click: function (e) {
        e.preventDefault();
        // 상품 유형
        const categoryObj = $('#category_checkbox input[type="checkbox"]');
        owner.searchStatus.category = owner.setCheckedDataExcluding(
          categoryObj,
          ["all"]
        );
        // 바코드 해지 여부
        const barcodeObj = $(
          '#barcode_cancel_checkbox input[type="checkbox"]'
        );
        owner.searchStatus.barcode_cancel = owner.setCheckedDataExcluding(
          barcodeObj,
          ["all"]
        );
        // 입고 경로
        const routeObj = $('#inbound_route_checkbox input[type="checkbox"]');
        owner.searchStatus.inbound_route = owner.setCheckedDataExcluding(
          routeObj,
          ["all"]
        );
        // 입고 사유
        const reasonObj = $(
          '#inbound_reason_checkbox input[type="checkbox"]'
        );
        owner.searchStatus.inbound_reason = owner.setCheckedDataExcluding(
          reasonObj,
          ["all"]
        );
        owner.searchStatus.search_key = owner.getUISelVal($("#sel_search"));
        owner.searchStatus.search_text = $("#search_text").val();

        owner.page_no = 1;
        owner.bindDataPageList();
      },
    });

    // 엔터 검색
    $("#search_text").off().on("keyup", function (e) {
      if (e.key === "Enter") {
        $("#btn_search_submit").trigger("click");
      }
    });

    // 목록 정렬
    owner.bindSortEvent(owner, () => {
      owner.bindDataPageList();
    });

    // 팝업 - 입고 처리 open
    $("#btn_inbound_process").off().on({
      click: function (e) {
        e.preventDefault();
        owner.inboundInfo = [];
        if (!$(this).hasClass("disable")) {
          const inboundList = owner.getCheckedItems($("#inbound_item_fixed_list")).map((v) => v);

          if (inboundList.length === 1) {
            const sendData = {
              mb_no: owner.mb_no,
              srw_id: owner.srw_id,
              od_id: inboundList[0],
            };

            owner.callAPI(
              owner,
              "WareHouseInOut",
              "getClientInBoundProcessModalData",
              sendData,
              (owner, response) => {
                const dataInfo = response.result_data?.dataInfo ?? {};
                const virtual = response.result_data?.virtual ?? [];
                const rack = response.result_data?.rack ?? [];
                const files = response.result_data?.files ?? [];

                owner.inboundInfo = dataInfo;

                owner.renderClientUiModalWithHandleBars({
                  modalWrapId: "inbound_process_modal",
                  modalClass: "modal_lg",
                  handlebarsData: { dataInfo },
                  handlebarsId: "popup-inbound-process-template",
                });
                const handlebarsHtml = owner.getHandlebarsTemplate(
                  "popup-move-rack-list-template",
                  { rackList: rack[virtual[0].svw_id] }
                );
                $("#inbound_process_modal").find("#sel_inbound_svwr_id").append(handlebarsHtml);

                // 가상 창고, 렉 세팅 & 이벤트 바인딩
                owner.addUiSelect({
                  target: $("#sel_inbound_svw_id"),
                  itemArray: virtual,
                  txtName: "svw_name",
                  valName: "svw_id",
                  allCheck: false,
                });
                owner.bindUiSelectEvent(
                  "sel_inbound_svwr_id",
                  (select, option) => {
                    const available = option.data("available");
                    select.data("available", available);
                  }
                );
                $("#sel_inbound_svw_id .option button").off().on("click", function (e) {
                  e.preventDefault();
                  const _this = $(this);
                  const svw_id = _this.val();
                  const selRackListObj = $("#sel_inbound_svwr_id");
                  owner.drawMoveRackSelectOptionAjax(
                    owner,
                    svw_id,
                    "WareHouseStock",
                    "getRackDataByVirtual",
                    selRackListObj,
                    () => {
                      selRackListObj.empty();
                    }
                  );
                });

                // 팝업 토글버튼 이벤트 바인딩
                const barcodeStatus = {
                  inbound_damaged_yn: false,
                  inbound_missing_parts: false,
                };
                const toggleTargetObj = $(".tableTabTopcustom");
                owner.bindTableTabTopCustom(toggleTargetObj);
                toggleTargetObj.off().on("click", function () {
                  const status = $(this).find("span.status.active").data("status");
                  const name = $(this).closest(".toggleWrap").attr("id");
                  if (status === "error") {
                    barcodeStatus[name] = true;
                    $("#inbound_action_required_sec").show();
                  } else {
                    barcodeStatus[name] = false;
                    if (
                      !barcodeStatus.inbound_damaged_yn &&
                      !barcodeStatus.inbound_missing_parts
                    ) {
                      $("#inbound_action_required_sec").hide();
                    }
                  }
                });

                // 미리보기 임시파일 세팅
                $('#fileimage').val(null);
                if (files.length > 0) {
                  owner.inboundPhots = files.map(file => ({
                    name: file.fileName,
                    size: file.fileSize,
                    uuid: file.fileUuid,
                    path: file.filePath,}));
                  if (owner.inboundPhots.length > 0) {
                    let html = '';
                    owner.inboundPhots.forEach(file => {
                      html += `<div class="imgWrap">
                      <img src="${g5_url}/${file.path}" alt="${file.name}" />
                      <div class="closeBtn" data-odid="${dataInfo.od_id}" data-file-path="${file.path}" data-file-name="${file.name}"></div>
                    </div>`;
                    });
                    $('#preview_list').append(html);
                  }
                }
              },
              (ownerClass, error, code) => {
                owner.inboundInfo = [];
                owner.clientAlertFnc(ownerClass, error, code);
              }
            );
          } else {
            return owner.alertFormError("입고처리는 1건씩 진행해 주세요.");
          }
        }
      },
    });

    // 팝업 - 이미지 추가 버튼 이벤트 바인딩
    $(document).off("change", "#fileimage").on("change", "#fileimage", function (e) {
      owner.setThumbnailFormData(e);
    });

    // 팝업 - 이미지 삭제
    $(document).off('click', '.imgWrap .closeBtn').on('click', '.imgWrap .closeBtn', function() {
      const obj = $(this);
      const sendData = {
        od_id: obj.data('odid'),
        filePath: obj.data('filePath')
      }
      const fileName = obj.data('fileName');
      owner.callAPI(owner, 'WareHouseInOut', 'deleteInBoundTempPhoto', sendData, (owner, response) => {
        owner.inboundPhots = owner.inboundPhots.filter((f) => f.path !== sendData.filePath);
        obj.closest('.imgWrap').remove();
      }, (owner, error, code) => owner.clientAlertFnc(owner, error, code))
    });

    // 팝업 - 입고 처리 submit
    $(document).off("click", "#btn_inbound_process_submit").on("click", "#btn_inbound_process_submit", function (e) {
        e.preventDefault();
        // 상품이미지 확인
        if (owner.inboundPhots.length < 2) {
          return owner.alertFormError("상품 사진은 2건 이상 등록되야 합니다.");
        }
        const rackObj = $("#sel_inbound_svwr_id");
        const available = parseInt(
          rackObj.find(".select button").data("available")
        );
        // 보관 렉 확인
        if (available === 0) {
          rackObj.find(".select").addClass("error");
          return owner.alertFormError(
            "해당 렉의 입고 가능한 수량은 " + available + "건 입니다."
          );
        }
        // 작동여부에 하자가 있거나 부품누락이 있으면 메모에 글자가 들어가야함1
        const damaged = owner.getUiToggleVal($("#inbound_damaged_yn"));
        const missingParts = owner.getUiToggleVal($("#inbound_missing_parts"));
        const inboundTextareaObj = $("#inbound_memo");
        const inboundMemo = $.trim(inboundTextareaObj.val());
        let action_required = "none";
        if (damaged === "error" || missingParts === "error") {
          action_required = owner.getUiToggleVal($("#inbound_action_required"));
          if (Array.from(inboundMemo).length < 1) {
            inboundTextareaObj.closest('.textarea_wrap').addClass('error');
            inboundTextareaObj.closest('.textarea_wrap').find('.errorText').remove();
            return owner.alertFormError(
              "작동 여부 비정상 or 부품 누락 있음 선택 상태에서는 메모 입력이 필수입니다."
            );
          }
        }

        if (Array.from(inboundMemo).length > 200) {
          inboundTextareaObj.closest('.textarea_wrap').addClass('error');
          inboundTextareaObj.closest('.textarea_wrap').find('.errorText').remove();
          return owner.alertFormError('메모는 200글자 이내로 입력해 주세요.');
        }

        const sendData = {
          mb_no: owner.mb_no,
          srw_id: owner.srw_id,
          od_id: $('input[name="inbound_process_od_id"]').val(),
          svwr_id: parseInt(owner.getUISelVal($("#sel_inbound_svwr_id"))),
          files: owner.inboundPhots,
          not_working: damaged === "error" ? "Y" : "N",
          missing_parts: missingParts === "error" ? "Y" : "N",
          action_required: action_required,
          ish_memo: $.trim(inboundMemo),
        };

        owner.callAPI(
          owner,
          "WareHouseInOut",
          "inboundOrderProcess",
          sendData,
          (owner, response) => {
            owner.clientAlertFnc(
              owner,
              "입고처리가 완료되었습니다.",
              "0010",
              false,
              "success"
            );
            owner.removeModalUiWrapperHtml("inbound_process_modal");
            owner.inboundInfo = [];
          },
          (ownerClass, error, code) => {
            owner.clientAlertFnc(ownerClass, error, code);
            if (code === "0010") {
              owner.removeModalUiWrapperHtml("inbound_process_modal");
            } else if (code === "0020") {
              owner.inboundPhots = [];
              $('#preview_list').empty();
            }
          }
        );
      });

    // 입고 처리 메모 글자수
    $(document).off("keyup", "#inbound_memo").on("keyup", "#inbound_memo", function (e) {
        e.preventDefault();
        const count = Array.from($(this).val()).length;
        $("#memo_count").text(count);
      });

    // 팝업 - 입고 처리 내역
    $(document).off("click", ".btn-inbound-completed-info").on("click", ".btn-inbound-completed-info", function (e) {
        e.preventDefault();
        const ish_id = $(this).closest("tr").data("rowId");
        const sendData = {
          srw_id: owner.srw_id,
          mb_no: owner.mb_no,
          ish_id: parseInt(ish_id),
        };
        owner.showInboundCompletedInfoModal(owner, sendData);
      });

    $(document).off('click', '.btn-go-detail').on('click', '.btn-go-detail', function(e) {
      e.preventDefault();
      const orderSrwId = $(this).data('realWarehouse');
      if (owner.pageMode === 'pending' && parseInt(owner.srw_id) !== parseInt(orderSrwId)) {
        alert('입고완료 후 바코드 상세 내역을 확인하실 수 있습니다.');
        return false;
      } else if (owner.pageMode === 'completed' && parseInt(owner.srw_id) !== parseInt(orderSrwId)) {
        alert('해당 바코드의 재고 위치가 변경되어 상세 정보를 확인할 수 없습니다.');
        return false;
      }else {
        window.location.href = $(this).attr('href');
      }
    });

    // 페이징 버튼
    owner.bindPagination(owner, ".pagination a", () =>
      owner.bindDataPageList()
    );
    // select 리스트 전체 선택
    owner.bindClientAllCheckboxController();
  }

  pageSetting(owner, response) {
    const inboundList = response?.result_data?.list ?? [];
    const pagination = inboundList?.ui_pagination ?? {};
    owner.inboundListData = inboundList?.data ?? [];
    owner.bindUiPageList({
      id: "inbound_item_fixed_list",
      dataList: owner.inboundListData,
      noneHtml: false,
      customRowHtmlCallback: (item) => {
        return `<tr data-row-id="${item.uniqueId}">
            <td class="Tcenter">
              <div class="DDY_checkBox">
                <input type="checkbox" name="auto_checkbox[]" id="checkbox_${item.uniqueId}" value="${item.uniqueId}" />
                <label for="checkbox_${item.uniqueId}"></label>
              </div>
            </td>
            <td>
              <em>
                <a href="${g5_sub_rental_url}/warehouse/barcode_detail.php?unique_id=${item.sr_stock_id}" 
                class="btn-go-detail" 
                data-real-warehouse="${item.srw_id}">${item.sr_it_barcode_no}</a>
              </em>
            </td>
            <td>${item.it_name}</td>
        </tr>`;
      },
    });
    const pageListOptions = {
      id: "inbound_item_list",
      dataList: owner.inboundListData,
      noneHtml: false,
    };

    const listSectionObj = $("#inbound_item_list_sec");
    // 데이터 none 박스 제거
    listSectionObj.find(".pagination, .noData, .table_ref").remove();

    if (owner.pageMode === "completed") {
      pageListOptions.customRowHtmlCallback = (item) => {
        return `<tr data-row-id="${item.uniqueId}">
          <td>${item.mb_giup_bname}</td>
          <td>${item.ca_name}</td>
          <td>${item.inbound_path ?? "-"}</td>
          <td class="Tcenter">${
            item.return_reason !== ""
              ? `<span class="DDY_badge squre_line ${
                  item.return_reason === "회수"
                    ? "DDY_badge_Red"
                    : "DDY_badge_Blue"
                }">${item.return_reason}</span>`
              : "-"
          }
          </td>
          <td><em><a href="#" class="btn-inbound-completed-info">${
            item.barcode_status
          }</a></em></td>
          <td>${item.ish_location_text ?? "-"}</td>
          <td>${item.warehouse_put_dt ?? "-"}</td>
        </tr>`;
      };
    } else {
      pageListOptions.customRowHtmlCallback = (item) => {
        return `<tr data-row-id="${item.uniqueId}">
          <td>${item.mb_giup_bname ?? "-"}</td>
          <td>${item.return_od_time ?? "-"}</td>
          <td>${item.ca_name ?? "-"}</td>
          <td>${item.inbound_path ?? "-"}</td>
          <td>${item.install_or_delivery ?? "-"}</td>
          <td class="Tcenter">${
            item.return_reason !== ""
              ? `<span class="DDY_badge squre_line ${
                  item.return_reason === "회수"
                    ? "DDY_badge_Red"
                    : "DDY_badge_Blue"
                }">${item.return_reason}</span>`
              : "-"
          }
          </td>
          <td class="Tcenter">${item.barcode_cancel_yn ?? "-"}</td>
        </tr>`;
      };
    }
    owner.bindUiPageList(pageListOptions);

    const paginationHtml = owner.setUiPagination({
      paginationProps: pagination,
    });

    if (inboundList?.data.length !== 0) {
      listSectionObj.append(`<div class="table_ref">
              <p>
                표를 밀어보세요.<img src="${g5_img_url}/subrental/table_text_arrow.svg" alt="표를 밀어보세요">
              </p>
            </div>`);
    }
    if (inboundList?.data.length === 0) {
      listSectionObj.append(`<div class="noData">
                            <p>데이터가 존재하지 않습니다</p>
                        </div>`);
    }
    listSectionObj.append(paginationHtml);
    owner.doubleTableMatchRowHeight($(".divideTable"));

    let modalMessage = "";
    if (owner.pageMode === "pending") {
      modalMessage = "검색결과 내 모든 입고대기 건을 다운로드 하시겠습니까?";
    } else {
      modalMessage =
        "선택한 입고완료 건이 없습니다.<br>검색결과 내 모든 입고완료 건을 다운로드 하시겠습니까?";
    }
    // 엑셀 다운 로드
    owner.bindExcelDownload({
      ownerClass: owner,
      selectedListTarget: $("#inbound_item_fixed_list"),
      module: "WareHouseInOut",
      action: "inboundExcelDownload",
      dataList: owner.inboundListData,
      moreSendData: {
        type: owner.pageMode,
        ...owner.searchStatus,
      },
      modalMessage,
    });
  }

  bindDataPageList() {
    const owner = this;
    const sendData = {
      token: $('input[name="token"]').val(),
      page_no: owner.page_no,
      type: owner.pageMode,
      ...owner.searchStatus,
    };

    owner.callAPI(
      owner,
      "WareHouseInOut",
      "getClientInBoundList",
      sendData,
      (owner, response) => {
        owner.pageSetting(owner, response);
      },
      (ownerClass, error, code) => owner.clientAlertFnc(ownerClass, error, code)
    );
  }

  drawPendingDoubleListHeader() {
    $("#inbound_item_fixed_list").remove();
    $("#inbound_item_list").remove();
    const colList = [
      { colWidth: "200px", txt: "계약 사업소", colName: "mb_giup_bname" },
      {
        colWidth: "120px",
        txt: "회수 요청일",
        colName: "return_od_time",
        sort: "table_sort desc",
      },
      { colWidth: "100px", txt: "상품 유형", colName: "ca_name" },
      { colWidth: "100px", txt: "입고 경로", colName: "inbound_path" },
      {
        colWidth: "150px",
        txt: "설치업체/택배운송장",
        colName: "install_or_delivery",
      },
      {
        colWidth: "100px",
        txt: "입고 사유",
        colName: "return_reason",
        className: "Tcenter",
      },
      {
        colWidth: "120px",
        txt: "바코드 해지 여부",
        colName: "barcode_cancel_yn",
        className: "Tcenter",
      },
    ];
    this.drawUiDoubleList({
      target: $("#inbound_item_list_sec"),
      fixedTableId: "inbound_item_fixed_list",
      fixedTableWidthPer: 40,
      fixedTableColList: this.fixedTablePendingColList,
      tableId: "inbound_item_list",
      colList,
    });
  }
  drawCompletedDoubleListHeader() {
    $("#inbound_item_fixed_list").remove();
    $("#inbound_item_list").remove();
    const colList = [
      { colWidth: "200px", txt: "계약 사업소", colName: "mb_giup_bname" },
      { colWidth: "100px", txt: "상품 유형", colName: "ca_name" },
      { colWidth: "100px", txt: "입고 경로", colName: "inbound_path" },
      {
        colWidth: "100px",
        txt: "입고 사유",
        colName: "return_reason",
        className: "Tcenter",
      },
      { colWidth: "100px", txt: "정상 여부", colName: "barcode_status" },
      { colWidth: "200px", txt: "창고 위치", colName: "ish_location_text" },
      {
        colWidth: "120px",
        txt: "입고 처리일",
        colName: "warehouse_put_dt",
        sort: "table_sort desc",
      },
    ];
    this.drawUiDoubleList({
      target: $("#inbound_item_list_sec"),
      fixedTableId: "inbound_item_fixed_list",
      fixedTableWidthPer: 40,
      fixedTableColList: this.fixedTableCompletedColList,
      tableId: "inbound_item_list",
      colList,
    });
  }

  /**
   *  이미지 처리
   * @param event
   */
  setThumbnailFormData(event) {
    const owner = this;
    const selectedFiles = event.target.files;
    const MAX_SIZE_MB = 20;
    const MAX_SIZE_BYTES = MAX_SIZE_MB * 1024 * 1024;
    const previewList = $("#preview_list");

    if (previewList.children().length + selectedFiles.length > 10) {
      $(event.target).val(null);
      return owner.alertFormError("최대 10장만 등록 가능합니다.");
    }

    let sendSubmit = true;
    const formData = new FormData();
    formData.append('od_id', owner.inboundInfo.od_id);

    $.each(selectedFiles, function (index, file) {
      const isDuplicate = owner.inboundPhots.some((f) => f.name === file.name && f.size === file.size);
      if (isDuplicate) {
        sendSubmit = false;
        return owner.alertFormError(file.name + " 파일은 이미 추가되었습니다.1");
      }

      if (file.size > MAX_SIZE_BYTES) {
        sendSubmit = false;
        return owner.alertFormError(file.name + " 파일의 크기가 " + MAX_SIZE_MB + "MB를 초과합니다.");
      }

      formData.append('files[]', file);
    });

    if (sendSubmit) {
      owner.callAPI(owner, 'WareHouseInOut', 'updateInBoundTempPhotoFormData', formData,
        (owner, response) => {
          let html = '';
          const photos = response.result_data?.files ?? [];
          photos.forEach((file) => {
            const isAlreadyExists = owner.inboundPhots.some(
              (f) => f.name === file.fileName && f.size === file.fileSize
            );
            if (!isAlreadyExists) {
              owner.inboundPhots.push({
                name: file.fileName,
                size: file.fileSize,
                uuid: file.fileUuid,
                path: file.filePath,
              });
              html += `<div class="imgWrap">
                      <img src="${g5_url}/${file.filePath}" alt="${file.fileName}" />
                      <div class="closeBtn" data-odid="${owner.inboundInfo.od_id}" data-file-path="${file.filePath}" data-file-name="${file.fileName}"></div>
                    </div>`;
            }
          });
          $('#preview_list').append(html);
        },
        (ownerClass, error, code) => owner.clientAlertFnc(ownerClass, error, code),
        { useFormData: true });
    }
    $(event.target).val(null);
  }
}
