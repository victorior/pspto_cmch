</main>
<?php if (!empty($layoutBootstrap)): ?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<?php endif; ?>
<?php if (!empty($layoutJqueryUi)): ?>
<script src="vendor/jquery-ui/1.13.2/jquery-ui.min.js?v=20260541"></script>
<?php endif; ?>
<?php if (!empty($layoutPageScripts)): ?>
<?= $layoutPageScripts ?>
<?php endif; ?>
</body>
</html>
