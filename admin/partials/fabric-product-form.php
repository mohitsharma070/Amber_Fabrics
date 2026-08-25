<?php
$isEdit=!empty($isEdit);
$submitLabel=isset($submitLabel)?(string)$submitLabel:'Save Changes';$cancelHref=$cancelHref??'fabrics.php';
$catalogLabels=[
 'size_type'=>'Size Type','return_exchange_condition'=>'Return Condition','size_chart'=>'Size Chart','pickup_address_code'=>'Pickup Address Code',
 'customisation_id'=>'Customisation Id','associated_pixel'=>'Associated Pixel','attr_attribute_name'=>'Attribute Name','attr_brand_name'=>'Brand Name',
 'attr_type'=>'Type','attr_material'=>'Material','attr_product_type'=>'Product Type','attr_style_name'=>'Style Name','attr_design'=>'Design',
 'attr_printing_type'=>'Printing Type','attr_shape'=>'Shape','attr_pattern'=>'Pattern','attr_origin'=>'Origin','attr_thickness_ply'=>'Thickness / Ply',
 'attr_disposable_folded'=>'Disposable Folded','attr_stain_resistant'=>'Stain-Resistant','attr_eco_friendly'=>'Eco-Friendly','attr_fabric'=>'Fabric',
];
$initialEditorSection='';
if(!empty($errors)){
 $pricingErrorFields=['sale_price','price','cost_price','stock','quantity','gst_rate','hsn_code','shipping_weight_kg','parcel_length_cm','parcel_width_cm','parcel_height_cm'];
 $contentErrorFields=['description'];
 $shippingErrorFields=array_keys(array_slice($catalogLabels,6,null,true));
 foreach(array_keys($errors) as $errorField){
  if(in_array($errorField,$pricingErrorFields,true)){$initialEditorSection='pricing';break;}
  if(in_array($errorField,$contentErrorFields,true)){$initialEditorSection='content';break;}
  if(in_array($errorField,$shippingErrorFields,true)){$initialEditorSection='shipping';break;}
  $initialEditorSection='details';
 }
}
?>
<div class="ui-card u-mb-3"><div class="ui-card__body u-py-2"><ul class="admin-tabs product-editor-tabs">
 <li><button type="button" class="admin-tab is-active product-editor-tab" data-editor-tab="details">Catalogue Details</button></li>
 <li><button type="button" class="admin-tab product-editor-tab" data-editor-tab="pricing">Pricing &amp; Quantity</button></li>
 <li><button type="button" class="admin-tab product-editor-tab" data-editor-tab="content">Description</button></li>
 <li><button type="button" class="admin-tab product-editor-tab" data-editor-tab="shipping">Attributes</button></li>
 <?php if($isEdit):?><li><a class="admin-tab" href="#variants-card" id="variants-tab-link">Variants</a></li><?php endif;?>
</ul></div></div>

