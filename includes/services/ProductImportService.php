<?php

final class ProductImportService
{
    public const MAX_BYTES = 5242880;
    public const MAX_ROWS = 5000;

    public const HEADERS = [
        'Product Code','Amazon ASIN','Name','Sku Id','Selling Price','MRP','Cost Price','Quantity',
        'Packaging Length (in cm)','Packaging Breadth (in cm)','Packaging Height (in cm)','Packaging Weight (in kg)','GST %',
        'Image 1','Image 2','Image 3','Image 4','Image 5','Image 6','Image 7','Image 8','Image 9','Image 10','Video 1','Video 2',
        'Product Type','Size Type','Size','Colour','Description','Return/Exchange Condition','Visibility','Size Chart','Pickup Address Code',
        'HSN Code','Customisation Id','Associated Pixel','attr_Attribute Name','attr_Brand Name','attr_Type','attr_material',
        'attr_Product Type','attr_Style Name','attr_design','attr_Printing Type','attr_Shape','attr_Pattern','attr_Origin',
        'attr_Thickness & Ply','attr_Disposable Folded','attr_Stain-Resistant','attr_Eco-Friendly','attr_Fabric',
    ];

    private const MAP = [
        'productcode'=>'product_code','amazonasin'=>'amazon_asin','name'=>'name','skuid'=>'sku','sellingprice'=>'sale_price','mrp'=>'price',
        'costprice'=>'cost_price','quantity'=>'quantity','packaginglengthincm'=>'parcel_length_cm','packagingbreadthincm'=>'parcel_width_cm',
        'packagingheightincm'=>'parcel_height_cm','packagingweightinkg'=>'shipping_weight_kg','gst'=>'gst_rate','producttype'=>'category',
        'sizetype'=>'size_type','size'=>'size','colour'=>'color','color'=>'color','description'=>'description',
        'returnexchangecondition'=>'return_exchange_condition','visibility'=>'visibility','sizechart'=>'size_chart',
        'pickupaddresscode'=>'pickup_address_code','hsncode'=>'hsn_code','customisationid'=>'customisation_id',
        'associatedpixel'=>'associated_pixel','attrattributename'=>'attr_attribute_name','attrbrandname'=>'attr_brand_name',
        'attrtype'=>'attr_type','attrmaterial'=>'attr_material','attrproducttype'=>'attr_product_type','attrstylename'=>'attr_style_name',
        'attrdesign'=>'attr_design','attrprintingtype'=>'attr_printing_type','attrshape'=>'attr_shape','attrpattern'=>'attr_pattern',
        'attrorigin'=>'attr_origin','attrthicknessply'=>'attr_thickness_ply','attrdisposablefolded'=>'attr_disposable_folded',
        'attrstainresistant'=>'attr_stain_resistant','attrecofriendly'=>'attr_eco_friendly','attrfabric'=>'attr_fabric',
    ];

