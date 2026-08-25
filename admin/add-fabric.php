<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$errors=[];
$categories=storefront_categories_fetch($conn);
$categoryDefaultUnits=array_column($categories,'default_unit_type','slug');
$old=['product_code'=>'','amazon_asin'=>'','name'=>'','category'=>'','unit_type'=>'piece','product_type'=>'simple','sku'=>'','slug'=>''];
if($_SERVER['REQUEST_METHOD']==='POST'){
    foreach($old as $key=>$unused){$old[$key]=trim((string)($_POST[$key]??$old[$key]));}
    $old['unit_type']=ProductAdminService::normalizeDraftUnitType($old['unit_type'],(string)($categoryDefaultUnits[$old['category']]??''));
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
<div class="admin-narrow-page u-mx-auto">
  <div class="u-mb-4"><h1 class="u-mb-1">Create Product Draft</h1><p class="u-text-muted u-mb-0">These field names match the catalogue CSV. Complete the remaining catalogue fields in the editor.</p></div>
  <?php if(isset($errors['form'])):?><div class="ui-alert ui-alert--error"><?php echo e($errors['form']);?></div><?php endif;?>
  <form method="post" class="ui-card u-shadow js-no-loading" id="product-draft-form">
    <div class="ui-card__body l-grid l-grid--12 u-gap-3"><?php echo csrf_field(); ?>
      <input type="hidden" name="product_type" value="simple"><input type="hidden" name="slug" value="">
      <div class="l-col-md-half"><label for="product_code" class="ui-label">Product Code</label><input id="product_code" name="product_code" maxlength="100" class="<?php echo form_class($errors,'product_code');?> u-uppercase" value="<?php echo e($old['product_code']);?>"><?php echo form_error($errors,'product_code');?></div>
      <div class="l-col-md-half"><label for="amazon_asin" class="ui-label">Amazon ASIN</label><input id="amazon_asin" name="amazon_asin" maxlength="10" class="<?php echo form_class($errors,'amazon_asin');?> u-uppercase" value="<?php echo e($old['amazon_asin']);?>"><?php echo form_error($errors,'amazon_asin');?></div>
      <div class="l-col-full"><label for="name" class="ui-label">Name *</label><input id="name" name="name" required maxlength="255" class="<?php echo form_class($errors,'name');?>" value="<?php echo e($old['name']);?>"><?php echo form_error($errors,'name');?></div>
      <div class="l-col-md-half"><label for="category" class="ui-label">Product Type *</label><select id="category" name="category" required class="<?php echo form_class($errors,'category','ui-select');?>"><option value="">Select product type</option><?php foreach($categories as $cat):?><option value="<?php echo e($cat['slug']);?>" data-default-unit-type="<?php echo e((string)($cat['default_unit_type']??''));?>" <?php echo $old['category']===$cat['slug']?'selected':'';?>><?php echo e($cat['name']);?></option><?php endforeach;?></select><?php echo form_error($errors,'category');?></div>
      <div class="l-col-md-half"><label for="sku" class="ui-label">Sku Id</label><input id="sku" name="sku" maxlength="100" class="ui-input u-uppercase" value="<?php echo e($old['sku']);?>" placeholder="Generated if blank"></div>
      <div class="l-col-md-half"><label for="unit_type" class="ui-label">Selling Unit *</label><select name="unit_type" required id="unit_type" class="<?php echo form_class($errors,'unit_type','ui-select');?>"><option value="piece" <?php echo $old['unit_type']==='piece'?'selected':'';?>>Piece</option><option value="set" <?php echo $old['unit_type']==='set'?'selected':'';?>>Set</option><option value="meter" <?php echo $old['unit_type']==='meter'?'selected':'';?>>Meter</option></select><?php echo form_error($errors,'unit_type');?><div class="ui-help">Categories can enforce a selling unit. You can change it later from the product editor when the category permits.</div></div>
    </div>
    <div class="ui-card__footer u-flex u-justify-between"><a class="ui-button ui-button--secondary" href="fabrics.php">Cancel</a><button class="ui-button ui-button--primary">Create Draft &amp; Continue</button></div>
  </form>
</div>
<?php include 'partials/footer.php'; ?>
