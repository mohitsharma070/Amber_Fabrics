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
$initialEditorTarget='';
if(!empty($errors)){
 $pricingErrorFields=['sale_price','price','cost_price','stock','quantity','meter_options','gst_rate','hsn_code','shipping_weight_kg','parcel_length_cm','parcel_width_cm','parcel_height_cm'];
 $contentErrorFields=['description'];
 $shippingErrorFields=array_keys(array_slice($catalogLabels,6,null,true));
 foreach(array_keys($errors) as $errorField){
  if($errorField==='media'){$initialEditorTarget='media';break;}
  if($errorField==='variants'){$initialEditorSection='variants';$initialEditorTarget='variants';break;}
  if(in_array($errorField,$pricingErrorFields,true)){$initialEditorSection='pricing';break;}
  if(in_array($errorField,$contentErrorFields,true)){$initialEditorSection='content';break;}
  if(in_array($errorField,$shippingErrorFields,true)){$initialEditorSection='shipping';break;}
  $initialEditorSection='details';
 }
}
?>
<div class="card mb-3"><div class="card-body py-2"><ul class="nav nav-pills product-editor-tabs">
 <li class="nav-item"><button type="button" class="nav-link active product-editor-tab" data-editor-tab="details">Catalogue Details</button></li>
 <li class="nav-item"><button type="button" class="nav-link product-editor-tab" data-editor-tab="pricing">Pricing &amp; Quantity</button></li>
 <li class="nav-item"><button type="button" class="nav-link product-editor-tab" data-editor-tab="content">Description</button></li>
 <li class="nav-item"><button type="button" class="nav-link product-editor-tab" data-editor-tab="shipping">Attributes</button></li>
 <?php if($isEdit):?><li class="nav-item"><a class="nav-link" href="#variants-card" id="variants-tab-link">Variants</a></li><?php endif;?>
</ul></div></div>

