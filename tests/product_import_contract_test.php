<?php
$root=dirname(__DIR__);$fail=[];$assert=static function(bool $ok,string $message)use(&$fail){if(!$ok)$fail[]=$message;};
require_once $root.'/includes/services/ProductImportService.php';
require_once $root.'/includes/services/ProductAdminService.php';
require_once $root.'/includes/services/CartService.php';
require_once $root.'/includes/helpers/core.php';
$page=(string)file_get_contents($root.'/admin/product-import.php');
$list=(string)file_get_contents($root.'/admin/fabrics.php');
$service=(string)file_get_contents($root.'/includes/services/ProductImportService.php');
$openapi=(string)file_get_contents($root.'/openapi.yaml');
$architecture=(string)file_get_contents($root.'/docs/repo-architecture.md');

$assert(count(ProductImportService::HEADERS)===57,'Catalogue workbook must contain all 57 supported product columns.');
$assert(in_array('Product Code',ProductImportService::HEADERS,true)&&in_array('attr_Fabric',ProductImportService::HEADERS,true),'Catalogue template endpoints are missing.');
$assert(in_array('Selling Unit',ProductImportService::HEADERS,true),'Catalogue template must expose a per-row Selling Unit column.');
$assert(ProductImportService::HEADERS[0]==='Product ID'&&ProductImportService::HEADERS[1]==='Product Revision'&&in_array('Meter Length Options',ProductImportService::HEADERS,true),'Round-trip identifiers, revisions, or meter options are missing from the catalogue.');
$assert(str_contains($page,'require_admin()')&&str_contains($page,'verify_csrf()'),'Importer must require admin authentication and CSRF validation.');
$assert(str_contains($page,'Validate Only')&&str_contains($page,'Validate, Import &amp; Publish Active'),'Importer must separate validation from readiness-checked writes and publishing.');
$assert(str_contains($page,"'product_catalogue_import','product',0,"),'Batch imports must use the integer no-target sentinel required by the admin activity logger.');
$assert(str_contains($list,'product-import.php')&&str_contains($page,'download=products')&&str_contains($page,'Download Current Products'),'Products administration must expose the catalogue round-trip export.');
$assert(str_contains($service,'is_uploaded_file')&&str_contains($service,'MAX_ROWS')&&str_contains($service,'finfo'),'Upload size, row count and MIME protections are missing.');
$assert(ProductImportService::MAX_BYTES===10485760&&str_contains($service,'$workbookSize > self::MAX_BYTES')&&str_contains($page,'Maximum 10 MB'),'Generated workbooks and uploads must share the documented 10 MB limit.');
$assert(str_contains($service,"['skip','update']")&&str_contains($service,'begin_transaction()')&&str_contains($service,'rollback()'),'Duplicate modes or per-row transaction handling is missing.');
$assert(str_contains($service,'fabric_upload_path')&&!str_contains($service,'file_get_contents($row'),'Media import must only reference safe existing files and never fetch arbitrary URLs.');
$assert(str_contains($service,'ProductAdminService::publish')&&str_contains($service,'Imported as draft'),'Visible products must pass normal readiness checks or remain drafts.');
$assert(str_contains($service,"if (\$data['requested_status'] === 'active')")&&!str_contains($service,"if (!\$existing && \$data['requested_status'] === 'active')"),'Active visibility must use the normal publish-readiness service for both new and existing imports.');
$assert(str_contains($service,'SELECT name,slug,default_unit_type,status FROM categories')&&str_contains($service,'ProductAdminService::normalizeDraftUnitType((string)$unit,$categoryDefaultUnit)'),'Catalogue imports must enforce category-required selling units while retaining inactive-category status.');
$assert(str_contains($service,'Selling Unit changed to ')&&str_contains($service,'because the selected product type requires it.'),'CSV validation must explain category-driven unit overrides.');
$assert(str_contains($page,'Fallback selling unit')&&str_contains($page,'Used only when a row has no Selling Unit'),'Importer UI must explain that the selected unit is only a fallback.');
$assert(str_contains($service,"WHERE product_type='simple'")&&str_contains($service,'amber-products-')&&str_contains($service,"status='draft',is_available=0"),'Current-product export or draft-safe update behavior is missing.');
$assert(str_contains($service,'assertUniqueIdentifiers($rows)')&&str_contains($service,"throw new InvalidArgumentException('Product ID '"),'Round-trip imports must reject duplicate or unknown immutable identifiers.');
$assert(str_contains($page,'ProductImportService::CLEAR_MARKER')&&str_contains($service,"'media_action'=>\$mediaAction"),'Explicit optional-field and media clearing semantics are missing.');
$assert(
    str_contains($page,'existing legacy meter product')
        && str_contains($openapi,'legacy meter product')
        && str_contains($architecture,'legacy meter products'),
    'Catalogue UI and endpoint documentation must explain legacy blank meter-option round trips.'
);
$assert(str_contains($service,"array_merge(\$existingCatalog,\$data['catalog_data'])")&&str_contains($service,'$altByMedia'),'Round-trip updates must retain unknown catalogue metadata and alt text for media that remains attached.');
$assert(str_contains($service,'mediaMatches($conn')&&str_contains($service,'more than 10 images or 2 videos'),'Unchanged or unrepresentable product media must not be destructively rewritten by a round trip.');
$assert(str_contains($service,'assertCurrentRevision')&&str_contains($service,'hash_equals')&&str_contains($service,'FOR UPDATE'),'Round-trip updates must reject stale product revisions again inside the write transaction.');
$assert(str_contains($page,'catch (ProductCatalogueException $e)')&&str_contains($page,"flash('error', \$e->getMessage())"),'Expected export-limit failures must give administrators an actionable message.');
$assert(str_contains($openapi,'enum: [template, products]')&&str_contains($architecture,'immutable Product IDs'),'The product export endpoint contract and architecture documentation must describe round-trip exports.');
$assert(str_contains($openapi,'requested Visibility is active')&&str_contains($architecture,'requested `Visibility` is `active`'),'Import documentation must describe readiness-checked publishing for active new and existing rows.');
$assert(str_contains($service,'normalizeXlsxZipHeaders')&&str_contains($openapi,'desktop Excel-compatible')&&str_contains($architecture,'without recovery'),'Product exports must declare desktop Excel-compatible ZIP metadata and document the compatibility contract.');

