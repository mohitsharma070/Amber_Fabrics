<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$errors=[];
$categories=storefront_categories_fetch($conn);
$old=['product_code'=>'','amazon_asin'=>'','name'=>'','category'=>'','unit_type'=>'piece','product_type'=>'simple','sku'=>'','slug'=>''];
if($_SERVER['REQUEST_METHOD']==='POST'){
    foreach($old as $key=>$unused){$old[$key]=trim((string)($_POST[$key]??$old[$key]));}
    if(!verify_csrf()){$errors['form']='Invalid session token. Please try again.';}
    if($old['name']==='')$errors['name']='Product name is required.';
    $validCategories=array_column($categories,'slug');
    if(!in_array($old['category'],$validCategories,true))$errors['category']='Select a valid category.';
    if(!in_array($old['unit_type'],['meter','piece','set'],true))$errors['unit_type']='Select a valid unit type.';
    if(!in_array($old['product_type'],['simple','variable'],true))$errors['product_type']='Select a valid product mode.';
    $catalogValidation=ProductAdminService::validateCatalog($conn,$old);$errors=array_merge($errors,$catalogValidation['errors']);
    if(!$errors){
        try{
            $id=ProductAdminService::createDraft($conn,$old,(int)$_SESSION['admin_id']);
            ProductAdminService::saveCatalog($conn,$id,$old);
            flash('success','Draft created. Complete the checklist, add media and stock, then publish.');
            redirect('edit-fabric.php?id='.$id.'&new_product=1');
        }catch(Throwable $e){$errors['form']=$e->getMessage();}
    }
}
$metaTitle=SiteContext::title('Add Product');include 'partials/header.php';
?>
<div class="mx-auto" style="max-width:760px">
  <div class="mb-4"><h1 class="mb-1">Create Product Draft</h1><p class="text-muted mb-0">These field names match the catalogue CSV. Complete the remaining catalogue fields in the editor.</p></div>
  <?php if(isset($errors['form'])):?><div class="alert alert-danger"><?php echo e($errors['form']);?></div><?php endif;?>
  <form method="post" class="card shadow-sm js-no-loading" id="product-draft-form">
    <div class="card-body row g-3"><?php echo csrf_field(); ?>
      <input type="hidden" name="product_type" value="simple"><input type="hidden" name="slug" value="">
      <div class="col-md-6"><label class="form-label">Product Code</label><input name="product_code" maxlength="100" class="<?php echo form_class($errors,'product_code');?> text-uppercase" value="<?php echo e($old['product_code']);?>"><?php echo form_error($errors,'product_code');?></div>
      <div class="col-md-6"><label class="form-label">Amazon ASIN</label><input name="amazon_asin" maxlength="10" class="<?php echo form_class($errors,'amazon_asin');?> text-uppercase" value="<?php echo e($old['amazon_asin']);?>"><?php echo form_error($errors,'amazon_asin');?></div>
      <div class="col-12"><label class="form-label">Name *</label><input name="name" required maxlength="255" class="<?php echo form_class($errors,'name');?>" value="<?php echo e($old['name']);?>"><?php echo form_error($errors,'name');?></div>
      <div class="col-md-6"><label class="form-label">Product Type *</label><select name="category" required class="<?php echo form_class($errors,'category','form-select');?>"><option value="">Select product type</option><?php foreach($categories as $cat):?><option value="<?php echo e($cat['slug']);?>" <?php echo $old['category']===$cat['slug']?'selected':'';?>><?php echo e($cat['name']);?></option><?php endforeach;?></select><?php echo form_error($errors,'category');?></div>
      <div class="col-md-6"><label class="form-label">Sku Id</label><input name="sku" maxlength="100" class="form-control text-uppercase" value="<?php echo e($old['sku']);?>" placeholder="Generated if blank"></div>
      <div class="col-md-6"><label class="form-label">Selling Unit *</label><select name="unit_type" required class="<?php echo form_class($errors,'unit_type','form-select');?>"><option value="piece" <?php echo $old['unit_type']==='piece'?'selected':'';?>>Piece</option><option value="set" <?php echo $old['unit_type']==='set'?'selected':'';?>>Set</option><option value="meter" <?php echo $old['unit_type']==='meter'?'selected':'';?>>Meter</option></select><?php echo form_error($errors,'unit_type');?><div class="form-text">The selling unit is locked after draft creation to protect inventory and order history.</div></div>
    </div>
    <div class="card-footer d-flex justify-content-between"><a class="btn btn-outline-secondary" href="fabrics.php">Cancel</a><button class="btn btn-primary">Create Draft &amp; Continue</button></div>
  </form>
</div>
<?php include 'partials/footer.php'; ?>
