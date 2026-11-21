/* global jQuery, OC_NEW_CONFIG */
jQuery(function ($) {
  var cfg = window.OC_NEW_CONFIG || {};
  var lbl = cfg.labels || {};
  var labelNCta = lbl.nCta || 'N\u00BA Cta:';
  var budgetYear = cfg.budgetYear || new Date().getFullYear();
  var csrf = cfg.csrf || '';
  var lineIx = cfg.lineIxStart || 0;
  var pickIx = cfg.pickIxStart || 0;
  var ocFormId = 'oc-form';

  /** Campos ocultos en el form (POST); la tabla solo edita en pantalla. */
  function rebuildLineHiddenInputs() {
    var $store = $('#oc-lines-hidden-store');
    if (!$store.length) {
      return;
    }
    $store.empty();
    var i = 0;
    $('#lines_body tr').each(function () {
      var $tr = $(this);
      var code = String($tr.find('.line-code').val() || '').trim();
      var name = String($tr.find('.line-name').val() || '').trim();
      var qty = String($tr.find('.line-qty').val() || '1');
      var price = String($tr.find('.line-price').val() || '0');
      if (code === '' && name === '') {
        return;
      }
      $store.append(
        $('<input type="hidden">').attr('name', 'lines[' + i + '][product_code]').val(code)
      );
      $store.append(
        $('<input type="hidden">').attr('name', 'lines[' + i + '][product_name]').val(name)
      );
      $store.append($('<input type="hidden">').attr('name', 'lines[' + i + '][qty]').val(qty));
      $store.append(
        $('<input type="hidden">').attr('name', 'lines[' + i + '][unit_price]').val(price)
      );
      i++;
    });
  }

  function esc(s) {
    return $('<div>').text(s == null ? '' : String(s)).html();
  }

  function openModal(sel) {
    if (typeof $.fn.modal !== 'function') {
      window.alert('Bootstrap no esta disponible. Compruebe su conexion o bloqueadores de anuncios.');
      return;
    }
    $(sel).modal({ backdrop: true, keyboard: true, show: true });
  }

  var OC_TAX_PERCENT = 19;

  function money0(n) {
    if (isNaN(n)) {
      return '';
    }
    return Math.round(n).toLocaleString('en-US');
  }

  function money2(n) {
    if (isNaN(n)) {
      return '';
    }
    var x = Math.round(n * 100) / 100;
    var parts = x.toFixed(2).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts[0] + '.' + parts[1];
  }

  function parseNum(v) {
    if (v == null || v === '') {
      return NaN;
    }
    return parseFloat(String(v).replace(/\s/g, '').replace(/,/g, ''));
  }

  function ensureResultadosTable() {
    var $r = $('#resultados');
    if ($r.find('#lines_table').length) {
      return;
    }
    $r.html(
      '<div class="table-responsive">' +
        '<table class="table table-bordered" id="lines_table">' +
        '<thead><tr>' +
        '<th class="text-center">CODIGO</th>' +
        '<th class="text-center">CANT.</th>' +
        '<th>DESCRIPCION</th>' +
        '<th class="text-right">PRECIO UNIT.</th>' +
        '<th class="text-right">PRECIO TOTAL</th>' +
        '<th></th>' +
        '</tr></thead>' +
        '<tbody id="lines_body"></tbody>' +
        '<tfoot id="oc_lines_tfoot">' +
        '<tr><td class="text-right" colspan="4">SUBTOTAL $</td><td class="text-right" id="oc_subtotal_val"></td><td></td></tr>' +
        '<tr><td class="text-right" colspan="4" id="oc_tax_cell_label">IVA (' + OC_TAX_PERCENT + '%)</td>' +
        '<td class="text-right" id="oc_iva_val"></td><td></td></tr>' +
        '<tr><td class="text-right" colspan="4">TOTAL $</td><td class="text-right" id="oc_total_val"></td><td></td></tr>' +
        '</tfoot></table></div>'
    ).show();
  }

  function ocCodeIsServicioBoleta(code) {
    var c = String(code || '').trim();
    if (!c) {
      return false;
    }
    return /^servicio\s+boleta_/i.test(c) || /^servicio_boleta_/i.test(c);
  }

  function ocNameIsServicioBoleta(name) {
    var n = String(name || '').trim();
    if (!n) {
      return false;
    }
    return /^servicio\s+boleta_/i.test(n) || /^servicio_boleta_/i.test(n);
  }

  function ocLineIsServicioBoleta(code, name) {
    return ocCodeIsServicioBoleta(code) || ocNameIsServicioBoleta(name);
  }

  function ocBoletaRetencionRateFromName(n) {
    n = String(n || '');
    var m = n.match(/\(\s*([0-9]+[.,]?[0-9]*)\s*%?\s*Impto\.?\s*Retenido/i);
    if (m) {
      return parseFloat(String(m[1]).replace(',', '.'));
    }
    m = n.match(/servicio\s+boleta_([0-9]+[.,]?[0-9]*)\s*%/i);
    if (m) {
      return parseFloat(String(m[1]).replace(',', '.'));
    }
    m = n.match(/servicio_boleta_([0-9]+[.,]?[0-9]*)\s*%/i);
    if (m) {
      return parseFloat(String(m[1]).replace(',', '.'));
    }
    return 12.25;
  }

  function ocFormatRatePrint(r) {
    var x = Math.round(r * 10000) / 10000;
    return String(x).replace(/0+$/, '').replace(/\.$/, '');
  }

  /** Alineado con src/oc_tax_profile.php */
  function ocDetectTaxProfile() {
    var lines = [];
    $('#lines_body tr').each(function () {
      var $tr = $(this);
      lines.push({
        code: ($tr.find('.line-code').val() || '').trim(),
        name: ($tr.find('.line-name').val() || '').trim()
      });
    });
    var names = lines.map(function (l) { return l.name; }).filter(function (n) { return n !== ''; });

    if (names.length) {
      var allExento = names.every(function (n) {
        return /exento\s+de\s+IVA/i.test(n);
      });
      if (allExento) {
        return { mode: 'exento', rate: 0 };
      }
    }

    if (lines.length > 0 && lines.every(function (l) {
      return ocLineIsServicioBoleta(l.code, l.name);
    })) {
      var ratesBoleta = lines.map(function (l) {
        return ocBoletaRetencionRateFromName(l.name);
      });
      var rb0 = ratesBoleta[0];
      if (ratesBoleta.every(function (r) { return Math.abs(r - rb0) < 0.001; })) {
        return { mode: 'retencion', rate: rb0 };
      }
      return { mode: 'iva', rate: OC_TAX_PERCENT };
    }

    if (!names.length) {
      return { mode: 'iva', rate: OC_TAX_PERCENT };
    }

    var rates = names.map(function (n) {
      var m = n.match(/\(\s*([0-9]+[.,]?[0-9]*)\s*%?\s*Impto\.?\s*Retenido/i);
      if (m) {
        return parseFloat(String(m[1]).replace(',', '.'));
      }
      if (ocNameIsServicioBoleta(n)) {
        return ocBoletaRetencionRateFromName(n);
      }
      return null;
    });
    if (rates.length && rates.every(function (r) { return r !== null; })) {
      var r0 = rates[0];
      if (rates.every(function (r) { return Math.abs(r - r0) < 0.001; })) {
        return { mode: 'retencion', rate: r0 };
      }
    }
    return { mode: 'iva', rate: OC_TAX_PERCENT };
  }

  function updateLineTotal($tr) {
    var q = parseNum($tr.find('.line-qty').val());
    var p = parseNum($tr.find('.line-price').val());
    var t = (isNaN(q) ? 0 : q) * (isNaN(p) ? 0 : p);
    $tr.find('.line-total').text(money2(t));
    refreshDocTotals();
  }

  function getOcLineSubtotal() {
    var sub = 0;
    $('#lines_body tr').each(function () {
      var q = parseNum($(this).find('.line-qty').val());
      var p = parseNum($(this).find('.line-price').val());
      sub += (isNaN(q) ? 0 : q) * (isNaN(p) ? 0 : p);
    });
    return Math.round(sub * 100) / 100;
  }

  function refreshDocTotals() {
    var sub = getOcLineSubtotal();
    var prof = ocDetectTaxProfile();
    var taxAmt;
    var total;
    var labelCell;
    if (prof.mode === 'exento') {
      taxAmt = 0;
      total = sub;
      labelCell = 'IVA (exento)';
    } else if (prof.mode === 'retencion') {
      taxAmt = Math.round((sub * prof.rate / 100) * 100) / 100;
      total = Math.round((sub - taxAmt) * 100) / 100;
      labelCell = 'Impto. Retenido ( ' + ocFormatRatePrint(prof.rate) + '%) $';
    } else {
      taxAmt = Math.round(sub * OC_TAX_PERCENT / 100);
      total = Math.round(sub + taxAmt);
      labelCell = 'IVA (' + OC_TAX_PERCENT + '%)';
    }
    $('#oc_subtotal_val').text(money0(sub));
    $('#oc_tax_cell_label').text(labelCell);
    $('#oc_iva_val').text(money0(taxAmt));
    $('#oc_total_val').text(money0(total));
    refreshAllocHint();
  }

  function refreshAllocHint() {
    var al = 0;
    $('#resultadosPspto .alloc-amount').each(function () {
      al += parseNum($(this).val()) || 0;
    });
    al = Math.round(al * 100) / 100;
    var sub = getOcLineSubtotal();
    var hasLines = $('#lines_body tr').length > 0;
    var $hint = $('#alloc-hint');
    if (!$hint.length) {
      $('#resultadosPspto').prepend('<p id="alloc-hint" class="text-muted small"></p>');
      $hint = $('#alloc-hint');
    }
    if (al <= 0) {
      $hint.text('Impute actividades desde Buscar Actividades.').css('color', '#666');
      return;
    }
    if (!hasLines) {
      $hint.text('Total imputado: ' + money0(al) + '.').css('color', '#666');
      return;
    }
    if (sub <= 0) {
      $hint.text('Revise cantidad y precio en las lineas de producto.').css('color', '#c00');
      return;
    }
    $hint.text(
      'Total imputado: ' + money0(al) + '. Suma lineas producto: ' + money0(sub) + '.'
    ).css('color', '#666');
  }

  function bindLineRow($tr) {
    $tr.find('input').on('input change', function () {
      updateLineTotal($tr);
      rebuildLineHiddenInputs();
    });
    updateLineTotal($tr);
  }

  function addLine(code, name, qty, price) {
    ensureResultadosTable();
    lineIx++;
    var $tr = $('<tr></tr>');
    $tr.html(
      '<td><input type="text" class="form-control input-sm line-code" value="' + esc(code) + '"></td>' +
      '<td><input type="number" step="0.01" class="form-control input-sm line-qty" value="' + esc(qty || '1') + '"></td>' +
      '<td><input type="text" class="form-control input-sm line-name" value="' + esc(name) + '"></td>' +
      '<td><input type="number" step="0.01" class="form-control input-sm line-price" value="' + esc(price || '0') + '"></td>' +
      '<td class="text-right line-total">0</td>' +
      '<td><button type="button" class="btn btn-xs btn-danger oc-remove-line">&times;</button></td>'
    );
    $('#lines_body').append($tr);
    bindLineRow($tr);
    rebuildLineHiddenInputs();
  }

  function initExistingLineRows() {
    var $rows = $('#lines_body tr');
    if (!$rows.length) {
      return false;
    }
    $('#resultados').show();
    $rows.each(function () {
      bindLineRow($(this));
    });
    rebuildLineHiddenInputs();
    refreshDocTotals();
    return true;
  }

  function initExistingBudgetPicks() {
    var $picks = $('#resultadosPspto .budget-pick');
    if (!$picks.length) {
      return;
    }
    $picks.find('.budget-pick-remove').on('click', function () {
      $(this).closest('.budget-pick').remove();
      refreshAllocHint();
      refreshDocTotals();
    });
    $picks.find('.alloc-amount').on('input', function () {
      refreshAllocHint();
      refreshDocTotals();
    });
    refreshAllocHint();
    refreshDocTotals();
  }

  function bindProductPicks($box) {
    $box.find('.btn-pick-product').on('click', function () {
      var $btn = $(this);
      var pid = $btn.data('pid');
      var qtyEl = document.getElementById('cantidad_' + pid);
      var priceEl = document.getElementById('precio_venta_' + pid);
      var qty = parseNum(qtyEl ? qtyEl.value : '1');
      var price = parseNum(priceEl ? priceEl.value : '0');
      if (isNaN(qty) || qty <= 0) {
        window.alert('Indique una cantidad valida mayor a cero.');
        if (qtyEl) {
          qtyEl.focus();
        }
        return;
      }
      if (isNaN(price) || price < 0) {
        window.alert('Indique un precio valido.');
        if (priceEl) {
          priceEl.focus();
        }
        return;
      }
      addLine($btn.data('code'), $btn.data('name'), String(qty), String(price));
    });
  }

  function bindModalPagination($box, pageClass, onPage) {
    $box.find('.' + pageClass).on('click', function (e) {
      e.preventDefault();
      onPage(parseInt($(this).data('page'), 10) || 1);
    });
  }

  function ocLoadProducts(page) {
    page = page || 1;
    var q = $('#q').val() || '';
    $('#loader_prod').show().text('Cargando...');
    fetch('ajax/products_search.php?pick=1&page=' + page + '&year=' + budgetYear + '&q=' + encodeURIComponent(q))
      .then(function (r) {
        if (!r.ok) {
          throw new Error('HTTP ' + r.status);
        }
        return r.text();
      })
      .then(function (html) {
        var $box = $('#myModal .outer_div');
        $box.html(html);
        $('#loader_prod').hide();
        bindProductPicks($box);
        bindModalPagination($box, 'btn-page', ocLoadProducts);
      })
      .catch(function () {
        $('#loader_prod').hide();
        $('#myModal .outer_div').html('<p class="text-danger">Error al cargar productos.</p>');
      });
  }

  $('#btn-product-search').on('click', function () { ocLoadProducts(1); });
  $('#q').on('keyup', function () {
    clearTimeout(window._prodDebounce);
    window._prodDebounce = setTimeout(function () { ocLoadProducts(1); }, 350);
  });

  $('#btn_add_line').on('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    openModal('#myModal');
  });
  $('#myModal').on('shown.bs.modal', function () {
    $('#q').val('').focus();
    ocLoadProducts(1);
  });

  function setSupplier(id, name, phone, email) {
    $('#id_cliente').val(id ? String(id) : '');
    $('#nombre_cliente').val(name || '').data('oc-supplier-label', name || '');
    $('#tel1').val(phone || '');
    $('#mail').val(email || '');
  }

  function clearSupplierLink() {
    $('#id_cliente').val('');
    $('#tel1').val('');
    $('#mail').val('');
    $('#nombre_cliente').removeData('oc-supplier-label');
  }

  function setProveedorHint(msg, isErr) {
    var $h = $('#proveedor-hint');
    if (!$h.length) {
      return;
    }
    $h.text(msg || '').css('color', isErr ? '#c00' : '#666');
  }

  function bindSupplierPicks($box) {
    $box.find('.btn-pick-supplier').on('click', function () {
      var $btn = $(this);
      setSupplier(
        $btn.data('id'),
        $btn.data('name') || $btn.data('label'),
        $btn.data('phone'),
        $btn.data('email')
      );
      setProveedorHint('Proveedor seleccionado.', false);
      $('#myModal_prov').modal('hide');
    });
    $box.find('.btn-sup-page').on('click', function (e) {
      e.preventDefault();
      ocLoadSuppliers(parseInt($(this).data('page'), 10) || 1);
    });
  }

  function ocLoadSuppliers(page) {
    page = page || 1;
    var q = $('#prov_q').val() || '';
    $('#loader_supplier_pick').show().text('Cargando...');
    fetch(
      'ajax/suppliers_search.php?pick=1&page=' + page + '&year=' + budgetYear + '&q=' + encodeURIComponent(q)
    )
      .then(function (r) {
        if (!r.ok) {
          throw new Error('HTTP ' + r.status);
        }
        return r.text();
      })
      .then(function (html) {
        $('#proveedor_holder').html(html);
        $('#loader_supplier_pick').hide();
        bindSupplierPicks($('#proveedor_holder'));
      })
      .catch(function () {
        $('#loader_supplier_pick').hide();
        $('#proveedor_holder').html('<p class="text-danger">Error al cargar proveedores.</p>');
      });
  }

  function initSupplierAutocomplete() {
    var $field = $('#nombre_cliente');
    if (!$field.length) {
      return;
    }
    if ($.fn.autocomplete && $field.autocomplete('instance')) {
      $field.autocomplete('destroy');
    }
    $field.off('.ocSupplierAc');
    $(document).off('.ocSupplierAc');

    var $wrap = $field.closest('.col-md-3');
    $wrap.css('position', 'relative');
    var $menu = $('#oc-supplier-ac-menu');
    if (!$menu.length) {
      $menu = $('<ul id="oc-supplier-ac-menu" class="oc-supplier-ac-menu"></ul>');
      $wrap.append($menu);
    }

    function hideSupplierMenu() {
      $menu.hide().empty();
    }

    function showSupplierItems(items) {
      $menu.empty();
      if (!items || !items.length) {
        $menu.append('<li class="oc-supplier-ac-empty">Sin coincidencias</li>');
      } else {
        items.forEach(function (it) {
          var label = it.label || it.nombre_cliente || it.value || '';
          $('<li class="oc-supplier-ac-item"></li>')
            .text(label)
            .data('pick', it)
            .appendTo($menu);
        });
      }
      $menu.show();
    }

    function fetchSupplierSuggestions(term) {
      $.getJSON('ajax/suppliers_autocomplete.php', { term: term })
        .done(function (data) {
          if (!Array.isArray(data)) {
            setProveedorHint('Error al buscar. Use la lupa.', true);
            hideSupplierMenu();
            return;
          }
          if (data.length === 0) {
            setProveedorHint('Sin coincidencias.', true);
          } else {
            setProveedorHint('');
          }
          showSupplierItems(data);
        })
        .fail(function (xhr) {
          hideSupplierMenu();
          if (xhr.status === 401 || xhr.status === 403) {
            setProveedorHint('Sesion expirada.', true);
          } else {
            setProveedorHint('Error al buscar proveedores.', true);
          }
        });
    }

    $field.on('input.ocSupplierAc', function () {
      var typed = String($field.val() || '').trim();
      if (typed === '') {
        clearSupplierLink();
        setProveedorHint('');
        hideSupplierMenu();
        return;
      }
      if ($('#id_cliente').val()) {
        var saved = String($field.data('oc-supplier-label') || '').trim();
        if (typed !== saved) {
          clearSupplierLink();
        }
      }
      if (typed.length < 2) {
        hideSupplierMenu();
        return;
      }
      clearTimeout(window._supAcDebounce);
      window._supAcDebounce = setTimeout(function () {
        fetchSupplierSuggestions(typed);
      }, 200);
    });

    $field.on('focus.ocSupplierAc', function () {
      var typed = String($field.val() || '').trim();
      if (typed.length >= 2) {
        fetchSupplierSuggestions(typed);
      }
    });

    $menu.on('mousedown.ocSupplierAc', '.oc-supplier-ac-item', function (e) {
      e.preventDefault();
      var it = $(this).data('pick');
      if (it && it.id_cliente) {
        setSupplier(it.id_cliente, it.nombre_cliente, it.telefono_cliente, it.email_cliente);
        setProveedorHint('Proveedor seleccionado.', false);
        hideSupplierMenu();
      }
    });

    $(document).on('click.ocSupplierAc', function (e) {
      if (!$(e.target).closest($wrap).length) {
        hideSupplierMenu();
      }
    });
  }

  initSupplierAutocomplete();

  $('#btn-buscar-proveedor').on('click', function (e) {
    e.preventDefault();
    openModal('#myModal_prov');
  });
  $('#btn-supplier-search').on('click', function () { ocLoadSuppliers(1); });
  $('#prov_q').on('keyup', function () {
    clearTimeout(window._supPickDebounce);
    window._supPickDebounce = setTimeout(function () { ocLoadSuppliers(1); }, 350);
  });
  $('#myModal_prov').on('shown.bs.modal', function () {
    $('#prov_q').val('').focus();
    ocLoadSuppliers(1);
  });

  $('#btn-save-supplier').on('click', function () {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('legal_name', $('#prov-legal').val() || '');
    fd.append('trade_name', $('#prov-trade').val() || '');
    fd.append('phone', $('#prov-phone').val() || '');
    fd.append('email', $('#prov-email').val() || '');
    fetch('ajax/quick_supplier.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) {
          $('#resultados_ajax').html('<p class="text-danger">' + esc(d.error) + '</p>');
          return;
        }
        setSupplier(d.id, d.nombre_cliente, d.telefono_cliente, d.email_cliente);
        $('#nuevoCliente').modal('hide');
        $('#guardar_cliente')[0].reset();
        $('#resultados_ajax').html('<p class="text-success">Proveedor guardado.</p>');
      });
  });

  function addBudgetPick(did, act, acct, ocupar, lineRem, hdrRem, reqOverride) {
    var ix = pickIx++;
    var reqDef = reqOverride != null && String(reqOverride) !== ''
      ? String(reqOverride)
      : ($('#id_vendedor').val() || '');
    var $card = $(
      '<div class="panel panel-default budget-pick" style="margin-bottom:8px;">' +
        '<div class="panel-body">' +
        '<input type="hidden" form="' + ocFormId + '" name="budget[' + ix + '][detail_id]" value="' + esc(did) + '">' +
        '<strong>' + esc(act) + '</strong> \u2014 ' + esc(acct) + '<br>' +
        '<small>Saldo linea: ' + esc(lineRem) + ' | actividad: ' + esc(hdrRem) + '</small>' +
        '<div class="row" style="margin-top:8px;">' +
        '<div class="col-sm-4"><label>Monto</label>' +
        '<input class="form-control input-sm alloc-amount" form="' + ocFormId + '" name="budget[' + ix + '][amount]" type="number" step="0.01" min="0.01" max="' + esc(ocupar) + '" value="' + esc(ocupar) + '" required></div>' +
        '<div class="col-sm-4"><label>N\u00BA req</label>' +
        '<input class="form-control input-sm" form="' + ocFormId + '" name="budget[' + ix + '][req_number]" maxlength="64" value="' + esc(reqDef) + '"></div>' +
        '<div class="col-sm-4" style="padding-top:22px;"><button type="button" class="btn btn-xs btn-danger budget-pick-remove">Quitar</button></div>' +
        '</div></div></div>'
    );
    $('#resultadosPspto').append($card);
    $card.find('.budget-pick-remove').on('click', function () {
      $card.remove();
      refreshAllocHint();
      refreshDocTotals();
    });
    $card.find('.alloc-amount').on('input', function () {
      refreshAllocHint();
      refreshDocTotals();
    });
    refreshDocTotals();
  }

  function bindBudgetPicks($box) {
    $box.find('.btn-pick-budget').on('click', function () {
      var btn = this;
      var did = btn.getAttribute('data-detail-id');
      var amtEl = document.getElementById('precio_venta_' + did);
      var maxVal = parseNum(btn.getAttribute('data-ocupar-max'));
      var amt = amtEl ? parseNum(amtEl.value) : maxVal;
      if (isNaN(amt) || amt <= 0) {
        window.alert('Indique un monto valido mayor a cero en Ocupar.');
        if (amtEl) {
          amtEl.focus();
        }
        return;
      }
      if (!isNaN(maxVal) && amt > maxVal + 0.05) {
        window.alert('El monto no puede superar el saldo disponible (' + money0(maxVal) + ').');
        if (amtEl) {
          amtEl.focus();
        }
        return;
      }
      addBudgetPick(
        did,
        btn.getAttribute('data-activity'),
        btn.getAttribute('data-account'),
        String(amt),
        btn.getAttribute('data-line-rem'),
        btn.getAttribute('data-header-rem')
      );
    });
  }

  function ocLoadBudgetActv() {
    var q = $('#qq').val() || '';
    $('#loader_actv').show().text('Cargando...');
    fetch('ajax_budget.php?q=' + encodeURIComponent(q) + '&year=' + budgetYear)
      .then(function (r) {
        if (!r.ok) {
          throw new Error('HTTP ' + r.status);
        }
        return r.text();
      })
      .then(function (html) {
        $('#page_holder').html(html);
        $('#loader_actv').hide();
        bindBudgetPicks($('#page_holder'));
      })
      .catch(function () {
        $('#loader_actv').hide();
        $('#page_holder').html('<p class="text-danger">Error al cargar actividades.</p>');
      });
  }

  $('#btn-budget-search').on('click', ocLoadBudgetActv);
  $('#qq').on('keyup', function () {
    clearTimeout(window._budgetDebounce);
    window._budgetDebounce = setTimeout(ocLoadBudgetActv, 350);
  });

  $('#btn_actv').on('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    openModal('#myModal_actv');
  });
  $('#myModal_actv').on('shown.bs.modal', function () {
    $('#qq').val('').focus();
    ocLoadBudgetActv();
  });

  if ($.fn.autocomplete) {
    $('#qq').autocomplete({
      minLength: 2,
      appendTo: '#myModal_actv .modal-body',
      source: function (request, response) {
        $.getJSON('ajax/budget_autocomplete.php', { term: request.term, year: budgetYear }, response);
      },
      select: function (event, ui) {
        event.preventDefault();
        addBudgetPick(
          String(ui.item.detail_id),
          ui.item.activity,
          ui.item.account,
          String(ui.item.ocupar),
          String(ui.item.line_rem),
          String(ui.item.header_rem)
        );
        $('#qq').val('');
      }
    });
  }

  $('#resultados').on('click', '.oc-remove-line', function () {
    $(this).closest('tr').remove();
    if ($('#lines_body tr').length === 0) {
      $('#resultados').hide().empty();
      $('#oc-lines-hidden-store').empty();
    } else {
      refreshDocTotals();
      rebuildLineHiddenInputs();
    }
  });

  $('#oc-form').on('submit', function (e) {
    rebuildLineHiddenInputs();
    $('.oc-cta-select').prop('disabled', false);
    if (!$('#nro_cta').val()) {
      e.preventDefault();
      alert('Seleccione al menos la primera cuenta (' + labelNCta + ').');
      $('#nro_cta').focus();
      return false;
    }
    if (!$('#id_cliente').val()) {
      e.preventDefault();
      alert('Debe seleccionar un proveedor.');
      $('#nombre_cliente').focus();
      return false;
    }
    if ($('#lines_table').length === 0 || $('#lines_body tr').length === 0) {
      e.preventDefault();
      alert('Agregue al menos un producto.');
      return false;
    }
    if ($('#oc-lines-hidden-store input').length === 0) {
      e.preventDefault();
      alert('No se pudieron enviar las lineas de producto. Vuelva a agregar el producto.');
      return false;
    }
    if ($('#resultadosPspto .budget-pick').length === 0) {
      e.preventDefault();
      alert('Debe imputar al menos una actividad de presupuesto.');
      return false;
    }
    return true;
  });

  if (cfg.supplierId) {
    setSupplier(cfg.supplierId, cfg.supplierName, cfg.supplierPhone, cfg.supplierEmail);
  }

  var ctaSelects = [
    { el: '#nro_cta', emptyValue: '', emptyLabel: 'Seleccione', selected: cfg.nroCta || '' },
    { el: '#nro_cta2', emptyValue: '0', emptyLabel: '', selected: cfg.nroCta2 || '0' },
    { el: '#nro_cta3', emptyValue: '0', emptyLabel: '', selected: cfg.nroCta3 || '0' },
    { el: '#nro_cta4', emptyValue: '0', emptyLabel: '', selected: cfg.nroCta4 || '0' }
  ];

  function fillCtaSelect($sel, items, spec) {
    var html = '';
    if (spec.emptyLabel !== '') {
      html += '<option value="' + esc(spec.emptyValue) + '">' + esc(spec.emptyLabel) + '</option>';
    } else {
      html += '<option value="' + esc(spec.emptyValue) + '"></option>';
    }
    items.forEach(function (it) {
      html += '<option value="' + esc(String(it.id)) + '">' + esc(it.label) + '</option>';
    });
    $sel.html(html).prop('disabled', false);
    if (spec.selected !== '' && spec.selected !== '0') {
      $sel.val(String(spec.selected));
    } else if (spec.emptyValue !== '') {
      $sel.val(spec.emptyValue);
    }
  }

  function loadAccountSelects() {
    fetch('ajax/account_options.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          $('#cuentas-alert').text('No se pudieron cargar las cuentas contables.').show();
          $('.oc-cta-select').html('<option value="">Error</option>');
          return;
        }
        if (!data.items || !data.items.length) {
          $('#cuentas-alert').html(
            'No hay cuentas contables para este tenant. Importe con ' +
            '<code>php database/import_plan_cuenta.php</code> o Admin &rarr; Cuentas contables.'
          ).show();
          ctaSelects.forEach(function (spec) {
            $(spec.el).html('<option value="">Sin cuentas</option>').prop('disabled', true);
          });
          return;
        }
        $('#cuentas-alert').hide();
        ctaSelects.forEach(function (spec) {
          fillCtaSelect($(spec.el), data.items, spec);
        });
      })
      .catch(function () {
        $('#cuentas-alert').text('Error de red al cargar cuentas contables.').show();
        $('.oc-cta-select').prop('disabled', true);
      });
  }

  if (!initExistingLineRows()) {
    (cfg.initialLines || []).forEach(function (ln) {
      addLine(
        ln.product_code || '',
        ln.product_name || '',
        String(ln.qty != null ? ln.qty : 1),
        String(ln.unit_price != null ? ln.unit_price : 0)
      );
    });
  }

  initExistingBudgetPicks();
  if (!$('#resultadosPspto .budget-pick').length) {
    (cfg.initialBudget || []).forEach(function (b) {
      addBudgetPick(
        String(b.detail_id),
        b.activity || '',
        b.account || '',
        String(b.amount != null ? b.amount : ''),
        String(b.line_rem != null ? b.line_rem : ''),
        String(b.header_rem != null ? b.header_rem : ''),
        b.req_number || ''
      );
      var $lastAmt = $('#resultadosPspto .budget-pick').last().find('.alloc-amount');
      if ($lastAmt.length && b.amount != null) {
        $lastAmt.val(String(b.amount));
      }
    });
  }
  if ($('#lines_body tr').length || $('#resultadosPspto .budget-pick').length) {
    refreshAllocHint();
    refreshDocTotals();
  }

  loadAccountSelects();
});
