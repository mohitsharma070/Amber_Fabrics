<?php
require_once __DIR__.'/../includes/init.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
$out=static function(array $d,int $s=200):never{http_response_code($s);echo json_encode($d);exit;};
$id=(int)($_REQUEST['product_id']??0);$action=(string)($_REQUEST['action']??'list');
if($id<=0)$out(['ok'=>false,'message'=>'Invalid product.'],422);
$exists=$conn->prepare('SELECT id,name FROM fabrics WHERE id=? LIMIT 1');$exists->bind_param('i',$id);$exists->execute();$product=$exists->get_result()->fetch_assoc();if(!$product)$out(['ok'=>false,'message'=>'Product not found.'],404);
if($action==='list')$out(['ok'=>true,'media'=>ProductAdminService::media($conn,$id)]);
if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf())$out(['ok'=>false,'message'=>'Invalid request.'],403);
try{
 if($action==='upload'){
   $type=($_POST['media_type']??'image')==='video'?'video':'image';$current=ProductAdminService::media($conn,$id);$images=0;$videos=0;foreach($current as $m){$m['media_type']==='image'?$images++:$videos++;}
   $maxImages=max(1,(int)plugin_setting('admin-product-editor-v2','max_gallery_images',10));$maxVideos=max(1,(int)plugin_setting('admin-product-editor-v2','max_product_videos',2));
   if($type==='image'&&$images>=$maxImages)$out(['ok'=>false,'message'=>'A product can have up to '.$maxImages.' images.'],422);
   if($type==='video'&&$videos>=$maxVideos)$out(['ok'=>false,'message'=>'A product can have up to '.$maxVideos.' videos.'],422);
   $file=$_FILES['file']??[];$saved='';
   if($type==='image'){$saved=save_fabric_image_upload($file,'Product image');}
   else{
     if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size']??0)>25*1024*1024)throw new RuntimeException('Video upload failed or exceeds 25MB.');
     $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));$mime=mime_content_type((string)($file['tmp_name']??''))?:'';
     if(!in_array($ext,['mp4','webm','ogg'],true)||!in_array($mime,['video/mp4','video/webm','video/ogg'],true))throw new RuntimeException('Video must be MP4, WEBM or OGG.');
     $saved=random_filename((string)$file['name']);if(!move_uploaded_file((string)$file['tmp_name'],fabric_upload_path($saved)))throw new RuntimeException('Could not save video.');
   }
   try{$sort=$type==='image'?$images:$videos;$primary=($type==='image'&&$images===0)?1:0;$alt=substr(trim((string)($_POST['alt_text']??$product['name'])),0,255);$stmt=$conn->prepare('INSERT INTO fabric_media(fabric_id,media_type,filename,alt_text,is_primary,sort_order) VALUES(?,?,?,?,?,?)');$stmt->bind_param('isssii',$id,$type,$saved,$alt,$primary,$sort);$stmt->execute();ProductAdminService::syncLegacyMedia($conn,$id);}catch(Throwable $e){image_pipeline_delete_files(fabric_upload_directory(),$saved);throw $e;}
   log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_media_uploaded','product',$id,'Product media uploaded.','ok');if(function_exists('product_feed_refresh_files'))product_feed_refresh_files(['conn'=>$conn]);$out(['ok'=>true,'media'=>ProductAdminService::media($conn,$id)]);
 }
 if($action==='reorder'){$ids=array_values(array_filter(array_map('intval',(array)($_POST['media_ids']??[]))));$conn->begin_transaction();$sort=0;foreach($ids as $orderedId){$q=$conn->prepare('UPDATE fabric_media SET sort_order=? WHERE id=? AND fabric_id=?');$q->bind_param('iii',$sort,$orderedId,$id);$q->execute();$sort++;}ProductAdminService::syncLegacyMedia($conn,$id);$conn->commit();log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_media_reordered','product',$id,'Product media reordered.','ok');if(function_exists('product_feed_refresh_files'))product_feed_refresh_files(['conn'=>$conn]);$out(['ok'=>true,'media'=>ProductAdminService::media($conn,$id)]);}
 $mediaId=(int)($_POST['media_id']??0);$rowStmt=$conn->prepare('SELECT * FROM fabric_media WHERE id=? AND fabric_id=? LIMIT 1');$rowStmt->bind_param('ii',$mediaId,$id);$rowStmt->execute();$row=$rowStmt->get_result()->fetch_assoc();if(!$row)$out(['ok'=>false,'message'=>'Media not found.'],404);
 if($action==='update'){$alt=substr(trim((string)($_POST['alt_text']??'')),0,255);$setPrimary=$row['media_type']==='image'&&!empty($_POST['set_primary']);$conn->begin_transaction();if($setPrimary){$q=$conn->prepare("UPDATE fabric_media SET is_primary=0 WHERE fabric_id=? AND media_type='image'");$q->bind_param('i',$id);$q->execute();$q=$conn->prepare('UPDATE fabric_media SET alt_text=?,is_primary=1 WHERE id=? AND fabric_id=?');}else{$q=$conn->prepare('UPDATE fabric_media SET alt_text=? WHERE id=? AND fabric_id=?');}$q->bind_param('sii',$alt,$mediaId,$id);$q->execute();ProductAdminService::syncLegacyMedia($conn,$id);$conn->commit();$out(['ok'=>true,'media'=>ProductAdminService::media($conn,$id)]);}
 if($action==='delete'){$conn->begin_transaction();$q=$conn->prepare('DELETE FROM fabric_media WHERE id=? AND fabric_id=?');$q->bind_param('ii',$mediaId,$id);$q->execute();if((int)$row['is_primary']===1){$q=$conn->prepare("UPDATE fabric_media SET is_primary=1 WHERE fabric_id=? AND media_type='image' ORDER BY sort_order,id LIMIT 1");$q->bind_param('i',$id);$q->execute();}ProductAdminService::syncLegacyMedia($conn,$id);$conn->commit();image_pipeline_delete_files(fabric_upload_directory(),(string)$row['filename']);log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_media_deleted','product',$id,'Product media removed.','ok');if(function_exists('product_feed_refresh_files'))product_feed_refresh_files(['conn'=>$conn]);$out(['ok'=>true,'media'=>ProductAdminService::media($conn,$id)]);}
 $out(['ok'=>false,'message'=>'Unknown action.'],400);
}catch(Throwable $e){try{$conn->rollback();}catch(Throwable $ignored){}error_log('[product-media] '.$e->getMessage());$out(['ok'=>false,'message'=>$e->getMessage()],422);}