$method=new ReflectionMethod(ProductImportService::class,'headerKey');$method->setAccessible(true);
$assert($method->invoke(null,"\xEF\xBB\xBFProduct Code")==='productcode','UTF-8 BOM header normalization failed.');
$assert($method->invoke(null,'attr_Thickness?&?Ply')==='attrthicknessply','Shared malformed thickness header must remain compatible.');
$unitMethod=new ReflectionMethod(ProductImportService::class,'sellingUnit');$unitMethod->setAccessible(true);
$assert($unitMethod->invoke(null,'Pieces')==='piece','Piece selling-unit alias normalization failed.');
$assert($unitMethod->invoke(null,'metres')==='meter','Meter selling-unit alias normalization failed.');
$assert($unitMethod->invoke(null,'SETS')==='set','Set selling-unit alias normalization failed.');
$assert($unitMethod->invoke(null,'pair')===null,'Unsupported selling units must not silently fall back.');
$parseXlsxRow=new ReflectionMethod(ProductImportService::class,'parseXlsxRow');$parseXlsxRow->setAccessible(true);
$prefixedRow='<x:row xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main" r="2"><x:c r="E2" t="inlineStr"><x:is><x:t xml:space="preserve">Prefixed Product</x:t></x:is></x:c><x:c r="F2" t="inlineStr"><x:is><x:t>SKU-001</x:t></x:is></x:c></x:row>';
$prefixedValues=$parseXlsxRow->invoke(null,$prefixedRow,[]);
$assert(($prefixedValues[4]??'')==='Prefixed Product'&&($prefixedValues[5]??'')==='SKU-001','Excel imports must read inline strings from namespace-prefixed worksheet XML.');
$merge=new ReflectionMethod(ProductImportService::class,'applyRoundTripSemantics');$merge->setAccessible(true);
$existing=['id'=>7,'product_code'=>'OLD-7','amazon_asin'=>null,'name'=>'Existing Product','sku'=>'OLD-SKU','category'=>'fabric-by-meter','product_type'=>'simple','unit_type'=>'meter','meter_options'=>'10, 20','price'=>100,'sale_price'=>90,'cost_price'=>50,'stock'=>0,'stock_meters'=>20,'size'=>'','color'=>'Blue','description'=>'Existing description','catalog_data'=>'{}','hsn_code'=>null,'gst_rate'=>5,'shipping_weight_kg'=>1,'parcel_length_cm'=>10,'parcel_width_cm'=>10,'parcel_height_cm'=>10,'status'=>'active'];
[$merged,$mergeErrors]=$merge->invoke(null,['product_id'=>'7','product_code'=>ProductImportService::CLEAR_MARKER,'sku'=>'NEW-SKU','name'=>''], $existing);
$assert($mergeErrors===[]&&$merged['name']==='Existing Product'&&$merged['sku']==='NEW-SKU'&&$merged['product_code']==='','Blank update cells must retain current values while the explicit marker clears optional values.');
$uniqueIdentifiers=new ReflectionMethod(ProductImportService::class,'assertUniqueIdentifiers');$uniqueIdentifiers->setAccessible(true);
$duplicateRejected=false;try{$uniqueIdentifiers->invoke(null,[2=>['productid'=>'7','skuid'=>'ONE'],3=>['productid'=>'7','skuid'=>'TWO']]);}catch(ReflectionException $e){throw $e;}catch(Throwable $e){$duplicateRejected=str_contains($e->getMessage(),'duplicated in CSV rows 2 and 3');}
$assert($duplicateRejected,'Duplicate Product IDs in one upload must be rejected before any row can be written.');
$clearMarkerAccepted=true;try{$uniqueIdentifiers->invoke(null,[2=>['productid'=>'7','skuid'=>'ONE','productcode'=>ProductImportService::CLEAR_MARKER],3=>['productid'=>'8','skuid'=>'TWO','productcode'=>ProductImportService::CLEAR_MARKER]]);}catch(Throwable $e){$clearMarkerAccepted=false;}
$assert($clearMarkerAccepted,'The explicit clear marker must not be mistaken for a duplicate Product Code.');

