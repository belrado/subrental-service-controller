class outbound extends warehouse_base {
  constructor(srw_id, mb_no) {
    super(srw_id, mb_no);
    const initData = this.getSelectDateFnc("thirtyDaysAgo");
    this.pageMode = "install";
    this.page_no = 1;
    this.orderStatusCheckData = [
      { text: "주문완료", value: "주문완료" },
      { text: "출고대기", value: "출고준비중", checked: true },
      { text: "출고완료", value: "출고완료" },
    ];
    this.searchKeyData = [
      { text: "상품명", value: "gsi.it_name" },
      { text: "바코드", value: "gso.sr_it_barcode_no" },
      { text: "사업소명", value: "gm.mb_giup_bname" },
    ];
    this.initialSearchStatus = {
      mb_no: this.mb_no,
      srw_id: this.srw_id,
      category: [],
      order_status: ["출고준비중"],
      start_date: initData.start,
      end_date: initData.end,
      search_key: "all",
      search_text: "",
      sort: "desc",
    };
    this.searchStatus = {
      ...this.initialSearchStatus,
    };
    this.installFixedTableColList = [
      {
        colWidth: "0.5",
        txt: "선택",
        colName: "auto_check_box",
        className: "Tcenter",
      },
      {
        colWidth: "1",
        txt: "설치예정일",
        colName: "sr_delivery_hope_dt",
        sort: "table_sort desc",
      },
      { colWidth: "2", txt: "바코드", colName: "sr_it_barcode_no" },
    ];
    this.parcelFixedTableColList = [
      {
        colWidth: "0.5",
        txt: "선택",
        colName: "auto_check_box",
        className: "Tcenter",
      },
      {
        colWidth: "1",
        txt: "주문일",
        colName: "od_time",
        sort: "table_sort desc",
      },
      { colWidth: "2", txt: "바코드", colName: "sr_it_barcode_no" },
    ];

    this.outboundListData = [];
    this.moveRackData = {};
  }

  drawBasic() {
    const owner = this;
    // 날자 세팅
    $("#start_picker").val(this.searchStatus.sdm_start_date);
    $("#end_picker").val(this.searchStatus.sdm_end_date);
    // 대대여 상품 유형
    owner.drawCategoryCheckbox();
    // 출고 처리 상태
    owner.addUiCheckbox({
      target: $("#order_status_checkbox"),
      title: "상태",
      itemList: owner.orderStatusCheckData,
      allChecked: false,
    });
    // 검색어 분류
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
      "getClientOutBoundInit",
      sendData,
      owner.success_get_init_info,
      (ownerClass, error, code) => owner.clientAlertFnc(ownerClass, error, code)
    );
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

    // tui.datepicker 세팅 & 날짜 선택 이벤트 바인딩
    owner.bindRangeDateBtnWithTuiPicker({
      className: "select-date",
      startDate: owner.searchStatus.start_date,
      endDate: owner.searchStatus.end_date,
    });

    // 탭 버튼
    $(".outbound-tab")
      .off()
      .on({
        click: function (e) {
          e.preventDefault();
          const _this = $(this);
          $(".outbound-tab").removeClass("active");
          _this.addClass("active");
          owner.pageMode = _this.data("pageMode");
          owner.page_no = 1;
          owner[
            "draw" +
              owner.capitalizeFirstLetter(owner.pageMode) +
              "DoubleListHeader"
          ]();
          if (owner.pageMode === "parcel") {
            $("#btn_bulk_parcel_excel_upload").show();
          } else {
            $("#btn_bulk_parcel_excel_upload").hide();
          }
          owner.searchStatus.sort = "desc";
          owner.bindDataPageList();
        },
      });

    // 검색 초기화
    $("#btn_search_reset")
      .off()
      .on({
        click: function (e) {
          e.preventDefault();
          owner.searchStatus = {
            ...owner.initialSearchStatus,
          };
          owner.checkboxReset("category_checkbox");
          owner.checkboxReset("order_status_checkbox", false);
          owner.setUISelVal($("#sel_search"), "전체", "all", false);
          $("#start_picker").val(owner.searchStatus.start_date);
          $("#end_picker").val(owner.searchStatus.end_date);
          $("#search_text").val("");
          $(".select-date").removeClass("on");
          $(".table_sort").removeClass("asc").addClass("desc");
        },
      });
    // 검색 submit
    $("#btn_search_submit")
      .off()
      .on({
        click: function (e) {
          e.preventDefault();

          // 주문일
          owner.searchStatus.start_date = $("#start_picker").val();
          owner.searchStatus.end_date = $("#end_picker").val();
          // 상품 유형
          const categoryObj = $('#category_checkbox input[type="checkbox"]');
          owner.searchStatus.category = owner.setCheckedDataExcluding(
            categoryObj,
            ["all"]
          );
          // 주문 상태
          const stockObj = $('#order_status_checkbox input[type="checkbox"]');
          owner.searchStatus.order_status = owner.setCheckedDataExcluding(
            stockObj,
            ["all"]
          );
          // 검색어 분류
          owner.searchStatus.search_key = owner.getUISelVal($("#sel_search"));
          // 검색어
          owner.searchStatus.search_text = $.trim($("#search_text").val());

          owner.page_no = 1;
          owner.bindDataPageList();
        },
      });

    // 엔터 검색
    $("#search_text").on("keyup", function (e) {
      if (e.key === "Enter") {
        $("#btn_search_submit").trigger("click");
      }
    });

    // 목록 정렬
    owner.bindSortEvent(owner, () => {
      owner.bindDataPageList();
    });

    // 팝업 - 출고처리 open - 출고 처리
    $("#btn_outbound_process")
      .off()
      .on({
        click: function (e) {
          e.preventDefault();
          const outboundOrder = owner
            .getCheckedItems($("#outbound_item_fixed_list"))
            .map((v) => v);
          if (outboundOrder.length > 0) {
            owner.renderClientUiModalWithHandleBars({
              modalWrapId: "outbound_process_modal",
              modalClass: "modal_md",
              handlebarsData: { selectCnt: outboundOrder.length },
              handlebarsId: "popup-outbound-process-template",
            });
          } else {
            return owner.alertFormError("선택된 항목이 없습니다.");
          }
        },
      });
    // 팝업 - 촐고 처리 submit
    $(document)
      .off("click", "#btn_outbound_submit")
      .on("click", "#btn_outbound_submit", function (e) {
        e.preventDefault();
        const outboundOrder = owner
          .getCheckedItems($("#outbound_item_fixed_list"))
          .map((v) => v);
        if (outboundOrder.length > 0) {
          const sendData = {
            token: $('input[name="token"]').val(),
            mb_no: owner.mb_no,
            srw_id: owner.srw_id,
            od_ids: outboundOrder,
            type: owner.pageMode,
          };
          owner.callAPI(
            owner,
            "WareHouseInOut",
            "outboundOrderProcess",
            sendData,
            (owner, response) => {
              owner.clientAlertFnc(
                owner,
                "출고 처리가 완료 되었습니다.",
                "0010",
                false,
                "success"
              );
              owner.removeModalUiWrapperHtml("outbound_process_modal");
            },
            (ownerClass, error, code) => {
              owner.clientAlertFnc(ownerClass, error, code);
              if (code === "0010") {
                owner.removeModalUiWrapperHtml("outbound_process_modal");
              }
            }
          );
        } else {
          owner.removeModalUiWrapperHtml("outbound_process_modal");
          return owner.alertFormError("선택된 항목이 없습니다.");
        }
      });
    // 팝업 - 출고 처리 cancel
    $(document)
      .off("click", "#btn_outbound_cancel")
      .on("click", "#btn_outbound_cancel", function (e) {
        e.preventDefault();
        owner.removeModalUiWrapperHtml("outbound_process_modal");
      });

    // 팝업 - 택배 정보 단건 open
    $(document)
      .off("click", ".btn_add_delivery")
      .on("click", ".btn_add_delivery", function (e) {
        e.preventDefault();
        const sendData = {
          token: $('input[name="token"]').val(),
          mb_no: owner.mb_no,
          srw_id: owner.srw_id,
          od_id: parseInt($(this).closest("tr").data("rowId")),
        };
        owner.callAPI(
          owner,
          "WareHouseInOut",
          "getOrderDeliveryInfo",
          sendData,
          (owner, response) => {
            const resData = response?.result_data ?? [];
            if (resData.delivery.length > 0) {
              owner.renderClientUiModalWithHandleBars({
                modalWrapId: "outbound_delivery_modal",
                modalClass: "modal_md",
                handlebarsData: { info: resData.info },
                handlebarsId: "popup-delivery-template",
              });

              owner.addUiSelect({
                target: $("#sel_delivery"),
                itemArray: resData.delivery,
                name: "ct_delivery_company",
                txtName: "name",
                valName: "val",
                selected: {
                  key: "val",
                  value: resData.info?.ct_delivery_company,
                },
              });
            } else {
              owner.alertFormError("택배사 정보를 불러오지 못했습니다.");
            }
          },
          (ownerClass, error, code) =>
            owner.clientAlertFnc(ownerClass, error, code)
        );
      });
    // 팝업 - 택배 정보 단건 submit
    $(document)
      .off("click", "#btn_add_tracking_number")
      .on("click", "#btn_add_tracking_number", function (e) {
        e.preventDefault();
        const modalObj = $(this).closest("#outbound_delivery_modal");
        const deliveryCompanyObj = modalObj.find(
          'button[name="ct_delivery_company"]'
        );
        const deliveryNumObj = modalObj.find('input[name="ct_delivery_num"]');

        if (
          deliveryCompanyObj.val() === "all" ||
          deliveryCompanyObj.val() === ""
        ) {
          deliveryCompanyObj.closest(".select").addClass("error");
          return owner.alertFormError("택배사를 선택해 주세요");
        }

        if (deliveryCompanyObj.val() !== 'etc' && !owner.validateIsNumeric(deliveryNumObj.val())) {
          return owner.alertFormError(
            "기타배송을 제외한 운송장 번호는 숫자로 등록해주세요",
            deliveryNumObj
          );
        }

        const sendData = {
          token: $('input[name="token"]').val(),
          mb_no: owner.mb_no,
          srw_id: owner.srw_id,
          od_id: parseInt(
            modalObj.find('input[name="order_delivery_od_id"]').val()
          ),
          ct_delivery_company: $.trim(deliveryCompanyObj.val()),
          ct_delivery_num: $.trim(deliveryNumObj.val()),
        };

        owner.callAPI(
          owner,
          "WareHouseInOut",
          "updateOrderDelivery",
          sendData,
          () => {
            owner.clientAlertFnc(
              owner,
              "등록이 완료되었습니다.",
              "0010",
              false,
              "success"
            );
            owner.removeModalUiWrapperHtml("outbound_delivery_modal");
          },
          (ownerClass, error, code) => {
            owner.clientAlertFnc(ownerClass, error, code);
            if (code === "0010") {
              owner.removeModalUiWrapperHtml("outbound_delivery_modal");
            }
          }
        );
      });

    // 팝업 - 택배 정보 일괄 업로드 (excel)
    $("#btn_bulk_parcel_excel_upload")
      .off()
      .on({
        click: function (e) {
          e.preventDefault();
          if (owner.pageMode === "parcel") {
            owner.renderClientUiModalWithHandleBars({
              modalWrapId: "outbound_excel_upload_modal",
              modalClass: "modal_md",
              handlebarsData: {},
              handlebarsId: "popup-parcel-excel-upload-template",
            });
          } else {
            location.reload(true);
          }
        },
      });
    // 팝업 - 택배 정보 일괄 업로드 submit (excel)
    $(document)
      .off("click", "#btn_bulk_parcel_excel_upload_submit")
      .on("click", "#btn_bulk_parcel_excel_upload_submit", function (e) {
        e.preventDefault();
        if (owner.pageMode === "parcel") {
          const excelFile = new FormData(
            document.getElementById("bulk_parcel_excel_form")
          );
          excelFile.append("mb_no", owner.mb_no);
          excelFile.append("srw_id", owner.srw_id);
          owner.callAPI(
            owner,
            "WareHouseInOut",
            "outboundDeliveryInfoExcelUpload",
            excelFile,
            (owner, response) => {
              owner.clientAlertFnc(
                owner,
                "등록이 완료되었습니다.",
                "0010",
                false,
                "success"
              );
              owner.removeModalUiWrapperHtml("outbound_excel_upload_modal");
            },
            (ownerClass, error, code) => owner.clientAlertFnc(ownerClass, error, code),
            { useFormData: true }
          );
        } else {
          location.reload(true);
        }
      });

    // 페이징 버튼
    owner.bindPagination(owner, ".pagination a", () =>
      owner.bindDataPageList()
    );
    // select 리스트 전체 선택
    owner.bindClientAllCheckboxController();

    // 팝업 - 바코드 입력 open
    $(document)
      .off("click", ".btn_add_barcode_popup")
      .on("click", ".btn_add_barcode_popup", function (e) {
        e.preventDefault();
        const od_id = $(this).closest("tr").data("rowId");
        const sendData = {
          mb_no: owner.mb_no,
          srw_id: owner.srw_id,
          od_id: od_id,
        };

        owner.callAPI(
          owner,
          "WareHouseInOut",
          "getAvailableBarcodeList",
          sendData,
          (owner, response) => {
            const resData = response?.result_data ?? [];
            const orderInfo = resData?.order ?? {};
            const barcode = resData?.barcode ?? [];
            if (barcode.length > 0) {
              owner.renderClientUiModalWithHandleBars({
                modalWrapId: "add_barcode_modal",
                modalClass: "modal_md",
                handlebarsData: { orderInfo: orderInfo, barcode: barcode },
                handlebarsId: "popup-add-barcode-template",
              });
            } else {
              return owner.alertFormError(
                "선택한 주문에 사용할 수 있는 바코드가 없습니다."
              );
            }
          },
          (ownerClass, error, code) =>
            owner.clientAlertFnc(ownerClass, error, code)
        );
      });
    // 팝업 - 바코드 리스트 event bind
    $("#container")
      .off("click", ".btn_add_barcode_select")
      .on("click", ".btn_add_barcode_select", function (e) {
        e.preventDefault();
        const _this = $(this);
        const status = _this.data("barcodeStatus");
        const sr_it_barcode_no = _this.data("barcodeNo");
        $("#add_barcode_modal li").removeClass("active").removeClass("on");
        _this.closest("li").addClass("active");
        _this.closest("li.barcode-row").addClass("on");
        if (status === "new") {
          $("#add_barcode_modal .barcode-status-new").show();
        } else {
          $("#add_barcode_modal .barcode-status-new").hide();
        }
        _this
          .closest("#add_barcode_modal")
          .find('input[name="add_barcode_no"]')
          .val(sr_it_barcode_no);
      });
    // 팝업 - 바코드 입력 submit
    $(document)
      .off("click", "#btn_add_barcode_submit")
      .on("click", "#btn_add_barcode_submit", function (e) {
        e.preventDefault();
        const modalWrap = $(this).closest("#add_barcode_modal");
        const od_id = modalWrap.find('input[name="add_barcode_od_id"]').val();
        const sr_it_barcode_no = modalWrap
          .find('input[name="add_barcode_no"]')
          .val();
        if (sr_it_barcode_no) {
          const sendData = {
            mb_no: owner.mb_no,
            srw_id: owner.srw_id,
            od_id: parseInt(od_id),
            sr_it_barcode_no: sr_it_barcode_no,
            type: owner.pageMode,
          };

          owner.callAPI(
            owner,
            "WareHouseInOut",
            "updateOrderBarcode",
            sendData,
            (owner, response) => {
              owner.clientAlertFnc(
                owner,
                "변경이 완료되었습니다.",
                "0010",
                false,
                "success"
              );
              owner.removeModalUiWrapperHtml("add_barcode_modal");
            },
            (ownerClass, error, code) => {
              owner.clientAlertFnc(ownerClass, error, code);
              if (code === "0010") {
                owner.removeModalUiWrapperHtml("add_barcode_modal");
              }
            }
          );
        } else {
          return owner.alertFormError("입력할 바코드를 선택해 주세요.");
        }
      });

    // 팝업 - 창고 이동
    $(document)
      .off("click", ".btn_move_rack")
      .on("click", ".btn_move_rack", function (e) {
        e.preventDefault();
        const sr_stock_id = $(this).data("stockId");
        owner.moveRackData =
          owner.outboundListData.find(
            (f) => parseInt(f.sr_stock_id) === parseInt(sr_stock_id)
          ) ?? {};
        const sendData = {
          token: $('input[name="token"]').val(),
          srw_id: owner.srw_id,
          mb_no: owner.mb_no,
          sr_stock_ids: [sr_stock_id],
        };
        owner.getVirtualAndCheckMoveRackStockData(owner, sendData);
      });

    // 팝업 - 창고 이동 submit
    $(document)
      .off("click", "#move_rack_submit")
      .on("click", "#move_rack_submit", function (e) {
        e.preventDefault();
        const svw_id = $("#sel_move_svw_id button").val();
        const selRack = $("#sel_move_svwr_id button");
        const svwr_id = selRack.val();
        const available = selRack.data("available");

        if (!owner.moveRackData?.sr_stock_id) {
          owner.removeModalUiWrapperHtml("move_rack_modal");
          return owner.alertFormError(
            "창고 이동할 정보를 불러오지 못했습니다."
          );
        }

        if (owner.moveRackData.svwr_id === svwr_id) {
          $("#sel_move_svwr_id .select").addClass("error");
          return owner.alertFormError(
            "보관중인 창고와 창고 이동을 선택한 창고가 동일합니다."
          );
        }

        if (available > 0) {
          // 바코드 이동
          const sendData = {
            token: $('input[name="token"]').val(),
            mb_no: owner.mb_no,
            srw_id: owner.srw_id,
            svw_id: parseInt(svw_id),
            svwr_id: parseInt(svwr_id),
            sr_stock_ids: [owner.moveRackData.sr_stock_id],
          };

          owner.callAPI(
            owner,
            "WareHouseStock",
            "updateClientMoveToRack",
            sendData,
            (ownerClass, response) => {
              owner.clientAlertFnc(
                owner,
                "변경이 완료되었습니다.",
                "0010",
                false,
                "success"
              );
              owner.removeModalUiWrapperHtml("move_rack_modal");
            },
            (ownerClass, error, code) => {
              owner.clientAlertFnc(ownerClass, error, code);
              if (code === "0010") {
                owner.removeModalUiWrapperHtml("move_rack_modal");
              }
            }
          );
        } else {
          $("#sel_move_svwr_id .select").addClass("error");
          owner.clientAlertFnc(
            owner,
            "해당 렉의 입고 가능한 수량은 " + available + "건 입니다.",
            "0009"
          );
        }
      });
  }

  pageSetting(owner, response) {
    const outboundList = response?.result_data?.list ?? [];
    const pagination = response?.result_data?.list?.ui_pagination ?? [];
    owner.outboundListData = outboundList?.data ?? [];
    const listHtml = (item) => {
      const ctStatusStyleClass =
        item.ct_status === "출고완료" ? "suces_trans" : "info_trans";
      return `<tr data-row-id='${item?.uniqueId}'>
      <td>${item.it_name}</td>
      <td>${
        item.sr_it_barcode_no && item.barcode_location_status === "warehouse"
          ? `<em><a href="#" data-stock-id="${item.sr_stock_id}" class="btn_move_rack">${item.warehouse_location_text}</a></em>`
          : `${item.warehouse_location_text}`
      }</td>
      <td>${item.sp_display_type ? item.sp_display_type : "-"}</td>
      ${
        owner.pageMode === "install"
          ? `<td>${item.ic_name} ${item.ip_name}<br>${item.ip_phone}</td>`
          : `<td><em><a href="#" class="btn_add_delivery">${item.ct_delivery}</a></em></td>`
      }
      <td>${item.ca_name}</td>
      <td>${item.mb_giup_bname}</td>
      <td>${owner.maskMiddleName(item.od_b_name)}</td>
      <td>${owner.maskPhoneNumber(item.rcv_phone)}</td>
      <td>${owner.maskAddress(item.od_b_addr1)}</td>
      <td title="${item.od_memo}">${item.od_memo ? item.od_memo : "-"}</td>
      <td class="Tcenter"><span class="status_chips ${ctStatusStyleClass}">${
        item.ct_status
      }</span></td></tr>`;
    };

    owner.bindUiPageList({
      id: "outbound_item_fixed_list",
      dataList: owner.outboundListData,
      noneHtml: false,
      customRowHtmlCallback: (item) => {
        const disable =
          item.ct_status === "출고완료"
            ? true
            : owner.pageMode === "install"
            ? !item.sr_it_barcode_no
            : !(item.sr_it_barcode_no && item.ct_delivery !== "입력");
        return `<tr data-row-id='${item?.uniqueId}'>
                    <td class="Tcenter">
                      <div class="DDY_checkBox">
                          <input type="checkbox" name="auto_checkbox[]" id="auto_checkbox_${
                            item?.uniqueId
                          }" value="${item?.uniqueId}" />
                          <label for="auto_checkbox_${item?.uniqueId}"></label>
                      </div>
                    </td>
                    <td>${
                      owner.pageMode === "install"
                        ? item.sr_delivery_hope_dt
                        : item.od_time
                    }</td>
                    <td>${
                      item.sr_it_barcode_no
                        ? `<em><a href="${g5_sub_rental_url}/warehouse/barcode_detail.php?unique_id=${item.sr_stock_id}">${item.sr_it_barcode_no}</a></em>`
                        : `<em><a href="#" class="btn_add_barcode_popup">바코드 입력</a>`
                    }</td>                
                 </tr>`;
      },
    });
    owner.bindUiPageList({
      id: "outbound_item_list",
      dataList: owner.outboundListData,
      noneHtml: false,
      customRowHtmlCallback: (item) => {
        return listHtml(item);
      },
    });
    const paginationHtml = owner.setUiPagination({
      paginationProps: pagination,
    });
    const listSectionObj = $("#outbound_item_list_sec");
    // 데이터 none 박스 제거
    listSectionObj.find(".pagination, .noData, .table_ref").remove();
    if (owner.outboundListData.length === 0) {
      listSectionObj.append(`<div class="noData">
                            <p>데이터가 존재하지 않습니다</p>
                        </div>`);
    }
    if (owner.outboundListData.length !== 0) {
      listSectionObj.append(`<div class="table_ref">
              <p>
                표를 밀어보세요.<img src="${g5_img_url}/subrental/table_text_arrow.svg" alt="표를 밀어보세요">
              </p>
            </div>`);
    }
    listSectionObj.append(paginationHtml);
    owner.doubleTableMatchRowHeight($(".divideTable"));

    const modalMessage =
      "선택한 출고 건이 없습니다.<br>검색결과 내 모든 출고 건을 다운로드 하시겠습니까?";
    // 엑셀 다운 로드
    owner.bindExcelDownload({
      ownerClass: owner,
      selectedListTarget: $("#outbound_item_fixed_list"),
      module: "WareHouseInOut",
      action: "outboundExcelDownload",
      dataList: owner.outboundListData,
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
      ...owner.searchStatus,
      type: owner.pageMode,
    };

    owner.outboundListData = [];
    owner.moveRackData = {};

    owner.callAPI(
      owner,
      "WareHouseInOut",
      "getClientOutBoundList",
      sendData,
      (owner, response) => {
        owner.pageSetting(owner, response);
      },
      (ownerClass, error, code) => owner.clientAlertFnc(ownerClass, error, code)
    );
  }

  drawInstallDoubleListHeader() {
    $("#outbound_item_fixed_list").remove();
    $("#outbound_item_list").remove();
    const colList = [
      { colWidth: "150px", txt: "상품명", colName: "it_name" },
      { colWidth: "150px", txt: "창고위치", colName: "barcode_location_status" },
      { colWidth: "80px", txt: "비고", colName: "sp_display_type" },
      { colWidth: "170px", txt: "설치 업체", colName: "install_company" },
      { colWidth: "120px", txt: "상품 유형", colName: "ca_name" },
      {
        colWidth: "150px",
        txt: "계약 사업소",
        colName: "mb_giup_bname",
      },
      { colWidth: "150px", txt: "수급자", colName: "od_b_name" },
      { colWidth: "150px", txt: "전화번호", colName: "rcv_phone" },
      { colWidth: "150px", txt: "주소", colName: "od_b_addr1" },
      {
        colWidth: "260px",
        txt: "배송요청사항",
        colName: "od_memo",
      },
      {
        colWidth: "120px",
        txt: "상태",
        colName: "style-button",
        btnText: "확인",
        className: "Tcenter",
      },
    ];
    this.drawUiDoubleList({
      target: $("#outbound_item_list_sec"),
      fixedTableId: "outbound_item_fixed_list",
      fixedTableWidthPer: 30,
      fixedTableColList: this.installFixedTableColList,
      tableId: "outbound_item_list",
      colList,
    });
  }

  drawParcelDoubleListHeader() {
    $("#outbound_item_fixed_list").remove();
    $("#outbound_item_list").remove();
    const colList = [
      { colWidth: "150px", txt: "상품명", colName: "it_name" },
      { colWidth: "150px", txt: "창고위치", colName: "barcode_location_status" },
      { colWidth: "80px", txt: "비고", colName: "sp_display_type" },
      { colWidth: "170px", txt: "택배운송장", colName: "delivery" },
      { colWidth: "120px", txt: "상품 유형", colName: "ca_name" },
      {
        colWidth: "150px",
        txt: "계약 사업소",
        colName: "mb_giup_bname",
      },
      { colWidth: "150px", txt: "수급자", colName: "od_b_name" },
      { colWidth: "150px", txt: "전화번호", colName: "rcv_phone" },
      { colWidth: "150px", txt: "주소", colName: "od_b_addr1" },
      {
        colWidth: "260px",
        txt: "배송요청사항",
        colName: "od_memo",
      },
      {
        colWidth: "120px",
        txt: "상태",
        colName: "style-button",
        btnText: "확인",
        className: "Tcenter",
      },
    ];
    this.drawUiDoubleList({
      target: $("#outbound_item_list_sec"),
      fixedTableId: "outbound_item_fixed_list",
      fixedTableWidthPer: 30,
      fixedTableColList: this.parcelFixedTableColList,
      tableId: "outbound_item_list",
      colList,
    });
  }
}
