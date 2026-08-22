<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

if (isset($_GET['download']) && $_GET['download'] === 'template') {
    ProductImportService::streamTemplate();
}

$summary = null;
$error = '';
$duplicateMode = in_array(($_POST['duplicate_mode'] ?? ''), ['skip','update'], true) ? $_POST['duplicate_mode'] : 'skip';
$defaultUnit = in_array(($_POST['default_unit'] ?? ''), ['piece','meter','set'], true) ? $_POST['default_unit'] : 'piece';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid session token. Refresh the page and try again.';
    } else {
        try {
            $doImport = ($_POST['action'] ?? '') === 'import';
            $summary = ProductImportService::process(
                $conn,
                $_FILES['catalogue'] ?? [],
                ['duplicate_mode' => $duplicateMode, 'default_unit' => $defaultUnit],
                (int) $_SESSION['admin_id'],
                $doImport
            );
            if ($doImport) {
                log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_catalogue_import','product',null,
                    sprintf('Catalogue CSV processed: %d created, %d updated, %d skipped, %d failed.', $summary['created'],$summary['updated'],$summary['skipped'],$summary['failed']),'ok');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$metaTitle = SiteContext::title('Import Products');
include 'partials/header.php';
?>
<div class="u-flex u-flex-wrap u-justify-between u-items-start u-gap-3 u-mb-4">
  <div><h1 class="u-mb-1">Import Products</h1><p class="u-text-muted u-mb-0">Fill the catalogue in Excel, save it as CSV UTF-8, validate it, then import.</p></div>
  <div class="u-flex u-gap-2"><a class="ui-button ui-button--outline" href="product-import.php?download=template">Download CSV Template</a><a class="ui-button ui-button--secondary" href="fabrics.php">Back to Products</a></div>
</div>

<?php if ($error !== ''): ?><div class="ui-alert ui-alert--error"><?php echo e($error); ?></div><?php endif; ?>

<div class="l-grid l-grid--12 u-gap-4">
  <div class="l-col-xl-eight">
    <form method="post" enctype="multipart/form-data" class="ui-card u-shadow js-no-loading">
      <div class="ui-card__body">
        <?php echo csrf_field(); ?>
        <div class="u-mb-3">
          <label class="ui-label" for="catalogue">Catalogue CSV *</label>
          <input class="ui-input" id="catalogue" name="catalogue" type="file" accept=".csv,text/csv" required>
          <div class="ui-help">Maximum 5 MB and 5,000 non-empty product rows. Empty template rows are ignored.</div>
        </div>
        <div class="l-grid l-grid--12 u-gap-3">
          <div class="l-col-md-half"><label class="ui-label" for="duplicate_mode">When SKU or Product Code exists</label><select class="ui-select" id="duplicate_mode" name="duplicate_mode"><option value="skip" <?php echo $duplicateMode==='skip'?'selected':''; ?>>Skip existing product (recommended)</option><option value="update" <?php echo $duplicateMode==='update'?'selected':''; ?>>Update existing simple product</option></select></div>
          <div class="l-col-md-half"><label class="ui-label" for="default_unit">Fallback selling unit</label><select class="ui-select" id="default_unit" name="default_unit"><option value="piece" <?php echo $defaultUnit==='piece'?'selected':''; ?>>Piece</option><option value="meter" <?php echo $defaultUnit==='meter'?'selected':''; ?>>Meter</option><option value="set" <?php echo $defaultUnit==='set'?'selected':''; ?>>Set</option></select><div class="ui-help">Used only when a row has no Selling Unit. Older CSV files can still infer Meter or Set from Size Type.</div></div>
        </div>
      </div>
      <div class="ui-card__footer u-flex u-flex-wrap u-justify-end u-gap-2"><button class="ui-button ui-button--outline" name="action" value="validate">Validate Only</button><button class="ui-button ui-button--primary" name="action" value="import" data-confirm="Import all valid rows now?">Validate &amp; Import</button></div>
    </form>
  </div>
  <div class="l-col-xl-third">
    <div class="ui-card u-h-full"><div class="ui-card__body"><h2 class="u-heading-5">Import rules</h2><ul class="u-text-small u-mb-0 admin-list-indent"><li class="u-mb-2">Name, Sku Id, MRP, Quantity and Product Type are required.</li><li class="u-mb-2">Set Selling Unit per row to Piece, Meter, or Set. The fallback is used only when that cell is blank.</li><li class="u-mb-2">Product Type must exactly match an active category name or slug.</li><li class="u-mb-2">Products import as simple products. “Visible” rows publish only when the product readiness checks pass; otherwise they remain drafts.</li><li class="u-mb-2">Image and video cells may contain filenames already present in <code>images/fabrics</code>. External URLs are not downloaded.</li><li>Use Validate Only first. Invalid rows never write partial product data.</li></ul></div></div>
  </div>
</div>

<?php if (is_array($summary)): ?>
<div class="ui-card u-mt-4 u-shadow">
  <div class="ui-card__header"><h2 class="u-heading-5 u-mb-0"><?php echo $summary['dry_run']?'Validation Result':'Import Result'; ?></h2></div>
  <div class="ui-card__body">
    <div class="l-grid l-grid--12 u-gap-2 u-text-center">
      <?php foreach(['total'=>'Rows','created'=>'Created','updated'=>'Updated','skipped'=>'Skipped','failed'=>'Errors'] as $key=>$label): ?><div class="l-col-half l-col-auto"><div class="u-border u-rounded u-p-2"><div class="u-heading-4 u-font-semibold"><?php echo (int)$summary[$key]; ?></div><div class="u-text-muted u-text-small"><?php echo e($label); ?></div></div></div><?php endforeach; ?>
    </div>
  </div>
  <div class="ui-table-wrap admin-scroll-table">
    <table class="ui-table ui-table--striped u-align-middle u-mb-0"><thead class="ui-table__head--dark admin-sticky-top"><tr><th>CSV Row</th><th>Product</th><th>Result</th><th>Details</th></tr></thead><tbody>
    <?php foreach($summary['results'] as $result): $status=(string)$result['status'];$badge=in_array($status,['created','updated','valid'],true)?'success':($status==='skipped'?'secondary':'danger'); ?>
      <tr><td><?php echo (int)$result['row']; ?></td><td><?php echo e((string)$result['name']); ?></td><td><span class="ui-badge ui-badge--<?php echo e(ui_tone($badge)); ?>"><?php echo e(ucfirst($status)); ?></span></td><td><?php echo e((string)$result['message']); ?><?php if(!empty($result['id'])): ?> <a href="edit-fabric.php?id=<?php echo (int)$result['id']; ?>">Edit</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php endif; ?>
<?php include 'partials/footer.php'; ?>