<form method="POST" class="row g-3" id="product-editor-form" data-initial-editor-section="<?php echo e($initialEditorSection); ?>" data-initial-editor-target="<?php echo e($initialEditorTarget); ?>">
 <?php echo csrf_field(); ?>
 <input type="hidden" name="submit" id="product_submit_intent" value="save">
 <input type="hidden" name="product_type" value="<?php echo e((string)($old['product_type']??'simple'));?>">
 <input type="hidden" name="slug" value="<?php echo e((string)($old['slug']??''));?>">
 <div class="col-md-4" data-editor-section="details"><label for="unit_type" class="form-label">Selling Unit</label><select id="unit_type" name="unit_type" class="<?php echo form_class($errors,'unit_type','form-select');?>" required><option value="piece" <?php echo ($old['unit_type']??'')==='piece'?'selected':'';?>>Piece</option><option value="set" <?php echo ($old['unit_type']??'')==='set'?'selected':'';?>>Set</option><option value="meter" <?php echo ($old['unit_type']??'')==='meter'?'selected':'';?>>Meter</option></select><?php echo form_error($errors,'unit_type');?><div class="form-text">Changing the unit moves the product to draft so inventory can be reviewed.</div></div>
 <div class="col-md-4" data-editor-section="details"><label class="form-label">Inventory Mode</label><input class="form-control" value="<?php echo e(ucfirst((string)($old['product_type']??'simple')));?>" disabled><div class="form-text">Use the Variants tab to change inventory mode safely.</div></div>
 <div class="col-md-4" data-editor-section="details"><label class="form-label">Product Code</label><input name="product_code" maxlength="100" class="<?php echo form_class($errors,'product_code');?> text-uppercase" value="<?php echo e((string)($old['product_code']??''));?>"><?php echo form_error($errors,'product_code');?></div>
 <div class="col-md-4" data-editor-section="details"><label class="form-label">Amazon ASIN</label><input name="amazon_asin" maxlength="10" class="<?php echo form_class($errors,'amazon_asin');?> text-uppercase" value="<?php echo e((string)($old['amazon_asin']??''));?>"><?php echo form_error($errors,'amazon_asin');?></div>
 <div class="col-md-4" data-editor-section="details"><label class="form-label">Sku Id *</label><input name="sku" id="sku_hidden" maxlength="100" required class="<?php echo form_class($errors,'sku');?> text-uppercase" value="<?php echo e((string)$old['sku']);?>"><input type="hidden" id="sku_preview" value="<?php echo e((string)$old['sku']);?>"><?php echo form_error($errors,'sku');?></div>
 <div class="col-md-8" data-editor-section="details"><label class="form-label">Name *</label><input name="name" maxlength="255" required class="<?php echo form_class($errors,'name');?>" value="<?php echo e((string)$old['name']);?>"><?php echo form_error($errors,'name');?></div>
 <div class="col-md-4" data-editor-section="details"><label for="category" class="form-label">Product Type *</label><select id="category" name="category" required class="<?php echo form_class($errors,'category','form-select');?>"><option value="">Select product type</option><?php foreach($categories as $cat):?><option value="<?php echo e((string)$cat['slug']);?>" data-default-unit-type="<?php echo e((string)($cat['default_unit_type']??''));?>" <?php echo ($old['category']??'')===$cat['slug']?'selected':'';?>><?php echo e((string)$cat['name']);?></option><?php endforeach;?></select><?php echo form_error($errors,'category');?></div>
 <div class="col-md-4" data-editor-section="details"><label class="form-label">Size Type</label><input name="size_type" class="form-control" value="<?php echo e((string)($old['size_type']??''));?>"></div>
 <div class="col-md-4" data-editor-section="details"><label class="form-label">Size</label><input name="size" maxlength="100" class="form-control" value="<?php echo e((string)($old['size']??''));?>"></div>
 <div class="col-md-4" data-editor-section="details"><label class="form-label">Colour</label><input name="color" maxlength="100" class="form-control" value="<?php echo e((string)($old['color']??''));?>"></div>
 <?php foreach(['return_exchange_condition','size_chart','pickup_address_code','customisation_id','associated_pixel'] as $field):?>
 <div class="col-md-4" data-editor-section="details"><label class="form-label"><?php echo e($catalogLabels[$field]);?></label><input name="<?php echo e($field);?>" class="form-control" value="<?php echo e((string)($old[$field]??''));?>"></div>
 <?php endforeach;?>
 <div class="col-md-4" data-editor-section="details"><label class="form-label">Visibility</label><select name="visibility" class="<?php echo form_class($errors,'visibility','form-select');?>"><option value="draft" <?php echo ($old['visibility']??'draft')==='draft'?'selected':'';?>>Draft</option><option value="active" <?php echo ($old['visibility']??'')==='active'?'selected':'';?>>Visible</option><option value="inactive" <?php echo ($old['visibility']??'')==='inactive'?'selected':'';?>>Hidden</option></select><?php echo form_error($errors,'visibility');?></div>

 <div class="col-md-3" data-editor-section="pricing"><label class="form-label">Selling Price<span data-meter-price-suffix><?php echo ($old['unit_type']??'')==='meter'?' per Meter':'';?></span> *</label><input type="number" name="selling_price" min="0.01" step="0.01" required class="<?php echo form_class($errors,'sale_price');?>" value="<?php echo e((string)($old['selling_price']??''));?>"><?php echo form_error($errors,'sale_price');?></div>
 <div class="col-md-3" data-editor-section="pricing"><label class="form-label">MRP<span data-meter-price-suffix><?php echo ($old['unit_type']??'')==='meter'?' per Meter':'';?></span> *</label><input type="number" name="mrp" min="0.01" step="0.01" required class="<?php echo form_class($errors,'price');?>" value="<?php echo e((string)($old['mrp']??''));?>"><?php echo form_error($errors,'price');?></div>
 <div class="col-md-3" data-editor-section="pricing"><label class="form-label">Cost Price</label><input type="number" name="cost_price" min="0" step="0.01" class="<?php echo form_class($errors,'cost_price');?>" value="<?php echo e((string)$old['cost_price']);?>"><?php echo form_error($errors,'cost_price');?></div>
 <div class="col-md-3" data-editor-section="pricing"><label class="form-label">Stock Quantity<span id="quantity_unit_suffix"><?php echo ($old['unit_type']??'')==='meter'?' (meters)':' (units)';?></span> *</label><input id="quantity" type="number" name="quantity" min="0" step="<?php echo ($old['unit_type']??'')==='meter'?'0.01':'1';?>" required class="<?php echo form_class($errors,'stock');?>" value="<?php echo e((string)($old['quantity']??0));?>"><?php echo form_error($errors,'stock');?></div>
 <div class="col-md-6" data-editor-section="pricing" data-meter-unit-field <?php echo ($old['unit_type']??'')==='meter'?'':'hidden';?>><label for="meter_options" class="form-label">Meter Length Options *</label><input id="meter_options" name="meter_options" data-meter-required class="<?php echo form_class($errors,'meter_options');?>" value="<?php echo e((string)($old['meter_options']??''));?>" placeholder="10, 20, 30" <?php echo ($old['unit_type']??'')==='meter'?'required':'';?>><div class="form-text">Comma-separated customer choices. Price is calculated per meter × selected length × bundle quantity.</div><?php echo form_error($errors,'meter_options');?></div>
 <?php foreach([['parcel_length_cm','Packaging Length (in cm)','0.01'],['parcel_width_cm','Packaging Breadth (in cm)','0.01'],['parcel_height_cm','Packaging Height (in cm)','0.01'],['shipping_weight_kg','Packaging Weight (in kg)','0.001']] as $metric):?>
 <div class="col-md-3" data-editor-section="pricing"><label class="form-label"><?php echo e($metric[1]);?></label><input type="number" name="<?php echo e($metric[0]);?>" min="<?php echo e($metric[2]);?>" step="<?php echo e($metric[2]);?>" class="<?php echo form_class($errors,$metric[0]);?>" value="<?php echo e((string)($old[$metric[0]]??''));?>"><?php echo form_error($errors,$metric[0]);?></div>
 <?php endforeach;?>
 <div class="col-md-3" data-editor-section="pricing"><label class="form-label">GST %</label><input type="number" name="gst_rate" min="0" max="100" step="0.01" class="<?php echo form_class($errors,'gst_rate');?>" value="<?php echo e((string)($old['gst_rate']??''));?>"><?php echo form_error($errors,'gst_rate');?></div>
 <div class="col-md-3" data-editor-section="pricing"><label class="form-label">HSN Code</label><input name="hsn_code" maxlength="8" inputmode="numeric" class="<?php echo form_class($errors,'hsn_code');?>" value="<?php echo e((string)($old['hsn_code']??''));?>"><?php echo form_error($errors,'hsn_code');?></div>

 <div class="col-12" data-editor-section="content"><label class="form-label">Description *</label><textarea name="description" rows="8" minlength="20" maxlength="5000" required class="<?php echo form_class($errors,'description');?>"><?php echo e((string)$old['description']);?></textarea><?php echo form_error($errors,'description');?></div>

 <?php foreach(array_slice($catalogLabels,6,null,true) as $field=>$label):?>
 <div class="col-md-4" data-editor-section="shipping"><label class="form-label"><?php echo e($label);?></label><input name="<?php echo e($field);?>" maxlength="1000" class="form-control" value="<?php echo e((string)($old[$field]??''));?>"></div>
 <?php endforeach;?>
 <div class="col-12" data-editor-section="actions"><button type="button" class="btn btn-outline-secondary" id="product-prev-tab-btn" data-cancel-href="<?php echo e((string)$cancelHref);?>">Back</button> <button type="button" class="btn btn-outline-primary" id="product-next-tab-btn">Next</button> <button type="submit" data-submit-intent="save" class="btn btn-primary"><?php echo e($submitLabel);?></button></div>
</form>