<form method="POST" class="l-grid l-grid--12 u-gap-3" id="product-editor-form" data-initial-editor-section="<?php echo e($initialEditorSection); ?>">
 <?php echo csrf_field(); ?>
 <input type="hidden" name="submit" id="product_submit_intent" value="save">
 <input type="hidden" name="product_type" value="<?php echo e((string)($old['product_type']??'simple'));?>">
 <input type="hidden" name="unit_type" value="<?php echo e((string)($old['unit_type']??'piece'));?>">
 <input type="hidden" name="slug" value="<?php echo e((string)($old['slug']??''));?>">
 <?php /* Both inputs are disabled mirrors of the hidden unit_type/product_type fields above,
          so they take a _display id rather than reusing the submitted field name. */ ?>
 <div class="l-col-md-third" data-editor-section="details"><label class="ui-label" for="unit_type_display">Selling Unit</label><input id="unit_type_display" class="ui-input" value="<?php echo e(ucfirst((string)($old['unit_type']??'piece')));?>" disabled><div class="ui-help">Choose the selling unit when creating a product draft.</div></div>
 <div class="l-col-md-third" data-editor-section="details"><label class="ui-label" for="product_type_display">Inventory Mode</label><input id="product_type_display" class="ui-input" value="<?php echo e(ucfirst((string)($old['product_type']??'simple')));?>" disabled><div class="ui-help">Use the Variants tab to change inventory mode safely.</div></div>
 <div class="l-col-md-third" data-editor-section="details"><label for="product_code" class="ui-label">Product Code</label><input id="product_code" name="product_code" maxlength="100" class="<?php echo form_class($errors,'product_code');?> u-uppercase" value="<?php echo e((string)($old['product_code']??''));?>"><?php echo form_error($errors,'product_code');?></div>
 <div class="l-col-md-third" data-editor-section="details"><label for="amazon_asin" class="ui-label">Amazon ASIN</label><input id="amazon_asin" name="amazon_asin" maxlength="10" class="<?php echo form_class($errors,'amazon_asin');?> u-uppercase" value="<?php echo e((string)($old['amazon_asin']??''));?>"><?php echo form_error($errors,'amazon_asin');?></div>
 <div class="l-col-md-third" data-editor-section="details"><label for="sku_hidden" class="ui-label">Sku Id *</label><input name="sku" id="sku_hidden" maxlength="100" required class="<?php echo form_class($errors,'sku');?> u-uppercase" value="<?php echo e((string)$old['sku']);?>"><input type="hidden" id="sku_preview" value="<?php echo e((string)$old['sku']);?>"><?php echo form_error($errors,'sku');?></div>
 <div class="l-col-md-eight" data-editor-section="details"><label for="name" class="ui-label">Name *</label><input id="name" name="name" maxlength="255" required class="<?php echo form_class($errors,'name');?>" value="<?php echo e((string)$old['name']);?>"><?php echo form_error($errors,'name');?></div>
 <div class="l-col-md-third" data-editor-section="details"><label for="category" class="ui-label">Product Type *</label><select id="category" name="category" required class="<?php echo form_class($errors,'category','ui-select');?>"><option value="">Select product type</option><?php foreach($categories as $cat):?><option value="<?php echo e((string)$cat['slug']);?>" <?php echo ($old['category']??'')===$cat['slug']?'selected':'';?>><?php echo e((string)$cat['name']);?></option><?php endforeach;?></select><?php echo form_error($errors,'category');?></div>
 <div class="l-col-md-third" data-editor-section="details"><label for="size_type" class="ui-label">Size Type</label><input id="size_type" name="size_type" class="ui-input" value="<?php echo e((string)($old['size_type']??''));?>"></div>
 <div class="l-col-md-third" data-editor-section="details"><label for="size" class="ui-label">Size</label><input id="size" name="size" maxlength="100" class="ui-input" value="<?php echo e((string)($old['size']??''));?>"></div>
 <div class="l-col-md-third" data-editor-section="details"><label for="color" class="ui-label">Colour</label><input id="color" name="color" maxlength="100" class="ui-input" value="<?php echo e((string)($old['color']??''));?>"></div>
 <?php /* Loop bodies: the id is derived from the field name, so it stays unique per iteration. */ ?>
 <?php foreach(['return_exchange_condition','size_chart','pickup_address_code','customisation_id','associated_pixel'] as $field):?>
 <div class="l-col-md-third" data-editor-section="details"><label class="ui-label" for="<?php echo e($field);?>"><?php echo e($catalogLabels[$field]);?></label><input id="<?php echo e($field);?>" name="<?php echo e($field);?>" class="ui-input" value="<?php echo e((string)($old[$field]??''));?>"></div>
 <?php endforeach;?>
 <div class="l-col-md-third" data-editor-section="details"><label for="visibility" class="ui-label">Visibility</label><select id="visibility" name="visibility" class="ui-select"><option value="draft" <?php echo ($old['visibility']??'draft')==='draft'?'selected':'';?>>Draft</option><option value="active" <?php echo ($old['visibility']??'')==='active'?'selected':'';?>>Visible</option><option value="inactive" <?php echo ($old['visibility']??'')==='inactive'?'selected':'';?>>Hidden</option></select></div>

 <div class="l-col-md-quarter" data-editor-section="pricing"><label for="selling_price" class="ui-label">Selling Price *</label><input id="selling_price" type="number" name="selling_price" min="0.01" step="0.01" required class="<?php echo form_class($errors,'sale_price');?>" value="<?php echo e((string)($old['selling_price']??''));?>"><?php echo form_error($errors,'sale_price');?></div>
 <div class="l-col-md-quarter" data-editor-section="pricing"><label for="mrp" class="ui-label">MRP *</label><input id="mrp" type="number" name="mrp" min="0.01" step="0.01" required class="<?php echo form_class($errors,'price');?>" value="<?php echo e((string)($old['mrp']??''));?>"><?php echo form_error($errors,'price');?></div>
 <div class="l-col-md-quarter" data-editor-section="pricing"><label for="cost_price" class="ui-label">Cost Price</label><input id="cost_price" type="number" name="cost_price" min="0" step="0.01" class="<?php echo form_class($errors,'cost_price');?>" value="<?php echo e((string)$old['cost_price']);?>"></div>
 <div class="l-col-md-quarter" data-editor-section="pricing"><label for="quantity" class="ui-label">Quantity *</label><input id="quantity" type="number" name="quantity" min="0" step="0.01" required class="<?php echo form_class($errors,'stock');?>" value="<?php echo e((string)($old['quantity']??0));?>"><?php echo form_error($errors,'stock');?></div>
 <?php foreach([['parcel_length_cm','Packaging Length (in cm)','0.01'],['parcel_width_cm','Packaging Breadth (in cm)','0.01'],['parcel_height_cm','Packaging Height (in cm)','0.01'],['shipping_weight_kg','Packaging Weight (in kg)','0.001']] as $metric):?>
 <div class="l-col-md-quarter" data-editor-section="pricing"><label class="ui-label" for="<?php echo e($metric[0]);?>"><?php echo e($metric[1]);?></label><input id="<?php echo e($metric[0]);?>" type="number" name="<?php echo e($metric[0]);?>" min="<?php echo e($metric[2]);?>" step="<?php echo e($metric[2]);?>" class="<?php echo form_class($errors,$metric[0]);?>" value="<?php echo e((string)($old[$metric[0]]??''));?>"></div>
 <?php endforeach;?>
 <div class="l-col-md-quarter" data-editor-section="pricing"><label for="gst_rate" class="ui-label">GST %</label><input id="gst_rate" type="number" name="gst_rate" min="0" max="100" step="0.01" class="<?php echo form_class($errors,'gst_rate');?>" value="<?php echo e((string)($old['gst_rate']??''));?>"><?php echo form_error($errors,'gst_rate');?></div>
 <div class="l-col-md-quarter" data-editor-section="pricing"><label for="hsn_code" class="ui-label">HSN Code</label><input id="hsn_code" name="hsn_code" maxlength="8" inputmode="numeric" class="<?php echo form_class($errors,'hsn_code');?>" value="<?php echo e((string)($old['hsn_code']??''));?>"><?php echo form_error($errors,'hsn_code');?></div>

 <div class="l-col-full" data-editor-section="content"><label for="description" class="ui-label">Description *</label><textarea id="description" name="description" rows="8" minlength="20" maxlength="5000" required class="<?php echo form_class($errors,'description');?>"><?php echo e((string)$old['description']);?></textarea><?php echo form_error($errors,'description');?></div>

 <?php foreach(array_slice($catalogLabels,6,null,true) as $field=>$label):?>
 <div class="l-col-md-third" data-editor-section="shipping"><label class="ui-label" for="<?php echo e($field);?>"><?php echo e($label);?></label><input id="<?php echo e($field);?>" name="<?php echo e($field);?>" maxlength="1000" class="ui-input" value="<?php echo e((string)($old[$field]??''));?>"></div>
 <?php endforeach;?>
 <div class="l-col-full" data-editor-section="actions"><button type="button" class="ui-button ui-button--secondary" id="product-prev-tab-btn" data-cancel-href="<?php echo e((string)$cancelHref);?>">Back</button> <button type="button" class="ui-button ui-button--outline" id="product-next-tab-btn">Next</button> <button type="submit" data-submit-intent="save" class="ui-button ui-button--primary"><?php echo e($submitLabel);?></button></div>
</form>
