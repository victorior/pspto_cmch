(function () {
  'use strict';

  var btn = document.querySelector('.js-export-activities');
  if (!btn) {
    return;
  }

  var modal = document.getElementById('export-modal');
  var progressBar = document.getElementById('export-progress-bar');
  var progressText = document.getElementById('export-progress-text');
  var progressDetail = document.getElementById('export-progress-detail');
  var subtitle = document.getElementById('export-modal-subtitle');
  var title = document.getElementById('export-modal-title');
  var exporting = false;

  function setProgress(pct, detail) {
    var clamped = Math.max(0, Math.min(100, pct));
    if (progressBar) {
      progressBar.style.width = clamped + '%';
    }
    if (progressText) {
      progressText.textContent = Math.round(clamped) + '%';
    }
    if (progressDetail && detail) {
      progressDetail.textContent = detail;
    }
  }

  function openModal() {
    if (!modal) {
      return;
    }
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('export-modal-open');
    if (title) {
      title.textContent = 'Preparando exportacion';
    }
    if (subtitle) {
      subtitle.textContent = 'Ano presupuestario completo — un momento por favor.';
    }
    setProgress(0, 'Consultando actividades del ano…');
  }

  function closeModal(delayMs) {
    window.setTimeout(function () {
      if (!modal) {
        return;
      }
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('export-modal-open');
      exporting = false;
      btn.disabled = false;
    }, delayMs || 0);
  }

  function triggerDownload(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function countCsvRows(text) {
    if (!text) {
      return 0;
    }
    var lines = text.split(/\r\n|\n|\r/);
    var count = 0;
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].trim() !== '') {
        count++;
      }
    }
    return Math.max(0, count - 1);
  }

  btn.addEventListener('click', function () {
    if (exporting) {
      return;
    }

    var metaUrl = btn.getAttribute('data-export-meta');
    var exportUrl = btn.getAttribute('data-export-url');
    if (!metaUrl || !exportUrl) {
      return;
    }

    exporting = true;
    btn.disabled = true;
    openModal();

    fetch(metaUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) {
        if (!res.ok) {
          return res.text().then(function (t) {
            throw new Error(t || 'No se pudo preparar la exportacion.');
          });
        }
        return res.json();
      })
      .then(function (meta) {
        if (!meta.ok) {
          throw new Error(meta.error || 'Error al preparar exportacion.');
        }

        var totalRows = Math.max(1, parseInt(meta.row_count, 10) || 1);
        var activityCount = parseInt(meta.activity_count, 10) || 0;
        var filename = meta.filename || 'presupuesto.csv';
        var year = meta.budget_year || '';

        if (subtitle) {
          subtitle.textContent = 'Exportando ' + year + (meta.department_name ? ' — ' + meta.department_name : '');
        }
        setProgress(5, activityCount + ' actividades, ~' + totalRows + ' filas en el archivo…');

        if (activityCount === 0) {
          setProgress(100, 'Sin actividades en este ano — generando archivo vacio…');
        }

        return fetch(exportUrl + (exportUrl.indexOf('?') >= 0 ? '&' : '?') + 'export_token=' + encodeURIComponent(meta.export_token || ''), { credentials: 'same-origin' })
          .then(function (res) {
            if (!res.ok) {
              return res.text().then(function (t) {
                throw new Error(t || 'Error al generar el archivo.');
              });
            }
            if (!res.body || !res.body.getReader) {
              return res.blob().then(function (blob) {
                return { blob: blob, rowsDone: totalRows, totalRows: totalRows };
              });
            }

            var reader = res.body.getReader();
            var decoder = new TextDecoder('utf-8');
            var rawChunks = [];
            var receivedText = '';
            var rowsDone = 0;

            function pump() {
              return reader.read().then(function (result) {
                if (result.done) {
                  receivedText += decoder.decode();
                  rowsDone = countCsvRows(receivedText);
                  var blob = new Blob(rawChunks, { type: 'text/csv;charset=utf-8' });
                  return { blob: blob, rowsDone: rowsDone, totalRows: totalRows };
                }
                rawChunks.push(result.value);
                receivedText += decoder.decode(result.value, { stream: true });
                rowsDone = countCsvRows(receivedText);
                var pct = Math.min(95, 5 + (rowsDone / totalRows) * 90);
                setProgress(pct, 'Generando filas: ' + Math.min(rowsDone, totalRows) + ' / ' + totalRows + '…');
                return pump();
              });
            }

            return pump();
          })
          .then(function (result) {
            setProgress(100, 'Descarga lista — guardando archivo…');
            if (title) {
              title.textContent = 'Descarga completada';
            }
            triggerDownload(result.blob, filename);
            closeModal(1200);
          });
      })
      .catch(function (err) {
        if (title) {
          title.textContent = 'Error en la exportacion';
        }
        setProgress(0, err.message || 'Ocurrio un error.');
        if (subtitle) {
          subtitle.textContent = 'Intente de nuevo en unos segundos.';
        }
        closeModal(3500);
      });
  });
})();