    public static function streamTemplate(): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="amber-products-template.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        // Use RFC 4180 escaping explicitly. PHP 8.4 otherwise emits a
        // deprecation notice that can corrupt the downloaded CSV response.
        fputcsv($out, self::HEADERS, ',', '"', '');
        fclose($out);
        exit;
    }

    public static function process(mysqli $conn, array $file, array $options, int $adminId, bool $import): array
    {
        $path = self::validateUpload($file);
        $rows = self::readRows($path);
        $categories = self::categories($conn);
        $mode = in_array(($options['duplicate_mode'] ?? ''), ['skip','update'], true) ? $options['duplicate_mode'] : 'skip';
        $defaultUnit = in_array(($options['default_unit'] ?? ''), ['piece','meter','set'], true) ? $options['default_unit'] : 'piece';
        $results=[];$created=0;$updated=0;$skipped=0;$failed=0;$warnings=0;

        foreach ($rows as $rowNumber => $raw) {
            $row = self::normalizeRow($raw);
            [$data,$errors,$rowWarnings] = self::validateRow($conn,$row,$categories,$defaultUnit);
            $existing = null;
            if (!$errors) {
                try { $existing = self::existing($conn,$data); }
                catch (Throwable $e) { $errors[] = $e->getMessage(); }
            }
            if (!$errors && $existing && $mode === 'skip') {
                $skipped++;
                $results[]=['row'=>$rowNumber,'name'=>$data['name'],'status'=>'skipped','message'=>'SKU or Product Code already exists.'];
                continue;
            }
            if (!$errors && $existing && ($existing['product_type'] ?? 'simple') !== 'simple') {
                $errors[]='Variable products cannot be overwritten by this simple-product catalogue import.';
            }
            if ($errors) {
                $failed++;
                $results[]=['row'=>$rowNumber,'name'=>$row['name'] ?? '','status'=>'error','message'=>implode(' ',array_unique($errors))];
                continue;
            }
            $warnings += count($rowWarnings);
            if (!$import) {
                $results[]=['row'=>$rowNumber,'name'=>$data['name'],'status'=>'valid','message'=>$rowWarnings ? implode(' ', $rowWarnings) : 'Ready to import.'];
                continue;
            }
            $conn->begin_transaction();
            try {
                $id = $existing ? self::updateProduct($conn,(int)$existing['id'],$data) : self::createProduct($conn,$data);
                self::replaceMedia($conn,$id,$data['media']);
                if ($data['requested_status'] === 'active') {
                    $published=ProductAdminService::publish($conn,$id,$adminId);
                    if (empty($published['ready'])) {
                        $rowWarnings[]='Imported as draft: '.implode(' ',array_values((array)($published['checks']??[])));
                    }
                }
                log_admin_activity($conn,$adminId,$existing?'product_import_updated':'product_import_created','product',$id,'Product imported from catalogue CSV.','ok');
                $conn->commit();
                $existing ? $updated++ : $created++;
                $results[]=['row'=>$rowNumber,'name'=>$data['name'],'status'=>$existing?'updated':'created','message'=>$rowWarnings?implode(' ',$rowWarnings):'Imported successfully.','id'=>$id];
            } catch (Throwable $e) {
                $conn->rollback();$failed++;
                $results[]=['row'=>$rowNumber,'name'=>$data['name'],'status'=>'error','message'=>'Import failed: '.$e->getMessage()];
            }
        }
        if ($import && ($created+$updated)>0 && function_exists('product_feed_refresh_files')) product_feed_refresh_files(['conn'=>$conn]);
        return ['total'=>count($rows),'created'=>$created,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$failed,'warnings'=>$warnings,'results'=>$results,'dry_run'=>!$import];
    }

    private static function validateUpload(array $file): string
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Select a CSV file to upload.');
        $size=(int)($file['size']??0);if($size<1||$size>self::MAX_BYTES)throw new InvalidArgumentException('CSV must be between 1 byte and 5 MB.');
        $name=(string)($file['name']??'');if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='csv')throw new InvalidArgumentException('Use a .csv file (Excel: Save As → CSV UTF-8).');
        $path=(string)($file['tmp_name']??'');if(!is_uploaded_file($path) && PHP_SAPI!=='cli')throw new InvalidArgumentException('The uploaded file could not be verified.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,['text/plain','text/csv','application/csv','application/vnd.ms-excel','application/octet-stream'],true))throw new InvalidArgumentException('The uploaded file is not a valid CSV.');
        return $path;
    }

    private static function readRows(string $path): array
    {
        $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException('Could not read the uploaded CSV.');
        $header=fgetcsv($handle,null,',','"','');if(!is_array($header)){fclose($handle);throw new InvalidArgumentException('CSV header row is missing.');}
        $indexes=[];
        foreach($header as $i=>$label){$key=self::headerKey((string)$label);if($key==='')continue;if(isset($indexes[$key])){fclose($handle);throw new InvalidArgumentException('CSV contains a duplicate column: '.$label);}$indexes[$key]=$i;}
        foreach(['name','skuid','mrp','quantity','producttype'] as $required){if(!isset($indexes[$required])){fclose($handle);throw new InvalidArgumentException('CSV is missing required column: '.$required);}}
        $known=array_fill_keys(array_merge(array_keys(self::MAP),array_map(fn($n)=>'image'.$n,range(1,10)),['video1','video2']),true);
        foreach(array_keys($indexes) as $key){if(!isset($known[$key])){fclose($handle);throw new InvalidArgumentException('Unknown CSV column: '.$header[$indexes[$key]]);}}
        $rows=[];$line=1;
        while(($values=fgetcsv($handle,null,',','"',''))!==false){$line++;$nonempty=false;foreach($values as $v){if(trim((string)$v)!==''){$nonempty=true;break;}}if(!$nonempty)continue;
            if(count($rows)>=self::MAX_ROWS){fclose($handle);throw new InvalidArgumentException('CSV exceeds the 5,000 product limit.');}
            $row=[];foreach($indexes as $key=>$index)$row[$key]=trim((string)($values[$index]??''));$rows[$line]=$row;
        }
        fclose($handle);if(!$rows)throw new InvalidArgumentException('The CSV contains no product rows.');return $rows;
    }

    private static function headerKey(string $value): string
    {
        $value=preg_replace('/^\xEF\xBB\xBF/','',$value)??$value;
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/','',$value)??'');
    }

    private static function normalizeRow(array $raw): array
    {
        $row=[];foreach(self::MAP as $csv=>$field)$row[$field]=trim((string)($raw[$csv]??''));
        for($i=1;$i<=10;$i++)$row['image'.$i]=trim((string)($raw['image'.$i]??''));
        for($i=1;$i<=2;$i++)$row['video'.$i]=trim((string)($raw['video'.$i]??''));
        return $row;
    }

    private static function categories(mysqli $conn): array
    {
        $out=[];$result=$conn->query("SELECT name,slug FROM categories WHERE status='active'");
        while($r=$result->fetch_assoc()){foreach([$r['name'],$r['slug']] as $value)$out[self::headerKey((string)$value)]=(string)$r['slug'];}return $out;
    }

    private static function validateRow(mysqli $conn,array $row,array $categories,string $defaultUnit): array
    {
        $errors=[];$warnings=[];$name=mb_substr(trim($row['name']),0,255);if($name==='')$errors[]='Name is required.';
        $sku=ProductAdminService::normalizeSku($row['sku']);if($sku===''||strlen($sku)<3)$errors[]='Sku Id must contain at least 3 valid characters.';
        $code=strtoupper(trim($row['product_code']));if($code!==''&&!preg_match('/^[A-Z0-9_-]{1,100}$/',$code))$errors[]='Product Code has invalid characters.';
        $asin=strtoupper(trim($row['amazon_asin']));if($asin!==''&&!preg_match('/^[A-Z0-9]{10}$/',$asin))$errors[]='Amazon ASIN must contain exactly 10 letters or numbers.';
        $category=$categories[self::headerKey($row['category'])]??'';if($category==='')$errors[]='Product Type must match an active category.';
        $price=self::number($row['price'],'MRP',$errors,true);$sale=self::number($row['sale_price'],'Selling Price',$errors,false,true);$cost=self::number($row['cost_price'],'Cost Price',$errors,false,true)??0.0;
        if($sale!==null&&$price!==null&&$sale>=$price)$errors[]='Selling Price must be below MRP.';
        $qty=self::number($row['quantity'],'Quantity',$errors,false)??0.0;
        $sizeType=mb_strtolower($row['size_type']);$unit=str_contains($sizeType,'meter')?'meter':(str_contains($sizeType,'set')?'set':$defaultUnit);
        if(in_array($unit,['piece','set'],true)&&floor($qty)!=$qty)$errors[]='Quantity must be a whole number for piece/set products.';
        $gst=self::number($row['gst_rate'],'GST %',$errors,false,true);if($gst!==null&&$gst>100)$errors[]='GST % must be between 0 and 100.';
        $hsn=trim($row['hsn_code']);if($hsn!==''&&!preg_match('/^[0-9]{4,8}$/',$hsn))$errors[]='HSN Code must contain 4 to 8 digits.';
        $measure=[];foreach(['parcel_length_cm'=>'Packaging Length','parcel_width_cm'=>'Packaging Breadth','parcel_height_cm'=>'Packaging Height','shipping_weight_kg'=>'Packaging Weight'] as $field=>$label){$measure[$field]=self::number($row[$field],$label,$errors,true,true);}
        $media=[];foreach(array_merge(array_map(fn($n)=>['image'.$n,'image'],range(1,10)),array_map(fn($n)=>['video'.$n,'video'],range(1,2))) as [$field,$type]){
            if($row[$field]==='')continue;$filename=basename((string)(parse_url($row[$field],PHP_URL_PATH)?:$row[$field]));
            if(!preg_match('/^[A-Za-z0-9._-]{1,255}$/',$filename)||!is_file(fabric_upload_path($filename))){$warnings[]=$field.' was not linked because the file is not present in images/fabrics.';continue;}
            $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));$allowed=$type==='image'?['jpg','jpeg','png','webp','gif']:['mp4','webm','mov'];if(!in_array($ext,$allowed,true)){$warnings[]=$field.' has an unsupported file type.';continue;}$media[]=['type'=>$type,'filename'=>$filename];
        }
        $visibility=self::headerKey($row['visibility']);$requested=in_array($visibility,['active','visible','published','publish','yes'],true)?'active':(in_array($visibility,['inactive','hidden','no'],true)?'inactive':'draft');
        $catalog=[];foreach(ProductAdminService::CATALOG_ATTRIBUTE_FIELDS as $field)$catalog[$field]=mb_substr((string)($row[$field]??''),0,1000);
        $data=['name'=>$name,'sku'=>$sku,'product_code'=>$code,'amazon_asin'=>$asin,'category'=>$category,'unit_type'=>$unit,'price'=>$price??0.0,'sale_price'=>$sale,'cost_price'=>$cost,'quantity'=>$qty,'size'=>mb_substr($row['size'],0,100),'color'=>mb_substr($row['color'],0,100),'description'=>trim($row['description']),'hsn_code'=>$hsn,'gst_rate'=>$gst,'requested_status'=>$requested,'media'=>$media,'catalog_data'=>$catalog]+$measure;
        return [$data,$errors,$warnings];
    }

    private static function number(string $raw,string $label,array &$errors,bool $positive=false,bool $optional=false): ?float
    {
        $raw=trim(str_replace([',','₹'], '', $raw));if($raw===''){if(!$optional)$errors[]=$label.' is required.';return null;}if(!is_numeric($raw)){$errors[]=$label.' must be numeric.';return null;}$value=(float)$raw;if(($positive&&$value<=0)||(!$positive&&$value<0))$errors[]=$label.' has an invalid value.';return $value;
    }

    private static function existing(mysqli $conn,array $data): ?array
    {
        $sku=$data['sku'];$code=$data['product_code'];$stmt=$conn->prepare("SELECT id,product_type FROM fabrics WHERE sku=? OR (?<>'' AND product_code=?) LIMIT 2");$stmt->bind_param('sss',$sku,$code,$code);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);if(count($rows)>1)throw new InvalidArgumentException('SKU and Product Code match different existing products.');return $rows[0]??null;
    }

    private static function createProduct(mysqli $conn,array $d): int
    {
        $slug=ProductAdminService::uniqueSlug($conn,'',$d['name']);$status=$d['requested_status']==='inactive'?'inactive':'draft';$available=0;$stock=$d['unit_type']==='meter'?0:$d['quantity'];$meters=$d['unit_type']==='meter'?$d['quantity']:0;$catalog=json_encode($d['catalog_data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$conn->prepare("INSERT INTO fabrics(product_code,amazon_asin,name,sku,slug,category,product_type,unit_type,size,color,description,catalog_data,hsn_code,gst_rate,shipping_weight_kg,parcel_length_cm,parcel_width_cm,parcel_height_cm,price,sale_price,cost_price,stock,stock_meters,min_order_meters,qty_step,status,is_available) VALUES(NULLIF(?,''),NULLIF(?,''),?,?,?,?,'simple',?,?,?,?,?,NULLIF(?,''),?,?,?,?,?,?,?,?,?,?,1,1,?,?)");
        $stmt->bind_param('ssssssssssssddddddddddsi',$d['product_code'],$d['amazon_asin'],$d['name'],$d['sku'],$slug,$d['category'],$d['unit_type'],$d['size'],$d['color'],$d['description'],$catalog,$d['hsn_code'],$d['gst_rate'],$d['shipping_weight_kg'],$d['parcel_length_cm'],$d['parcel_width_cm'],$d['parcel_height_cm'],$d['price'],$d['sale_price'],$d['cost_price'],$stock,$meters,$status,$available);$stmt->execute();return (int)$conn->insert_id;
    }

    private static function updateProduct(mysqli $conn,int $id,array $d): int
    {
        $status=$d['requested_status']==='inactive'?'inactive':'draft';$stock=$d['unit_type']==='meter'?0:$d['quantity'];$meters=$d['unit_type']==='meter'?$d['quantity']:0;$catalog=json_encode($d['catalog_data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$conn->prepare("UPDATE fabrics SET product_code=NULLIF(?,''),amazon_asin=NULLIF(?,''),name=?,sku=?,category=?,product_type='simple',unit_type=?,size=?,color=?,description=?,catalog_data=?,hsn_code=NULLIF(?,''),gst_rate=?,shipping_weight_kg=?,parcel_length_cm=?,parcel_width_cm=?,parcel_height_cm=?,price=?,sale_price=?,cost_price=?,stock=?,stock_meters=?,status=?,is_available=0 WHERE id=?");
        $stmt->bind_param('sssssssssssddddddddddsi',$d['product_code'],$d['amazon_asin'],$d['name'],$d['sku'],$d['category'],$d['unit_type'],$d['size'],$d['color'],$d['description'],$catalog,$d['hsn_code'],$d['gst_rate'],$d['shipping_weight_kg'],$d['parcel_length_cm'],$d['parcel_width_cm'],$d['parcel_height_cm'],$d['price'],$d['sale_price'],$d['cost_price'],$stock,$meters,$status,$id);$stmt->execute();return $id;
    }

    private static function replaceMedia(mysqli $conn,int $id,array $media): void
    {
        if(!$media)return;$stmt=$conn->prepare('DELETE FROM fabric_media WHERE fabric_id=?');$stmt->bind_param('i',$id);$stmt->execute();$sort=['image'=>0,'video'=>0];
        foreach($media as $m){$type=$m['type'];$filename=$m['filename'];$alt='';$primary=$type==='image'&&$sort['image']===0?1:0;$order=$sort[$type]++;$q=$conn->prepare('INSERT INTO fabric_media(fabric_id,media_type,filename,alt_text,is_primary,sort_order) VALUES(?,?,?,?,?,?)');$q->bind_param('isssii',$id,$type,$filename,$alt,$primary,$order);$q->execute();}
    }
}
