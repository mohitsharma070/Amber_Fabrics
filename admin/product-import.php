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
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div><h1 class="mb-1">Import Products</h1><p class="text-muted mb-0">Fill the catalogue in Excel, save it as CSV UTF-8, validate it, then import.</p></div>
  <div class="d-flex gap-2"><a class="btn btn-outline-primary" href="product-import.php?download=template">Download CSV Template</a><a class="btn btn-outline-secondary" href="fabrics.php">Back to Products</a></div>
</div>

<?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">
    <form method="post" enctype="multipart/form-data" class="card shadow-sm js-no-loading">
      <div class="card-body">
        <?php echo csrf_field(); ?>
        <div class="mb-3">
          <label class="form-label" for="catalogue">Catalogue CSV *</label>
          <input class="form-control" id="catalogue" name="catalogue" type="file" accept=".csv,text/csv" required>
          <div class="form-text">Maximum 5 MB and 5,000 non-empty product rows. Empty template rows are ignored.</div>
        </div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="duplicate_mode">When SKU or Product Code exists</label><select class="form-select" id="duplicate_mode" name="duplicate_mode"><option value="skip" <?php echo $duplicateMode==='skip'?'selected':''; ?>>Skip existing product (recommended)</option><option value="update" <?php echo $duplicateMode==='update'?'selected':''; ?>>Update existing simple product</option></select></div>
          <div class="col-md-6"><label class="form-label" for="default_unit">Default selling unit</label><select class="form-select" id="default_unit" name="default_unit"><option value="piece" <?php echo $defaultUnit==='piece'?'selected':''; ?>>Piece</option><option value="meter" <?php echo $defaultUnit==='meter'?'selected':''; ?>>Meter</option><option value="set" <?php echo $defaultUnit==='set'?'selected':''; ?>>Set</option></select><div class="form-text">Size Type containing “meter” or “set” overrides this value.</div></div>
        </div>
      </div>
      <div class="card-footer d-flex flex-wrap justify-content-end gap-2"><button class="btn btn-outline-primary" name="action" value="validate">Validate Only</button><button class="btn btn-primary" name="action" value="import" onclick="return confirm('Import all valid rows now?');">Validate &amp; Import</button></div>
    </form>
  </div>
  <div class="col-xl-4">
    <div class="card h-100"><div class="card-body"><h2 class="h5">Import rules</h2><ul class="small mb-0 ps-3"><li class="mb-2">Name, Sku Id, MRP, Quantity and Product Type are required.</li><li class="mb-2">Product Type must exactly match an active category name or slug.</li><li class="mb-2">Products import as simple products. “Visible” rows publish only when the product readiness checks pass; otherwise they remain drafts.</li><li class="mb-2">Image and video cells may contain filenames already present in <code>images/fabrics</code>. External URLs are not downloaded.</li><li>Use Validate Only first. Invalid rows never write partial product data.</li></ul></div></div>
  </div>
</div>

<?php if (is_array($summary)): ?>
<div class="card mt-4 shadow-sm">
  <div class="card-header"><h2 class="h5 mb-0"><?php echo $summary['dry_run']?'Validation Result':'Import Result'; ?></h2></div>
  <div class="card-body">
    <div class="row g-2 text-center">
      <?php foreach(['total'=>'Rows','created'=>'Created','updated'=>'Updated','skipped'=>'Skipped','failed'=>'Errors'] as $key=>$label): ?><div class="col-6 col-md"><div class="border rounded p-2"><div class="fs-4 fw-semibold"><?php echo (int)$summary[$key]; ?></div><div class="text-muted small"><?php echo e($label); ?></div></div></div><?php endforeach; ?>
    </div>
  </div>
  <div class="table-responsive" style="max-height:34rem">
    <table class="table table-striped align-middle mb-0"><thead class="table-dark sticky-top"><tr><th>CSV Row</th><th>Product</th><th>Result</th><th>Details</th></tr></thead><tbody>
    <?php foreach($summary['results'] as $result): $status=(string)$result['status'];$badge=in_array($status,['created','updated','valid'],true)?'success':($status==='skipped'?'secondary':'danger'); ?>
      <tr><td><?php echo (int)$result['row']; ?></td><td><?php echo e((string)$result['name']); ?></td><td><span class="badge text-bg-<?php echo $badge; ?>"><?php echo e(ucfirst($status)); ?></span></td><td><?php echo e((string)$result['message']); ?><?php if(!empty($result['id'])): ?> <a href="edit-fabric.php?id=<?php echo (int)$result['id']; ?>">Edit</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php endif; ?>
<?php include 'partials/footer.php'; ?>
