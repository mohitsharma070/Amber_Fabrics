<?php
$root=dirname(__DIR__);$fail=[];$assert=static function(bool $ok,string $message)use(&$fail){if(!$ok)$fail[]=$message;};
require_once $root.'/includes/services/ProductImportService.php';
$page=(string)file_get_contents($root.'/admin/product-import.php');
$list=(string)file_get_contents($root.'/admin/fabrics.php');
$service=(string)file_get_contents($root.'/includes/services/ProductImportService.php');

$assert(count(ProductImportService::HEADERS)===54,'Catalogue template must contain all 54 supported CSV columns.');
$assert(in_array('Product Code',ProductImportService::HEADERS,true)&&in_array('attr_Fabric',ProductImportService::HEADERS,true),'Catalogue template endpoints are missing.');
$assert(in_array('Selling Unit',ProductImportService::HEADERS,true),'Catalogue template must expose a per-row Selling Unit column.');
$assert(str_contains($page,'require_admin()')&&str_contains($page,'verify_csrf()'),'Importer must require admin authentication and CSRF validation.');
$assert(str_contains($page,'Validate Only')&&str_contains($page,'Validate &amp; Import'),'Importer must separate validation from writes.');
$assert(str_contains($list,'product-import.php'),'Products page must link to catalogue import.');
$assert(str_contains($service,'is_uploaded_file')&&str_contains($service,'MAX_ROWS')&&str_contains($service,'finfo'),'Upload size, row count and MIME protections are missing.');
$assert(str_contains($service,"['skip','update']")&&str_contains($service,'begin_transaction()')&&str_contains($service,'rollback()'),'Duplicate modes or per-row transaction handling is missing.');
$assert(str_contains($service,'fabric_upload_path')&&!str_contains($service,'file_get_contents($row'),'Media import must only reference safe existing files and never fetch arbitrary URLs.');
$assert(str_contains($service,'ProductAdminService::publish')&&str_contains($service,'Imported as draft'),'Visible products must pass normal readiness checks or remain drafts.');
$assert(str_contains($page,'Fallback selling unit')&&str_contains($page,'used only when that cell is blank'),'Importer UI must explain that the selected unit is only a fallback.');

$method=new ReflectionMethod(ProductImportService::class,'headerKey');$method->setAccessible(true);
$assert($method->invoke(null,"\xEF\xBB\xBFProduct Code")==='productcode','UTF-8 BOM header normalization failed.');
$assert($method->invoke(null,'attr_Thickness?&?Ply')==='attrthicknessply','Shared malformed thickness header must remain compatible.');
$unitMethod=new ReflectionMethod(ProductImportService::class,'sellingUnit');$unitMethod->setAccessible(true);
$assert($unitMethod->invoke(null,'Pieces')==='piece','Piece selling-unit alias normalization failed.');
$assert($unitMethod->invoke(null,'metres')==='meter','Meter selling-unit alias normalization failed.');
$assert($unitMethod->invoke(null,'SETS')==='set','Set selling-unit alias normalization failed.');
$assert($unitMethod->invoke(null,'pair')===null,'Unsupported selling units must not silently fall back.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "product_import_contract_test: OK\n";
