<?php
require_once __DIR__.'/../includes/init.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
$respond=static function(array $data,int $status=200):never{http_response_code($status);echo json_encode($data);exit;};
if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf())$respond(['ok'=>false,'message'=>'Invalid request.'],403);
$id=(int)($_POST['product_id']??0);$action=(string)($_POST['action']??'readiness');
if($id<=0)$respond(['ok'=>false,'message'=>'Invalid product.'],422);
try{
 if($action==='save-section'){
   $stmt=$conn->prepare('SELECT * FROM fabrics WHERE id=? LIMIT 1');$stmt->bind_param('i',$id);$stmt->execute();$input=$stmt->get_result()->fetch_assoc();
   if(!$input)$respond(['ok'=>false,'message'=>'Product not found.'],404);
   foreach(array_merge(['product_type','sku','slug','hsn_code','gst_rate','shipping_weight_kg','parcel_length_cm','parcel_width_cm','parcel_height_cm','product_code','amazon_asin'],ProductAdminService::CATALOG_ATTRIBUTE_FIELDS) as $field){if(array_key_exists($field,$_POST))$input[$field]=trim((string)$_POST[$field]);}
   $result=ProductAdminService::saveExtended($conn,$id,$input);
   if(!empty($result['errors']))$respond(['ok'=>false,'message'=>'Please correct the highlighted fields.','errors'=>$result['errors'],'warnings'=>$result['warnings']??[]],422);
   $catalogResult=ProductAdminService::saveCatalog($conn,$id,$input);if(!empty($catalogResult['errors']))$respond(['ok'=>false,'message'=>'Please correct the catalogue fields.','errors'=>$catalogResult['errors']],422);
   log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_section_saved','product',$id,'Product editor section saved.','ok');
   $respond(['ok'=>true,'message'=>'Section saved.','warnings'=>$result['warnings']??[],'slug'=>$result['slug']??'']);
 }
 if($action==='readiness')$respond(['ok'=>true]+ProductAdminService::readiness($conn,$id));
 if($action==='enable-variants'){
   $conn->begin_transaction();
   try{
     $modeStmt=$conn->prepare('SELECT product_type FROM fabrics WHERE id=? FOR UPDATE');$modeStmt->bind_param('i',$id);$modeStmt->execute();$modeRow=$modeStmt->get_result()->fetch_assoc();
     if(!$modeRow){$conn->rollback();$respond(['ok'=>false,'message'=>'Product not found.'],404);}
     if(($modeRow['product_type']??'simple')==='variable'){$conn->commit();$respond(['ok'=>true,'message'=>'Variable inventory is already enabled.']);}
     $stmt=$conn->prepare("UPDATE fabrics SET product_type='variable',status='draft',is_available=0,stock=0,stock_meters=0 WHERE id=?");$stmt->bind_param('i',$id);$stmt->execute();
     log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_mode_changed','product',$id,'Product changed to variable inventory.','ok');
     $conn->commit();
   }catch(Throwable $e){$conn->rollback();throw $e;}
   $respond(['ok'=>true,'message'=>'Variable inventory enabled. Add at least one active variant with stock before publishing.']);
 }
 if($action==='disable-variants'){
   $stockRaw=trim((string)($_POST['base_stock']??''));
   if($stockRaw===''||!is_numeric($stockRaw)||(float)$stockRaw<0)$respond(['ok'=>false,'message'=>'Enter valid non-negative base stock.'],422);
   $variantMedia=[];
   $conn->begin_transaction();
   try{
     $modeStmt=$conn->prepare('SELECT product_type,unit_type FROM fabrics WHERE id=? FOR UPDATE');$modeStmt->bind_param('i',$id);$modeStmt->execute();$modeRow=$modeStmt->get_result()->fetch_assoc();
     if(!$modeRow){$conn->rollback();$respond(['ok'=>false,'message'=>'Product not found.'],404);}
     $unit=in_array((string)($modeRow['unit_type']??''),['meter','piece','set'],true)?(string)$modeRow['unit_type']:'piece';
     if($unit!=='meter'&&floor((float)$stockRaw)!==(float)$stockRaw){$conn->rollback();$respond(['ok'=>false,'message'=>'Piece and set stock must be a whole number.'],422);}
     $stock=$unit==='meter'?0:(int)$stockRaw;$stockMeters=$unit==='meter'?round((float)$stockRaw,2):0;
     $mediaStmt=$conn->prepare('SELECT image,image2,image3,image4,video FROM fabric_variants WHERE fabric_id=?');$mediaStmt->bind_param('i',$id);$mediaStmt->execute();$variantMedia=$mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
     $detach=$conn->prepare('UPDATE order_items oi JOIN fabric_variants fv ON fv.id=oi.variant_id SET oi.variant_id=NULL WHERE fv.fabric_id=?');$detach->bind_param('i',$id);$detach->execute();
     $stmt=$conn->prepare("UPDATE fabrics SET product_type='simple',status='draft',is_available=0,stock=?,stock_meters=? WHERE id=?");$stmt->bind_param('ddi',$stock,$stockMeters,$id);$stmt->execute();
     $variants=$conn->prepare('DELETE FROM fabric_variants WHERE fabric_id=?');$variants->bind_param('i',$id);$variants->execute();
     log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_mode_changed','product',$id,'Product changed to simple inventory; variants deleted.','ok');
     $conn->commit();
   }catch(Throwable $e){$conn->rollback();throw $e;}
   foreach($variantMedia as $mediaRow){foreach(['image','image2','image3','image4','video'] as $field){$filename=trim((string)($mediaRow[$field]??''));if($filename==='')continue;if($field==='video')@unlink(fabric_upload_path($filename));else image_pipeline_delete_files(dirname(fabric_upload_path($filename)),$filename);}}
   $respond(['ok'=>true,'message'=>'Simple inventory enabled. Review and publish the product when ready.']);
 }
 if($action==='publish'){
   $result=ProductAdminService::publish($conn,$id,(int)$_SESSION['admin_id']);
   if(!$result['ready'])$respond(['ok'=>false,'message'=>'Complete the publishing checklist.']+$result,422);
   if(function_exists('product_feed_refresh_files'))product_feed_refresh_files(['conn'=>$conn]);
   $respond(['ok'=>true,'message'=>'Product published.']+$result);
 }
 if($action==='unpublish'){
   $stmt=$conn->prepare("UPDATE fabrics SET status='draft',is_available=0 WHERE id=?");$stmt->bind_param('i',$id);$stmt->execute();
   log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_unpublished','product',$id,'Product returned to draft.','ok');
   if(function_exists('product_feed_refresh_files'))product_feed_refresh_files(['conn'=>$conn]);
   $respond(['ok'=>true,'message'=>'Product returned to draft.']);
 }
 $respond(['ok'=>false,'message'=>'Unknown action.'],400);
}catch(Throwable $e){error_log('[product-actions] '.$e->getMessage());$respond(['ok'=>false,'message'=>'Unable to update the product.'],500);}
