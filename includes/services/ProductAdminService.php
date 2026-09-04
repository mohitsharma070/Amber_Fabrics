<?php

final class ProductAdminService
{
    public const CATALOG_ATTRIBUTE_FIELDS = [
        'size_type','return_exchange_condition','size_chart','pickup_address_code',
        'customisation_id','associated_pixel','attr_attribute_name','attr_brand_name',
        'attr_type','attr_material','attr_product_type','attr_style_name','attr_design',
        'attr_printing_type','attr_shape','attr_pattern','attr_origin','attr_thickness_ply',
        'attr_disposable_folded','attr_stain_resistant','attr_eco_friendly','attr_fabric',
    ];

    public static function enabled(): bool
    {
        return (int) plugin_setting('admin-product-editor-v2', 'enabled', 1) === 1;
    }

    public static function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        return trim($value, '-') ?: 'product';
    }

    public static function uniqueSlug(mysqli $conn, string $requested, string $name, int $excludeId = 0): string
    {
        $base = self::slugify($requested !== '' ? $requested : $name);
        $slug = $base;
        $suffix = 2;
        while (true) {
            $stmt = $conn->prepare('SELECT id FROM fabrics WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $slug, $excludeId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                return $slug;
            }
            $slug = substr($base, 0, 180) . '-' . $suffix++;
        }
    }

    public static function normalizeSku(string $sku): string
    {
        $sku = strtoupper(trim($sku));
        $sku = preg_replace('/[^A-Z0-9_-]+/', '-', $sku) ?? '';
        return trim($sku, '-_');
    }

    public static function normalizeDraftUnitType(string $unitType, string $categoryDefaultUnitType): string
    {
        $defaultUnitType = trim($categoryDefaultUnitType);
        return in_array($defaultUnitType, ['meter', 'piece', 'set'], true)
            ? $defaultUnitType
            : trim($unitType);
    }

    public static function categoryDefaultUnitType(mysqli $conn, string $category): string
    {
        $stmt = $conn->prepare("SELECT default_unit_type FROM categories WHERE slug = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('s', $category);
        $stmt->execute();
        return (string) ($stmt->get_result()->fetch_assoc()['default_unit_type'] ?? '');
    }

    public static function publicPath(array $product): string
    {
        $slug = trim((string) ($product['slug'] ?? ''));
        return $slug !== '' ? '/fabric/' . rawurlencode($slug) : '/fabric.php?id=' . (int) ($product['id'] ?? 0);
    }

    public static function skuAvailable(mysqli $conn, string $sku, int $excludeId = 0): bool
    {
        $stmt = $conn->prepare(
            'SELECT sku FROM fabrics WHERE sku = ? AND id <> ?
             UNION ALL
             SELECT sku FROM fabric_variants WHERE sku = ?
             LIMIT 1'
        );
        $stmt->bind_param('sis', $sku, $excludeId, $sku);
        $stmt->execute();
        return !$stmt->get_result()->fetch_assoc();
    }

    public static function catalogData(array $product): array
    {
        $decoded=json_decode((string)($product['catalog_data']??''),true);
        $data=is_array($decoded)?$decoded:[];
        foreach(self::CATALOG_ATTRIBUTE_FIELDS as $field)$data[$field]=trim((string)($data[$field]??''));
        return $data;
    }

    public static function validateCatalog(mysqli $conn, array $input, int $excludeId = 0): array
    {
        $errors=[];$code=strtoupper(trim((string)($input['product_code']??'')));$asin=strtoupper(trim((string)($input['amazon_asin']??'')));
        if($code!==''&&!preg_match('/^[A-Z0-9_-]{1,100}$/',$code))$errors['product_code']='Product Code may contain uppercase letters, numbers, hyphens and underscores.';
        if($code!==''){$stmt=$conn->prepare('SELECT id FROM fabrics WHERE product_code=? AND id<>? LIMIT 1');$stmt->bind_param('si',$code,$excludeId);$stmt->execute();if($stmt->get_result()->fetch_assoc())$errors['product_code']='This Product Code is already in use.';}
        if($asin!==''&&!preg_match('/^[A-Z0-9]{10}$/',$asin))$errors['amazon_asin']='Amazon ASIN must contain exactly 10 letters or numbers.';
        return ['errors'=>$errors,'product_code'=>$code,'amazon_asin'=>$asin];
    }

    public static function saveCatalog(mysqli $conn, int $fabricId, array $input): array
    {
        $validation=self::validateCatalog($conn,$input,$fabricId);if($validation['errors'])return $validation;
        $data=[];foreach(self::CATALOG_ATTRIBUTE_FIELDS as $field)$data[$field]=substr(trim((string)($input[$field]??'')),0,1000);
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Could not encode catalogue attributes.');
        $code=$validation['product_code'];$asin=$validation['amazon_asin'];
        $stmt=$conn->prepare("UPDATE fabrics SET product_code=NULLIF(?,''),amazon_asin=NULLIF(?,''),catalog_data=? WHERE id=?");$stmt->bind_param('sssi',$code,$asin,$json,$fabricId);$stmt->execute();
        return $validation+['catalog_data'=>$data];
    }

    public static function validateExtended(mysqli $conn, array $input, int $excludeId = 0, bool $forPublish = false): array
    {
        $errors = [];
        $warnings = [];
        $sku = self::normalizeSku((string) ($input['sku'] ?? ''));
        if ($sku === '' || strlen($sku) < 3 || strlen($sku) > 100) {
            $errors['sku'] = 'SKU must contain 3 to 100 letters, numbers, hyphens or underscores.';
        } elseif (!self::skuAvailable($conn, $sku, $excludeId)) {
            $errors['sku'] = 'This SKU is already in use.';
        }
        $price = (float) ($input['price'] ?? 0);
        $sale = trim((string) ($input['sale_price'] ?? ''));
        if ($forPublish && $price <= 0) $errors['price'] = 'Regular price must be greater than zero before publishing.';
        if ($sale !== '' && ((float) $sale <= 0 || ($price > 0 && (float) $sale >= $price))) {
            $errors['sale_price'] = 'Sale price must be greater than zero and below regular price.';
        }
        $effective = $sale !== '' ? (float) $sale : $price;
        if (is_numeric($input['cost_price'] ?? null) && (float) $input['cost_price'] > $effective && $effective > 0) {
            $warnings['cost_price'] = 'Cost price is above the current selling price.';
        }
        $dispatchMin = trim((string) ($input['dispatch_min_days'] ?? ''));
        $dispatchMax = trim((string) ($input['dispatch_max_days'] ?? ''));
        if ($dispatchMin !== '' && $dispatchMax !== '' && (int) $dispatchMax < (int) $dispatchMin) $errors['dispatch_max_days'] = 'Dispatch maximum cannot be below minimum.';
        $hsn = trim((string) ($input['hsn_code'] ?? ''));
        if ($hsn !== '' && !preg_match('/^[0-9]{4,8}$/', $hsn)) $errors['hsn_code'] = 'HSN must contain 4 to 8 digits.';
        $gst = trim((string) ($input['gst_rate'] ?? ''));
        if ($gst !== '' && (!is_numeric($gst) || (float) $gst < 0 || (float) $gst > 100)) $errors['gst_rate'] = 'GST must be between 0 and 100.';
        foreach (['shipping_weight_kg','parcel_length_cm','parcel_width_cm','parcel_height_cm'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '' && (!is_numeric($value) || (float) $value <= 0)) $errors[$field] = 'Enter a positive value or leave blank to use the global default.';
        }
        $unit=in_array(($input['unit_type']??''),['meter','piece','set'],true)?(string)$input['unit_type']:'';
        if($unit==='')$errors['unit_type']='Select a valid unit type.';
        $stock=(float)($input['stock']??0);$minimum=(float)($input['min_order_meters']??0);$step=trim((string)($input['qty_step']??''));
        if($stock<0)$errors['stock']='Stock cannot be negative.';
        if(in_array($unit,['piece','set'],true)){
            if(floor($stock)!=$stock)$errors['stock']='Piece and set stock must be a whole number.';
            if($minimum>0&&floor($minimum)!=$minimum)$errors['min_order_meters']='Piece and set minimums must be whole numbers.';
            if($step!==''&&(!is_numeric($step)||(float)$step<1||floor((float)$step)!=(float)$step))$errors['qty_step']='Piece and set quantity steps must be whole numbers.';
        }elseif($unit==='meter'&&$step!==''){
            if(!is_numeric($step)||(float)$step<=0)$errors['qty_step']='Meter quantity step must be greater than zero.';
            elseif(!meter_qty_step_is_representable($step))$errors['qty_step']='Meter quantity step must use no more than four decimal places.';
        }
        return ['errors' => $errors, 'warnings' => $warnings, 'sku' => $sku];
    }

    public static function media(mysqli $conn, int $fabricId): array
    {
        $stmt = $conn->prepare('SELECT * FROM fabric_media WHERE fabric_id = ? ORDER BY media_type, is_primary DESC, sort_order, id');
        $stmt->bind_param('i', $fabricId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function syncLegacyMedia(mysqli $conn, int $fabricId): void
    {
        // Media is stored exclusively in fabric_media. Kept as a no-op for callers
        // during the editor-v2 rollout so old deployments can update safely.
    }

    public static function readiness(mysqli $conn, int $fabricId): array
    {
        $stmt = $conn->prepare('SELECT * FROM fabrics WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $fabricId); $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        if (!$p) return ['ready'=>false,'checks'=>['product'=>'Product not found.']];
        $validation = self::validateExtended($conn, $p, $fabricId, true);
        $checks = $validation['errors'];
        if (trim((string)($p['name']??'')) === '') $checks['name']='Product name is required.';
        if (trim((string)($p['category']??'')) === '') $checks['category']='Category is required.';
        else {
            $category=(string)$p['category'];$q=$conn->prepare("SELECT id FROM categories WHERE slug=? AND status='active' LIMIT 1");$q->bind_param('s',$category);$q->execute();
            if(!$q->get_result()->fetch_assoc())$checks['category']='Select an active storefront category.';
        }
        if (mb_strlen(trim((string)($p['description']??''))) < 20) $checks['description']='Add a useful product description of at least 20 characters.';
        $media = self::media($conn, $fabricId);
        $hasImage = false; foreach ($media as $m) if ($m['media_type']==='image') {$hasImage=true;break;}
        if (!$hasImage) $checks['media']='A main product image is required.';
        if (($p['product_type']??'simple') === 'variable') {
            $q=$conn->prepare("SELECT COUNT(*) c FROM fabric_variants WHERE fabric_id=? AND is_active=1 AND (CASE WHEN ?='meter' THEN stock_meters ELSE stock END)>0");
            $unit=(string)$p['unit_type'];$q->bind_param('is',$fabricId,$unit);$q->execute();
            if ((int)($q->get_result()->fetch_assoc()['c']??0)<1) $checks['variants']='Add at least one active variant with stock.';
        } else {
            $stock=($p['unit_type']==='meter')?(float)$p['stock_meters']:(float)$p['stock'];
            if ($stock<=0) $checks['stock']='Simple products require sellable stock.';
        }
        return ['ready'=>$checks===[],'checks'=>$checks,'warnings'=>$validation['warnings']];
    }

    public static function createDraft(mysqli $conn, array $input, int $adminId): int
    {
        $name = trim((string)($input['name']??''));
        $category = trim((string)($input['category']??''));
        $categoryDefaultUnit = self::categoryDefaultUnitType($conn, $category);
        $requestedUnit = self::normalizeDraftUnitType((string)($input['unit_type']??''), $categoryDefaultUnit);
        $unit = in_array($requestedUnit,['meter','piece','set'],true)?$requestedUnit:'meter';
        $type = in_array(($input['product_type']??''),['simple','variable'],true)?(string)$input['product_type']:'simple';
        if ($name===''||$category==='') throw new InvalidArgumentException('Product name and category are required.');
        $sku=self::normalizeSku((string)($input['sku']??''));
        if ($sku==='') $sku=generate_unique_fabric_sku($conn,$category,'product','','');
        if (!self::skuAvailable($conn,$sku)) throw new InvalidArgumentException('This SKU is already in use.');
        $slug=self::uniqueSlug($conn,(string)($input['slug']??''),$name);
        $conn->begin_transaction();
        try {
            $stmt=$conn->prepare("INSERT INTO fabrics(name,sku,slug,category,product_type,unit_type,status,is_available,price,cost_price,stock,stock_meters,min_order_meters) VALUES(?,?,?,?,?,?,'draft',0,0,0,0,0,1)");
            $stmt->bind_param('ssssss',$name,$sku,$slug,$category,$type,$unit);$stmt->execute();
            $id=(int)$conn->insert_id;
            log_admin_activity($conn,$adminId,'product_draft_created','product',$id,'Draft product created.','ok');
            $conn->commit(); return $id;
        } catch(Throwable $e) { $conn->rollback(); throw $e; }
    }

    public static function saveExtended(mysqli $conn, int $fabricId, array $input): array
    {
        $validation=self::validateExtended($conn,$input,$fabricId,false);
        if ($validation['errors']) return $validation;
        $type=in_array(($input['product_type']??''),['simple','variable'],true)?(string)$input['product_type']:'simple';
        $name=trim((string)($input['name']??''));
        $slug=self::uniqueSlug($conn,(string)($input['slug']??''),$name,$fabricId);
        $sku=$validation['sku'];
        $hsn=trim((string)($input['hsn_code']??''));
        $gst=trim((string)($input['gst_rate']??''));$gstVal=$gst===''?null:(float)$gst;
        $weight=trim((string)($input['shipping_weight_kg']??''));$weightVal=$weight===''?null:(float)$weight;
        $length=trim((string)($input['parcel_length_cm']??''));$lengthVal=$length===''?null:(float)$length;
        $width=trim((string)($input['parcel_width_cm']??''));$widthVal=$width===''?null:(float)$width;
        $height=trim((string)($input['parcel_height_cm']??''));$heightVal=$height===''?null:(float)$height;
        $currentStmt=$conn->prepare('SELECT product_type FROM fabrics WHERE id=? LIMIT 1');
        $currentStmt->bind_param('i',$fabricId);$currentStmt->execute();
        $oldType=(string)($currentStmt->get_result()->fetch_assoc()['product_type']??'simple');
        $stmt=$conn->prepare("UPDATE fabrics SET product_type=?,sku=?,slug=?,hsn_code=NULLIF(?,''),gst_rate=?,shipping_weight_kg=?,parcel_length_cm=?,parcel_width_cm=?,parcel_height_cm=? WHERE id=?");
        $stmt->bind_param('ssssdddddi',$type,$sku,$slug,$hsn,$gstVal,$weightVal,$lengthVal,$widthVal,$heightVal,$fabricId);
        $stmt->execute();
        if($oldType!==$type){
            if($type==='variable'){
                $q=$conn->prepare("UPDATE fabrics SET status='draft',is_available=0,stock=0,stock_meters=0 WHERE id=?");
            }else{
                $q=$conn->prepare("UPDATE fabrics f LEFT JOIN fabric_variants v ON v.fabric_id=f.id SET f.status='draft',f.is_available=0,v.is_active=0 WHERE f.id=?");
            }
            $q->bind_param('i',$fabricId);$q->execute();
        }
        return $validation+['slug'=>$slug];
    }

    public static function publish(mysqli $conn, int $fabricId, int $adminId): array
    {
        $readiness=self::readiness($conn,$fabricId);
        if (!$readiness['ready']) return $readiness;
        $stmt=$conn->prepare("UPDATE fabrics SET status='active',is_available=1,published_at=COALESCE(published_at,NOW()),published_by=? WHERE id=?");
        $stmt->bind_param('ii',$adminId,$fabricId);$stmt->execute();
        log_admin_activity($conn,$adminId,'product_published','product',$fabricId,'Product published.','ok');
        return $readiness;
    }
}
