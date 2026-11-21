/**
 * Detalle dinamico de presupuesto (nueva actividad).
 */
(function () {
  'use strict';

  const SELECT_OPTIONS = window.BUDGET_ACCOUNT_OPTIONS || [];

  function optionHtml(selectedId) {
    return SELECT_OPTIONS.map(function (opt) {
      const selected = selectedId == opt.id ? ' selected' : '';
      const label = opt.label || opt.name || '';
      return '<option value="' + (opt.id || '') + '"' + selected + '>' + label + '</option>';
    }).join('');
  }

  function init(container) {
    if (!container) return;
    const tbody = container.querySelector('tbody');
    const btnAdd = container.querySelector('[data-add-row]');
    const totalEl = container.querySelector('[data-grand-total]');
    if (!tbody || !btnAdd) return;

    let rowIndex = tbody.querySelectorAll('.detail-row').length;

    function formatNumber(n) {
      return Number(n).toLocaleString('es-CL');
    }

    function updateRowTotal(row) {
      const q = parseFloat(row.querySelector('.quantity').value) || 0;
      const p = parseFloat(row.querySelector('.unit-price').value) || 0;
      const total = q * p;
      const totalCell = row.querySelector('.row-total');
      if (totalCell) totalCell.textContent = formatNumber(total);
      return total;
    }

    function updateGrandTotal() {
      let sum = 0;
      tbody.querySelectorAll('.detail-row').forEach(function (row) {
        sum += updateRowTotal(row);
      });
      if (totalEl) totalEl.textContent = formatNumber(sum);
    }

    function bindRemove(tr) {
      const btn = tr.querySelector('.btn-remove');
      if (!btn) return;
      btn.addEventListener('click', function () {
        if (tbody.querySelectorAll('.detail-row').length <= 1) return;
        tr.remove();
        updateGrandTotal();
      });
    }

    function bindQtyPrice(tr) {
      tr.querySelectorAll('.quantity, .unit-price').forEach(function (input) {
        input.addEventListener('input', updateGrandTotal);
      });
    }

    function addRow(data) {
      const tr = document.createElement('tr');
      tr.className = 'detail-row';
      tr.innerHTML =
        '<td><select name="details[' + rowIndex + '][account_code_id]" required><option value="">Seleccione</option>' +
        optionHtml(data && data.account_code_id) +
        '</select></td>' +
        '<td class="col-qty"><input type="number" step="0.01" min="0" name="details[' + rowIndex + '][quantity]" class="quantity" value="' +
        (data && data.quantity !== undefined ? data.quantity : '1') +
        '" required></td>' +
        '<td class="col-unit"><input type="number" step="0.01" min="0" name="details[' + rowIndex + '][unit_price]" class="unit-price" value="' +
        (data && data.unit_price !== undefined ? data.unit_price : '0') +
        '" required></td>' +
        '<td class="num"><span class="row-total">0</span></td>' +
        '<td><input type="text" name="details[' + rowIndex + '][observation]" maxlength="500" value="' +
        (data && data.observation ? String(data.observation).replace(/"/g, '&quot;') : '') +
        '" required></td>' +
        '<td><button type="button" class="btn-remove btn">Quitar</button></td>';
      tbody.appendChild(tr);
      rowIndex++;
      bindRemove(tr);
      bindQtyPrice(tr);
      updateGrandTotal();
    }

    btnAdd.addEventListener('click', function () {
      addRow({});
    });

    tbody.querySelectorAll('.detail-row').forEach(function (row) {
      bindRemove(row);
      bindQtyPrice(row);
    });
    tbody.addEventListener('input', function (e) {
      if (e.target.matches('.quantity, .unit-price')) updateGrandTotal();
    });
    updateGrandTotal();
  }

  document.querySelectorAll('[data-budget-detail]').forEach(init);
})();
