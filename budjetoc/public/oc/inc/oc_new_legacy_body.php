<?php

declare(strict_types=1);

/** @var string $err */
/** @var string $ok */
/** @var string $year */
/** @var int $budgetYear */
/** @var int $nextOc */
/** @var int $selectedSupplierId */
/** @var string $selectedSupplierName */
/** @var string $selectedSupplierPhone */
/** @var string $selectedSupplierEmail */
/** @var string $dtDefault */
/** @var int $editPoId */
/** @var bool $isEdit */
/** @var string $condicionesDefault */
/** @var string $noteDefault */
/** @var string $reqDefault */
/** @var string $folioYearDefault */
/** @var string $taxDocTypeDefault */
/** @var bool $taxDocTypeRequired */
/** @var list<array<string, mixed>> $initialLines */
/** @var list<array<string, mixed>> $initialBudget */
?>
<?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>
<div id="cuentas-alert" class="alert alert-warning" style="display:none;"></div>

<div class="panel panel-info">
  <div class="panel-heading">
    <h4 class="panel-title"><i class="glyphicon glyphicon-edit"></i> <?= $isEdit ? h(t_es('editar_oc')) : h(t_es('nueva_oc')) ?></h4>
  </div>
  <div class="panel-body">

    <div class="modal fade bs-example-modal-lg" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="myModalLabel">Buscar productos.</h4>
          </div>
          <div class="modal-body">
            <form class="form-horizontal" onsubmit="return false;">
              <div class="form-group">
                <div class="col-sm-6">
                  <input type="text" class="form-control" id="q" placeholder="Buscar productos" autocomplete="off">
                </div>
                <button type="button" class="btn btn-default" id="btn-product-search"><span class="glyphicon glyphicon-search"></span> Buscar</button>
              </div>
            </form>
            <div id="loader_prod" class="oc-modal-loader" style="display:none;"></div>
            <div class="outer_div"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade bs-example-modal-lg" id="myModal_actv" tabindex="-1" role="dialog" aria-labelledby="myModal_actvLabel">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="myModal_actvLabel">Buscar Actividades en el Presupuesto <?= (int) $budgetYear ?>.</h4>
          </div>
          <div class="modal-body">
            <form class="form-horizontal" onsubmit="return false;">
              <div class="form-group">
                <div class="col-sm-6">
                  <input type="text" class="form-control" id="qq" placeholder="Ej. 9815-<?= (int) $budgetYear ?> <?= h(t_es('n_actividad_ej')) ?>" autocomplete="off">
                </div>
                <button type="button" class="btn btn-default" id="btn-budget-search"><span class="glyphicon glyphicon-search"></span> Buscar</button>
              </div>
            </form>
            <div id="loader_actv" class="oc-modal-loader" style="display:none;"></div>
            <div id="page_holder"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade bs-example-modal-lg" id="myModal_prov" tabindex="-1" role="dialog" aria-labelledby="myModal_provLabel">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="myModal_provLabel">Buscar proveedor</h4>
          </div>
          <div class="modal-body">
            <form class="form-horizontal" onsubmit="return false;">
              <div class="form-group">
                <div class="col-sm-6">
                  <input type="text" class="form-control" id="prov_q" placeholder="<?= h(t_es('razon_social')) ?>, <?= h(t_es('nombre_comercial')) ?>, email..." autocomplete="off">
                </div>
                <button type="button" class="btn btn-default" id="btn-supplier-search"><span class="glyphicon glyphicon-search"></span> Buscar</button>
              </div>
            </form>
            <div id="loader_supplier_pick" class="oc-modal-loader" style="display:none;"></div>
            <div id="proveedor_holder"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="nuevoCliente" tabindex="-1" role="dialog">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title"><i class="glyphicon glyphicon-edit"></i> Agregar nuevo proveedor</h4>
          </div>
          <div class="modal-body">
            <div id="resultados_ajax"></div>
            <form class="form-horizontal" id="guardar_cliente" onsubmit="return false;">
              <div class="form-group">
                <label for="prov-legal" class="col-sm-3 control-label">Nombre / <?= h(t_es('razon_social')) ?></label>
                <div class="col-sm-8">
                  <input type="text" class="form-control" id="prov-legal" required>
                </div>
              </div>
              <div class="form-group">
                <label for="prov-trade" class="col-sm-3 control-label"><?= h(t_es('n_empresa')) ?></label>
                <div class="col-sm-8">
                  <input type="text" class="form-control" id="prov-trade">
                </div>
              </div>
              <div class="form-group">
                <label for="prov-phone" class="col-sm-3 control-label"><?= h(t_es('telefono')) ?></label>
                <div class="col-sm-8">
                  <input type="text" class="form-control" id="prov-phone">
                </div>
              </div>
              <div class="form-group">
                <label for="prov-email" class="col-sm-3 control-label">Email</label>
                <div class="col-sm-8">
                  <input type="email" class="form-control" id="prov-email">
                </div>
              </div>
            </form>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
            <button type="button" class="btn btn-primary" id="btn-save-supplier">Guardar datos</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="nuevoProducto" tabindex="-1" role="dialog">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title"><i class="glyphicon glyphicon-edit"></i> Agregar nuevo producto</h4>
          </div>
          <div class="modal-body">
            <div id="resultados_ajax_productos"></div>
            <form class="form-horizontal" id="guardar_producto" onsubmit="return false;">
              <div class="form-group">
                <label for="prod-code" class="col-sm-3 control-label"><?= h(t_es('codigo')) ?></label>
                <div class="col-sm-8">
                  <input type="text" class="form-control" id="prod-code" required maxlength="32">
                </div>
              </div>
              <div class="form-group">
                <label for="prod-name" class="col-sm-3 control-label">Nombre</label>
                <div class="col-sm-8">
                  <textarea class="form-control" id="prod-name" required maxlength="255" rows="2"></textarea>
                </div>
              </div>
              <div class="form-group">
                <label for="prod-price" class="col-sm-3 control-label">Precio</label>
                <div class="col-sm-8">
                  <input type="text" class="form-control" id="prod-price" required>
                </div>
              </div>
            </form>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
            <button type="button" class="btn btn-primary" id="btn-save-product">Guardar datos</button>
          </div>
        </div>
      </div>
    </div>

    <form class="form-horizontal" role="form" id="oc-form" method="post" accept-charset="utf-8">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <?php if ($isEdit): ?>
      <input type="hidden" name="purchase_order_id" value="<?= (int) $editPoId ?>">
      <?php endif; ?>
      <input type="hidden" name="supplier_id" id="id_cliente" value="<?= $selectedSupplierId > 0 ? $selectedSupplierId : '' ?>">

      <div class="form-group row">
        <label for="nombre_cliente" class="col-md-1 control-label">Proveedor</label>
        <div class="col-md-3">
          <div class="input-group input-group-sm">
            <input type="text" class="form-control" id="nombre_cliente" name="supplier_name_display"
                   placeholder="Buscar o escribir proveedor" autocomplete="off" value="<?= h($selectedSupplierName) ?>">
            <span class="input-group-btn">
              <button type="button" class="btn btn-default" id="btn-buscar-proveedor" title="Buscar proveedor">
                <span class="glyphicon glyphicon-search"></span>
              </button>
              <button type="button" class="btn btn-default" data-toggle="modal" data-target="#nuevoCliente" title="Nuevo proveedor">+</button>
            </span>
          </div>
          <p id="proveedor-hint" class="text-muted small" style="margin:4px 0 0;"></p>
        </div>
        <label for="tel1" class="col-md-1 control-label"><?= h(t_es('telefono')) ?></label>
        <div class="col-md-2">
          <input type="text" class="form-control input-sm" id="tel1" readonly value="<?= h($selectedSupplierPhone) ?>">
        </div>
        <label for="mail" class="col-md-1 control-label">Email</label>
        <div class="col-md-3">
          <input type="text" class="form-control input-sm" id="mail" readonly value="<?= h($selectedSupplierEmail) ?>">
        </div>
      </div>

      <div class="form-group row">
        <label for="id_vendedor" class="col-md-1 control-label"><?= h(t_es('nro_req')) ?></label>
        <div class="col-md-3">
          <input type="text" class="form-control input-sm" name="req_number" id="id_vendedor"
                 value="<?= h($reqDefault) ?>" placeholder="<?= h(t_es('nro_req_ph')) ?>" maxlength="64">
        </div>
        <label for="ordered_at" class="col-md-1 control-label">Fecha</label>
        <div class="col-md-2">
          <input type="datetime-local" class="form-control input-sm" name="ordered_at" id="ordered_at" value="<?= h($dtDefault) ?>" required>
        </div>
        <label for="condiciones" class="col-md-1 control-label">Pago</label>
        <div class="col-md-3">
          <select class="form-control input-sm" name="condiciones" id="condiciones">
            <option value="">Seleccione</option>
            <?php
            $payOpts = ['8' => 'Abono 40%', '9' => 'Abono 50%', '6' => t_es('pago_15_dias'), '5' => t_es('pago_7_dias'),
                '2' => t_es('pago_30_dias'), '3' => 'Transferencia bancaria', '4' => t_es('credito'), '7' => 'Contado'];
            foreach ($payOpts as $pv => $pl): ?>
            <option value="<?= h((string) $pv) ?>" <?= $condicionesDefault === (string) $pv ? 'selected' : '' ?>><?= h($pl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group row">
        <label for="tax_doc_type" class="col-md-1 control-label">Tipo</label>
        <div class="col-md-3">
          <select class="form-control input-sm" name="tax_doc_type" id="tax_doc_type"
                  <?= !empty($taxDocTypeRequired) ? 'required' : '' ?>>
            <option value="">Seleccione</option>
            <option value="factura" <?= ($taxDocTypeDefault ?? '') === 'factura' ? 'selected' : '' ?>><?= h(t_es('factura')) ?></option>
            <option value="boleta" <?= ($taxDocTypeDefault ?? '') === 'boleta' ? 'selected' : '' ?>>Boleta de Honorarios</option>
          </select>
        </div>
        <?php
        $facturaModeUi = ($taxFacturaModeDefault ?? '') === 'exento' ? 'exento' : 'iva';
        // Visible sin JS cuando tipo ya es Factura (alta con default / edicion); oculto solo boleta o vacio.
        $taxDocTypeUi = strtolower(trim((string) ($taxDocTypeDefault ?? '')));
        $showFacturaMode = $taxDocTypeUi === 'factura';
        $facturaModeWrapStyle = $showFacturaMode ? '' : 'display:none;';
        $showRetencion = $taxDocTypeUi === 'boleta';
        $retencionWrapStyle = $showRetencion ? '' : 'display:none;';
        $retencionValUi = (string) ($taxRetencionPercentDefault ?? '');
        ?>
        <div id="tax_factura_mode_wrap" class="col-md-2"<?= $facturaModeWrapStyle !== '' ? ' style="' . h($facturaModeWrapStyle) . '"' : '' ?>>
          <label class="radio-inline" style="padding-top:5px;">
            <input type="radio" name="tax_factura_mode" id="tax_factura_mode_iva" value="iva"
                   <?= $facturaModeUi === 'iva' ? 'checked' : '' ?>> Afecta IVA
          </label>
          <label class="radio-inline" style="padding-top:5px;">
            <input type="radio" name="tax_factura_mode" id="tax_factura_mode_exento" value="exento"
                   <?= $facturaModeUi === 'exento' ? 'checked' : '' ?>> Exenta
          </label>
        </div>
        <div id="tax_retencion_wrap" class="col-md-2"<?= $retencionWrapStyle !== '' ? ' style="' . h($retencionWrapStyle) . '"' : '' ?>>
          <label for="tax_retencion_percent" class="control-label" style="padding-top:0;margin-bottom:2px;">Retencion %</label>
          <input type="number" class="form-control input-sm" name="tax_retencion_percent" id="tax_retencion_percent"
                 step="0.01" min="0.01" max="100" value="<?= h($retencionValUi) ?>"
                 <?= $showRetencion ? '' : 'disabled' ?>>
        </div>
        <label for="folio_year" class="col-md-1 control-label">Folio:</label>
        <div class="col-md-2">
          <input type="number" class="form-control input-sm" name="folio_year" id="folio_year"
                 value="<?= h($folioYearDefault) ?>"<?= $isEdit ? ' readonly' : '' ?>>
        </div>
        <label for="numero_oc" class="col-md-1 control-label"><?= h(t_es('n_ordinal')) ?> OC</label>
        <div class="col-md-2">
          <input type="number" class="form-control input-sm" id="numero_oc" value="<?= (int) $nextOc ?>" readonly>
        </div>
      </div>

      <div class="form-group row">
        <label for="factura_nota" class="col-md-1 control-label">Nota</label>
        <div class="col-md-3">
          <input type="text" class="form-control input-sm" name="note" id="factura_nota"
                 value="<?= h($noteDefault) ?>" placeholder="Nota:" maxlength="500">
        </div>
      </div>

      <div class="form-group row">
        <label class="col-md-1 control-label"><?= h(t_es('n_cta')) ?></label>
        <div class="col-md-3">
          <div class="cuenta-stacked">
            <select name="nro_cta" id="nro_cta" class="form-control input-sm oc-cta-select" style="margin-bottom:4px;" required disabled>
              <option value="">Cargando cuentas...</option>
            </select>
            <select name="nro_cta2" id="nro_cta2" class="form-control input-sm oc-cta-select" style="margin-bottom:4px;" disabled>
              <option value="0"></option>
            </select>
            <select name="nro_cta3" id="nro_cta3" class="form-control input-sm oc-cta-select" style="margin-bottom:4px;" disabled>
              <option value="0"></option>
            </select>
            <select name="nro_cta4" id="nro_cta4" class="form-control input-sm oc-cta-select" disabled>
              <option value="0"></option>
            </select>
          </div>
        </div>
      </div>

      <div id="oc-lines-hidden-store" aria-hidden="true" style="display:none;"></div>

      <div class="form-group row">
        <div class="col-md-12">
          <h4 style="margin:0.5rem 0 0.25rem;font-size:1rem;">Productos y servicios</h4>
        </div>
      </div>

      <?php if ($isEdit && $initialLines !== []): ?>
      <div id="resultados" class="col-md-12">
        <div class="table-responsive">
          <table class="table table-bordered" id="lines_table">
            <thead>
              <tr>
                <th class="text-center">CODIGO</th>
                <th class="text-center">CANT.</th>
                <th>DESCRIPCION</th>
                <th class="text-right">PRECIO UNIT.</th>
                <th class="text-right">PRECIO TOTAL</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="lines_body">
            <?php foreach ($initialLines as $ln): ?>
              <?php
                $lt = round((float) ($ln['qty'] ?? 0) * (float) ($ln['unit_price'] ?? 0), 2);
                ?>
              <tr>
                <td><input type="text" class="form-control input-sm line-code" value="<?= h((string) ($ln['product_code'] ?? '')) ?>"></td>
                <td><input type="number" step="0.01" class="form-control input-sm line-qty" value="<?= h((string) ($ln['qty'] ?? '1')) ?>"></td>
                <td><input type="text" class="form-control input-sm line-name" value="<?= h((string) ($ln['product_name'] ?? '')) ?>"></td>
                <td><input type="number" step="0.01" class="form-control input-sm line-price" value="<?= h((string) ($ln['unit_price'] ?? '0')) ?>"></td>
                <td class="text-right line-total"><?= h(number_format($lt, 2, '.', ',')) ?></td>
                <td><button type="button" class="btn btn-xs btn-danger oc-remove-line">&times;</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot id="oc_lines_tfoot">
              <tr><td class="text-right" colspan="4">SUBTOTAL $</td><td class="text-right" id="oc_subtotal_val"></td><td></td></tr>
              <tr><td class="text-right" colspan="4" id="oc_tax_cell_label">IVA (19%)</td><td class="text-right" id="oc_iva_val"></td><td></td></tr>
              <tr><td class="text-right" colspan="4">TOTAL $</td><td class="text-right" id="oc_total_val"></td><td></td></tr>
            </tfoot>
          </table>
        </div>
      </div>
      <?php else: ?>
      <div id="resultados" class="col-md-12" style="display:none;"></div>
      <?php endif; ?>

      <div class="form-group row">
        <div class="col-md-12">
          <h4 style="margin:0.75rem 0 0.25rem;font-size:1rem;"><?= h(t_es('imputacion')) ?> presupuesto</h4>
        </div>
      </div>

      <div id="resultadosPspto" class="col-md-12">
      <?php if ($isEdit && $initialBudget !== []): ?>
        <?php foreach ($initialBudget as $bix => $b): ?>
        <div class="panel panel-default budget-pick" style="margin-bottom:8px;">
          <div class="panel-body">
            <input type="hidden" form="oc-form" name="budget[<?= (int) $bix ?>][detail_id]" value="<?= (int) ($b['detail_id'] ?? 0) ?>">
            <strong><?= h((string) ($b['activity'] ?? '')) ?></strong>
            <?= h(' ' . t_es('sep_title') . ' ') ?>
            <?= h((string) ($b['account'] ?? '')) ?><br>
            <small><?= h(t_es('saldo_linea')) ?>: <?= h((string) ($b['line_rem'] ?? '')) ?>
              | actividad: <?= h((string) ($b['header_rem'] ?? '')) ?></small>
            <div class="row" style="margin-top:8px;">
              <div class="col-sm-4">
                <label>Monto</label>
                <input class="form-control input-sm alloc-amount" form="oc-form"
                  name="budget[<?= (int) $bix ?>][amount]" type="number" step="0.01" min="0.01"
                  value="<?= h((string) ($b['amount'] ?? '')) ?>" required>
              </div>
              <div class="col-sm-4">
                <label><?= h(t_es('n_ordinal')) ?> req</label>
                <input class="form-control input-sm" form="oc-form"
                  name="budget[<?= (int) $bix ?>][req_number]" maxlength="64"
                  value="<?= h((string) ($b['req_number'] ?? '')) ?>">
              </div>
              <div class="col-sm-4" style="padding-top:22px;">
                <button type="button" class="btn btn-xs btn-danger budget-pick-remove"><?= h(t_es('quitar')) ?></button>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>

      <div class="form-group row">
        <div class="col-md-12">
          <div class="pull-right">
            <button type="button" class="btn btn-default" id="btn_actv" data-toggle="modal" data-target="#myModal_actv">
              <span class="glyphicon glyphicon-search"></span> Buscar Actividades
            </button>
            <button type="button" class="btn btn-default" id="btn_add_line" data-toggle="modal" data-target="#myModal">
              <span class="glyphicon glyphicon-search"></span> Agregar productos
            </button>
            <button type="button" class="btn btn-default" data-toggle="modal" data-target="#nuevoProducto">
              <span class="glyphicon glyphicon-plus"></span> Nuevo producto
            </button>
            <button type="submit" class="btn btn-primary">
              <span class="glyphicon glyphicon-floppy-disk"></span> <?= h($isEdit ? t_es('guardar_oc') : t_es('guardar_oc')) ?>
            </button>
            <?php if ($isEdit): ?>
            <a class="btn btn-default" href="index.php?year=<?= (int) $budgetYear ?>">Volver al listado</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<hr>
<p class="text-center text-muted small"><?= h(t_es('uso_interno')) ?></p>