$createXlsx=new ReflectionMethod(ProductImportService::class,'createXlsx');$createXlsx->setAccessible(true);
$readRows=new ReflectionMethod(ProductImportService::class,'readRows');$readRows->setAccessible(true);
$normalizeRow=new ReflectionMethod(ProductImportService::class,'normalizeRow');$normalizeRow->setAccessible(true);
$workbookRow=array_fill(0,count(ProductImportService::HEADERS),'');
$workbookValues=['Product ID'=>'7','Product Revision'=>str_repeat('a',64),'Product Code'=>'000045','Amazon ASIN'=>'0000000123','Name'=>'Text-safe Product','Sku Id'=>'000123','MRP'=>'100','Quantity'=>'20','Selling Unit'=>'meter','Meter Length Options'=>'10, 20','Product Type'=>'fabric-by-meter','Description'=>'=HYPERLINK("https://invalid.test")'];
foreach($workbookValues as $header=>$value)$workbookRow[array_search($header,ProductImportService::HEADERS,true)]=$value;
$workbookPath='';
try{
    $workbookPath=$createXlsx->invoke(null,[ProductImportService::HEADERS,$workbookRow]);
    $zipBytes=(string)file_get_contents($workbookPath);
    $localHeader=strpos($zipBytes,"PK\x03\x04");$centralHeader=strpos($zipBytes,"PK\x01\x02");
    $localVersion=$localHeader===false?0:(int)(unpack('vvalue',substr($zipBytes,$localHeader+4,2))['value']??0);
    $madeByVersion=$centralHeader===false?0:(int)(unpack('vvalue',substr($zipBytes,$centralHeader+4,2))['value']??0);
    $centralVersion=$centralHeader===false?0:(int)(unpack('vvalue',substr($zipBytes,$centralHeader+6,2))['value']??0);
    $assert($localVersion>=10&&$madeByVersion>=10&&$centralVersion>=10,'Generated Excel ZIP headers must declare a standard non-zero extraction version for desktop Excel compatibility.');
    $workbookRows=$readRows->invoke(null,$workbookPath,'xlsx');$roundTrip=$normalizeRow->invoke(null,reset($workbookRows));
    $assert(($roundTrip['sku']??'')==='000123'&&($roundTrip['product_code']??'')==='000045'&&($roundTrip['amazon_asin']??'')==='0000000123','Excel round trips must preserve leading-zero identifiers as text.');
    $assert(($roundTrip['product_revision']??'')===str_repeat('a',64),'Excel round trips must preserve immutable revision tokens.');
    $assert(($roundTrip['description']??'')==='=HYPERLINK("https://invalid.test")','Excel product values must round-trip as inert text rather than formulas.');
}catch(Throwable $e){$fail[]='Generated Excel workbook could not be read: '.$e->getMessage();}
finally{if($workbookPath!==''&&is_file($workbookPath))unlink($workbookPath);}

$revisionToken=new ReflectionMethod(ProductImportService::class,'revisionToken');$revisionToken->setAccessible(true);
$existing['updated_at']='2026-08-26 12:00:00';$revisionA=$revisionToken->invoke(null,$existing,['image'=>['one.jpg'],'video'=>[]]);$changed=$existing;$changed['price']=101;$revisionB=$revisionToken->invoke(null,$changed,['image'=>['one.jpg'],'video'=>[]]);$revisionC=$revisionToken->invoke(null,$existing,['image'=>['two.jpg'],'video'=>[]]);
$assert(strlen($revisionA)===64&&!hash_equals($revisionA,$revisionB)&&!hash_equals($revisionA,$revisionC),'Product revisions must change when exported scalar or media state changes.');

$validateRow=new ReflectionMethod(ProductImportService::class,'validateRow');$validateRow->setAccessible(true);
$validationInput=$normalizeRow->invoke(null,['name'=>'Archived Product','skuid'=>'ARC-100','mrp'=>'100','quantity'=>'20','sellingunit'=>'meter','meterlengthoptions'=>'10,20','producttype'=>'archived-category']);
$inactiveCategories=['archivedcategory'=>['slug'=>'archived-category','default_unit_type'=>'piece','status'=>'inactive']];$validationConn=mysqli_init();
[$preservedData,$preservedErrors]=$validateRow->invoke(null,$validationConn,$validationInput,$inactiveCategories,'piece',array_merge($existing,['category'=>'archived-category']));
[, $newInactiveErrors]=$validateRow->invoke(null,$validationConn,$validationInput,$inactiveCategories,'piece',null);
$assert($preservedData['category']==='archived-category'&&$preservedData['unit_type']==='meter'&&!str_contains(implode(' ',$preservedErrors),'active category'),'An unchanged inactive category and its existing unit must remain valid for an existing product.');
$assert(str_contains(implode(' ',$newInactiveErrors),'active category'),'New products must not be assigned to inactive categories.');

$activeMeterCategories=['fabricbymeter'=>['slug'=>'fabric-by-meter','default_unit_type'=>'meter','status'=>'active']];
$legacyStepInput=$normalizeRow->invoke(null,['name'=>'Legacy Step Product','skuid'=>'LEGACY-STEP','mrp'=>'100','quantity'=>'20','sellingunit'=>'meter','meterlengthoptions'=>'2.5,5','producttype'=>'fabric-by-meter']);
[, $legacyStepErrors]=$validateRow->invoke(null,$validationConn,$legacyStepInput,$activeMeterCategories,'piece',array_merge($existing,['qty_step'=>'0.00001']));
$assert(str_contains(implode(' ',$legacyStepErrors),'Meter Quantity Step must use no more than four decimal places'),'Catalogue validation must reject an existing unrepresentable meter step before writing the row.');
$legacyMeterExisting=array_merge($existing,['meter_options'=>'']);
$legacyMeterWorkbookRow=array_fill(0,count(ProductImportService::HEADERS),'');
$legacyMeterWorkbookValues=['Product ID'=>'7','Product Revision'=>$revisionToken->invoke(null,$legacyMeterExisting,['image'=>[],'video'=>[]]),'Name'=>'Existing Product','Sku Id'=>'OLD-SKU','MRP'=>'100','Quantity'=>'20','Selling Unit'=>'meter','Meter Length Options'=>'','Product Type'=>'fabric-by-meter'];
foreach($legacyMeterWorkbookValues as $header=>$value)$legacyMeterWorkbookRow[array_search($header,ProductImportService::HEADERS,true)]=$value;
$legacyMeterWorkbookPath='';
try{
    $legacyMeterWorkbookPath=$createXlsx->invoke(null,[ProductImportService::HEADERS,$legacyMeterWorkbookRow]);
    $legacyMeterWorkbookRows=$readRows->invoke(null,$legacyMeterWorkbookPath,'xlsx');
    $legacyMeterInput=$normalizeRow->invoke(null,reset($legacyMeterWorkbookRows));
}catch(Throwable $e){$fail[]='Legacy meter export could not be read back: '.$e->getMessage();$legacyMeterInput=[];}
finally{if($legacyMeterWorkbookPath!==''&&is_file($legacyMeterWorkbookPath))unlink($legacyMeterWorkbookPath);}
[$legacyMeterMerged,$legacyMeterMergeErrors]=$merge->invoke(null,$legacyMeterInput,$legacyMeterExisting);
[$legacyMeterData,$legacyMeterErrors,$legacyMeterWarnings]=$validateRow->invoke(null,$validationConn,$legacyMeterMerged,$activeMeterCategories,'piece',$legacyMeterExisting);
$assert(
    $legacyMeterMergeErrors===[]
        && $legacyMeterData['meter_options']===''
        && !str_contains(implode(' ',$legacyMeterErrors),'Meter Length Options are required')
        && str_contains(implode(' ',$legacyMeterWarnings),'existing meter product'),
    'An unchanged legacy meter product with blank options must round-trip without inventing hardcoded length choices.'
);
[, $newMeterErrors]=$validateRow->invoke(null,$validationConn,$legacyMeterInput,$activeMeterCategories,'piece',null);
$assert(str_contains(implode(' ',$newMeterErrors),'Meter Length Options are required'),'New meter products must still provide explicit length choices.');
$convertedExisting=array_merge($existing,['category'=>'bedsheets','unit_type'=>'piece','meter_options'=>'']);
[, $convertedMeterErrors]=$validateRow->invoke(null,$validationConn,$legacyMeterInput,$activeMeterCategories,'piece',$convertedExisting);
$assert(str_contains(implode(' ',$convertedMeterErrors),'Meter Length Options are required'),'Changing an existing non-meter product to meter must still require explicit length choices.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "product_import_contract_test: OK\n";
