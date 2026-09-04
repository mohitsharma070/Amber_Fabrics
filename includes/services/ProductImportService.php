<?php

final class ProductCatalogueException extends RuntimeException
{
}

final class ProductImportService
{
    public const MAX_BYTES = 10485760;
    public const MAX_ROWS = 5000;
    public const CLEAR_MARKER = '__CLEAR__';
    private const MAX_XLSX_XML_BYTES = 67108864;
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const HEADERS = [
        'Product ID','Product Revision','Product Code','Amazon ASIN','Name','Sku Id','Selling Price','MRP','Cost Price','Quantity','Selling Unit','Meter Length Options',
        'Packaging Length (in cm)','Packaging Breadth (in cm)','Packaging Height (in cm)','Packaging Weight (in kg)','GST %',
        'Image 1','Image 2','Image 3','Image 4','Image 5','Image 6','Image 7','Image 8','Image 9','Image 10','Video 1','Video 2',
        'Product Type','Size Type','Size','Colour','Description','Return Condition','Visibility','Size Chart','Pickup Address Code',
        'HSN Code','Customisation Id','Associated Pixel','attr_Attribute Name','attr_Brand Name','attr_Type','attr_material',
        'attr_Product Type','attr_Style Name','attr_design','attr_Printing Type','attr_Shape','attr_Pattern','attr_Origin',
        'attr_Thickness & Ply','attr_Disposable Folded','attr_Stain-Resistant','attr_Eco-Friendly','attr_Fabric',
    ];

    private const MAP = [
        'productid'=>'product_id','productrevision'=>'product_revision','productcode'=>'product_code','amazonasin'=>'amazon_asin','name'=>'name','skuid'=>'sku','sellingprice'=>'sale_price','mrp'=>'price',
        'costprice'=>'cost_price','quantity'=>'quantity','sellingunit'=>'unit_type','meterlengthoptions'=>'meter_options','packaginglengthincm'=>'parcel_length_cm','packagingbreadthincm'=>'parcel_width_cm',
        'packagingheightincm'=>'parcel_height_cm','packagingweightinkg'=>'shipping_weight_kg','gst'=>'gst_rate','producttype'=>'category',
        'sizetype'=>'size_type','size'=>'size','colour'=>'color','color'=>'color','description'=>'description',
        'returncondition'=>'return_exchange_condition','returnexchangecondition'=>'return_exchange_condition','visibility'=>'visibility','sizechart'=>'size_chart',
        'pickupaddresscode'=>'pickup_address_code','hsncode'=>'hsn_code','customisationid'=>'customisation_id',
        'associatedpixel'=>'associated_pixel','attrattributename'=>'attr_attribute_name','attrbrandname'=>'attr_brand_name',
        'attrtype'=>'attr_type','attrmaterial'=>'attr_material','attrproducttype'=>'attr_product_type','attrstylename'=>'attr_style_name',
        'attrdesign'=>'attr_design','attrprintingtype'=>'attr_printing_type','attrshape'=>'attr_shape','attrpattern'=>'attr_pattern',
        'attrorigin'=>'attr_origin','attrthicknessply'=>'attr_thickness_ply','attrdisposablefolded'=>'attr_disposable_folded',
        'attrstainresistant'=>'attr_stain_resistant','attrecofriendly'=>'attr_eco_friendly','attrfabric'=>'attr_fabric',
    ];

    private const CLEARABLE_FIELDS = [
        'product_code','amazon_asin','sale_price','meter_options','parcel_length_cm','parcel_width_cm',
        'parcel_height_cm','shipping_weight_kg','gst_rate','size_type','size','color','description','return_exchange_condition',
        'size_chart','pickup_address_code','hsn_code','customisation_id','associated_pixel','attr_attribute_name','attr_brand_name',
        'attr_type','attr_material','attr_product_type','attr_style_name','attr_design','attr_printing_type','attr_shape',
        'attr_pattern','attr_origin','attr_thickness_ply','attr_disposable_folded','attr_stain_resistant','attr_eco_friendly','attr_fabric',
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

    public static function streamCurrentProducts(mysqli $conn): never
    {
        $countResult = $conn->query("SELECT COUNT(*) AS total FROM fabrics WHERE product_type='simple'");
        $total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
        if ($total > self::MAX_ROWS) {
            throw new ProductCatalogueException('The catalogue has more than 5,000 simple products. Archive unused products or ask a developer to add filtered exports.');
        }

        $media = [];
        $mediaResult = $conn->query(
            "SELECT fm.fabric_id,fm.media_type,fm.filename
             FROM fabric_media fm
             INNER JOIN fabrics f ON f.id=fm.fabric_id
             WHERE f.product_type='simple'
             ORDER BY fm.fabric_id,fm.media_type,fm.is_primary DESC,fm.sort_order,fm.id"
        );
        while ($item = $mediaResult->fetch_assoc()) {
            $id = (int) $item['fabric_id'];
            $type = (string) $item['media_type'];
            if (!isset($media[$id])) $media[$id] = ['image' => [], 'video' => []];
            if (isset($media[$id][$type])) $media[$id][$type][] = (string) $item['filename'];
        }
        foreach($media as $id=>$items){
            if(count($items['image'])>10||count($items['video'])>2){
                throw new ProductCatalogueException('Product ID '.$id.' has more than 10 images or 2 videos. Reduce its media before exporting.');
            }
        }

        $products = $conn->query("SELECT * FROM fabrics WHERE product_type='simple' ORDER BY id");
        $rows = (static function () use ($products, $media): Generator {
            yield self::HEADERS;
            while ($product = $products->fetch_assoc()) {
                $id = (int) $product['id'];
                $productMedia = $media[$id] ?? ['image' => [], 'video' => []];
                $values = self::databaseValues($product);
                $values['product_revision'] = self::revisionToken($product, $productMedia);
                $row = [];
                foreach (self::HEADERS as $header) {
                    $key = self::headerKey($header);
                    if (preg_match('/^image([1-9]|10)$/', $key, $match)) {
                        $value = $productMedia['image'][(int) $match[1] - 1] ?? '';
                    } elseif (preg_match('/^video([12])$/', $key, $match)) {
                        $value = $productMedia['video'][(int) $match[1] - 1] ?? '';
                    } else {
                        $field = self::MAP[$key] ?? '';
                        $value = $field !== '' ? ($values[$field] ?? '') : '';
                    }
                    $row[] = (string) $value;
                }
                yield $row;
            }
        })();

        $workbookPath = self::createXlsx($rows);
        $workbookSize = (int) (filesize($workbookPath) ?: 0);
        if ($workbookSize < 1 || $workbookSize > self::MAX_BYTES) {
            @unlink($workbookPath);
            throw new ProductCatalogueException('The generated Excel file exceeds the 10 MB upload limit. Reduce long descriptions or catalogue attributes before exporting.');
        }

        header('Content-Type: '.self::XLSX_MIME);
        header('Content-Disposition: attachment; filename="amber-products-' . gmdate('Ymd-His') . '.xlsx"');
        header('Content-Length: '.$workbookSize);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        readfile($workbookPath);
        @unlink($workbookPath);
        exit;
    }

    public static function process(mysqli $conn, array $file, array $options, int $adminId, bool $import): array
    {
        [$path,$format] = self::validateUpload($file);
        $rows = self::readRows($path,$format);
        self::assertUniqueIdentifiers($rows);
        $categories = self::categories($conn);
        $mode = in_array(($options['duplicate_mode'] ?? ''), ['skip','update'], true) ? $options['duplicate_mode'] : 'skip';
        $defaultUnit = in_array(($options['default_unit'] ?? ''), ['piece','meter','set'], true) ? $options['default_unit'] : 'piece';
        $results=[];$created=0;$updated=0;$skipped=0;$failed=0;$warnings=0;

        foreach ($rows as $rowNumber => $raw) {
            $row = self::normalizeRow($raw);
            $errors=[];$rowWarnings=[];$existing=null;$data=[];
            try { $existing = self::existing($conn,$row); }
            catch (Throwable $e) { $errors[] = $e->getMessage(); }
            $hasProductId = trim((string) ($row['product_id'] ?? '')) !== '';
            $submittedRevision = strtolower(trim((string) ($row['product_revision'] ?? '')));
            if (!$errors && $existing && !$hasProductId && $mode === 'skip') {
                $skipped++;
                $results[]=['row'=>$rowNumber,'name'=>$row['name'],'status'=>'skipped','message'=>'SKU or Product Code already exists.'];
                continue;
            }
            if (!$errors && $existing && ($existing['product_type'] ?? 'simple') !== 'simple') {
                $errors[]='Variable products cannot be overwritten by this simple-product catalogue import.';
            }
            if(!$errors&&$hasProductId&&$submittedRevision===''){
                $errors[]='Product Revision is required when Product ID is populated. Download a fresh current-products workbook.';
            }
            if(!$errors&&$submittedRevision!==''&&!preg_match('/^[a-f0-9]{64}$/',$submittedRevision)){
                $errors[]='Product Revision is invalid. Download a fresh current-products workbook and do not edit revision cells.';
            }
            if(!$errors&&$existing&&$submittedRevision!==''&&!hash_equals(self::currentRevision($conn,$existing),$submittedRevision)){
                $errors[]='This product changed after the workbook was downloaded. Download a fresh workbook and reapply this row.';
            }
            if(!$errors&&!$existing&&$submittedRevision!==''){
                $errors[]='Product Revision must be blank when creating a new product.';
            }
            if (!$errors) {
                [$row,$mergeErrors] = self::applyRoundTripSemantics($row,$existing);
                $errors = array_merge($errors,$mergeErrors);
            }
            if(!$errors){
                [$data,$validationErrors,$validationWarnings] = self::validateRow($conn,$row,$categories,$defaultUnit,$existing);
                $errors = array_merge($errors,$validationErrors);
                $rowWarnings = array_merge($rowWarnings,$validationWarnings);
            }
            if (!$errors && !ProductAdminService::skuAvailable($conn, $data['sku'], (int) ($existing['id'] ?? 0))) {
                $errors[]='Sku Id is already used by a product variant.';
            }
            if(!$errors&&$existing){
                $existingCatalog=json_decode((string)($existing['catalog_data']??''),true);
                if(is_array($existingCatalog))$data['catalog_data']=array_merge($existingCatalog,$data['catalog_data']);
                if($data['media_action']==='replace'&&self::mediaMatches($conn,(int)$existing['id'],$data['media']))$data['media_action']='keep';
            }
            if ($errors) {
                $failed++;
                $results[]=['row'=>$rowNumber,'name'=>$row['name'] ?? '','status'=>'error','message'=>implode(' ',array_unique($errors))];
                continue;
            }
            if($existing&&$data['requested_status']!=='active')$rowWarnings[]='Existing product will be saved as draft for review.';
            if (!$import) {
                $warnings += count($rowWarnings);
                $results[]=['row'=>$rowNumber,'name'=>$data['name'],'status'=>'valid','message'=>$rowWarnings ? implode(' ', $rowWarnings) : 'Ready to import.'];
                continue;
            }
            $conn->begin_transaction();
            try {
                if($existing&&$submittedRevision!=='')self::assertCurrentRevision($conn,(int)$existing['id'],$submittedRevision);
                $id = $existing ? self::updateProduct($conn,(int)$existing['id'],$data) : self::createProduct($conn,$data);
                self::replaceMedia($conn,$id,$data['media'],$data['media_action']);
                $wasPublished=false;
                if ($data['requested_status'] === 'active') {
                    $published=ProductAdminService::publish($conn,$id,$adminId);
                    if (empty($published['ready'])) {
                        $rowWarnings[]='Imported as draft: '.implode(' ',array_values((array)($published['checks']??[])));
                    }else{$wasPublished=true;}
                }
                $activityDetails=$existing
                    ?($wasPublished?'Product updated from round-trip catalogue file and published.':'Product updated from round-trip catalogue file and moved to draft.')
                    :($wasPublished?'Product imported from catalogue file and published.':'Product imported from catalogue file.');
                log_admin_activity($conn,$adminId,$existing?'product_import_updated':'product_import_created','product',$id,$activityDetails,'ok');
                $conn->commit();
                $warnings += count($rowWarnings);
                $existing ? $updated++ : $created++;
                $resultMessages=$rowWarnings;if($wasPublished)$resultMessages[]='Published successfully because Visibility is active.';
                $results[]=['row'=>$rowNumber,'name'=>$data['name'],'status'=>$existing?'updated':'created','message'=>$resultMessages?implode(' ',$resultMessages):'Imported successfully.','id'=>$id];
            } catch (Throwable $e) {
                $conn->rollback();$failed++;
                $results[]=['row'=>$rowNumber,'name'=>$data['name'],'status'=>'error','message'=>'Import failed: '.$e->getMessage()];
            }
        }
        if ($import && ($created+$updated)>0 && function_exists('product_feed_refresh_files')) product_feed_refresh_files(['conn'=>$conn]);
        return ['total'=>count($rows),'created'=>$created,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$failed,'warnings'=>$warnings,'results'=>$results,'dry_run'=>!$import];
    }

    private static function validateUpload(array $file): array
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Select an Excel (.xlsx) or CSV file to upload.');
        $size=(int)($file['size']??0);if($size<1||$size>self::MAX_BYTES)throw new InvalidArgumentException('The upload must be between 1 byte and 10 MB.');
        $name=(string)($file['name']??'');$format=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($format,['csv','xlsx'],true))throw new InvalidArgumentException('Use an .xlsx workbook or a .csv file saved as CSV UTF-8.');
        $path=(string)($file['tmp_name']??'');if(!is_uploaded_file($path) && PHP_SAPI!=='cli')throw new InvalidArgumentException('The uploaded file could not be verified.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed=$format==='xlsx'
            ? [self::XLSX_MIME,'application/zip','application/octet-stream']
            : ['text/plain','text/csv','application/csv','application/vnd.ms-excel','application/octet-stream'];
        if(!in_array($mime,$allowed,true))throw new InvalidArgumentException('The uploaded file content does not match its extension.');
        return [$path,$format];
    }

    private static function readRows(string $path,string $format='csv'): array
    {
        if($format==='xlsx')return self::readXlsxRows($path);
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

    private static function createXlsx(iterable $rows): string
    {
        if(!class_exists('PharData'))throw new ProductCatalogueException('Excel export is unavailable because the PHP Phar extension is disabled.');
        $seed=tempnam(sys_get_temp_dir(),'amber-xlsx-');
        if($seed===false)throw new ProductCatalogueException('Could not create the temporary Excel export.');
        @unlink($seed);$archivePath=$seed.'.zip';$sheetPath=$seed.'.xml';$sheet=null;$archive=null;
        try{
            $sheet=fopen($sheetPath,'wb');if($sheet===false)throw new RuntimeException('Could not create the workbook worksheet.');
            fwrite($sheet,'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
                '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>');
            $rowNumber=0;$columnCount=0;
            foreach($rows as $row){
                $rowNumber++;$row=array_values((array)$row);$columnCount=max($columnCount,count($row));
                fwrite($sheet,'<row r="'.$rowNumber.'">');
                foreach($row as $index=>$rawValue){
                    $value=(string)$rawValue;
                    if(mb_strlen($value,'UTF-8')>32767)throw new ProductCatalogueException('A value in Excel row '.$rowNumber.' exceeds Excel\'s 32,767-character cell limit. Shorten that product field before exporting.');
                    $value=preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
                    $escaped=htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                    $reference=self::columnName($index+1).$rowNumber;
                    fwrite($sheet,'<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>');
                }
                fwrite($sheet,'</row>');
            }
            if($rowNumber<1)throw new ProductCatalogueException('The Excel export contains no rows.');
            $filter='A1:'.self::columnName(max(1,$columnCount)).$rowNumber;
            fwrite($sheet,'</sheetData><autoFilter ref="'.$filter.'"/></worksheet>');fclose($sheet);$sheet=null;

            $archive=new PharData($archivePath,FilesystemIterator::SKIP_DOTS,null,Phar::ZIP);
            $archive->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $archive->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $archive->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $archive->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $archive->addFile($sheetPath,'xl/worksheets/sheet1.xml');unset($archive);
            self::normalizeXlsxZipHeaders($archivePath);
            @unlink($sheetPath);
            return $archivePath;
        }catch(ProductCatalogueException $e){
            if(is_resource($sheet))fclose($sheet);unset($archive);@unlink($sheetPath);@unlink($archivePath);throw $e;
        }catch(Throwable $e){
            if(is_resource($sheet))fclose($sheet);unset($archive);@unlink($sheetPath);@unlink($archivePath);
            throw new ProductCatalogueException('Could not generate the Excel workbook. Verify that PHP Phar ZIP support is enabled.',0,$e);
        }
    }

    private static function normalizeXlsxZipHeaders(string $archivePath): void
    {
        $bytes=file_get_contents($archivePath);
        if($bytes===false)throw new RuntimeException('Could not finalize the Excel workbook archive.');
        $eocd=strrpos($bytes,"PK\x05\x06");
        if($eocd===false||strlen($bytes)<$eocd+22)throw new RuntimeException('The Excel workbook ZIP directory is missing.');
        $uint16=static fn(int $offset):int=>(int)(unpack('vvalue',substr($bytes,$offset,2))['value']??0);
        $uint32=static fn(int $offset):int=>(int)(unpack('Vvalue',substr($bytes,$offset,4))['value']??0);
        $entryCount=$uint16($eocd+10);$cursor=$uint32($eocd+16);$version=pack('v',20);
        if($entryCount<1||$cursor<0||$cursor>=$eocd)throw new RuntimeException('The Excel workbook ZIP directory is invalid.');
        for($entry=0;$entry<$entryCount;$entry++){
            if(substr($bytes,$cursor,4)!=="PK\x01\x02")throw new RuntimeException('The Excel workbook ZIP entry is invalid.');
            $nameLength=$uint16($cursor+28);$extraLength=$uint16($cursor+30);$commentLength=$uint16($cursor+32);$localOffset=$uint32($cursor+42);
            if(substr($bytes,$localOffset,4)!=="PK\x03\x04")throw new RuntimeException('The Excel workbook ZIP local entry is invalid.');
            $bytes=substr_replace($bytes,$version,$localOffset+4,2);
            $bytes=substr_replace($bytes,$version,$cursor+4,2);
            $bytes=substr_replace($bytes,$version,$cursor+6,2);
            $cursor+=46+$nameLength+$extraLength+$commentLength;
        }
        $written=file_put_contents($archivePath,$bytes,LOCK_EX);
        if($written!==strlen($bytes))throw new RuntimeException('Could not finalize the Excel workbook archive.');
    }

    private static function readXlsxRows(string $path): array
    {
        if(!class_exists('PharData')||!class_exists('XMLReader'))throw new InvalidArgumentException('Excel import requires the PHP Phar and XMLReader extensions.');
        $seed=tempnam(sys_get_temp_dir(),'amber-xlsx-read-');if($seed===false)throw new RuntimeException('Could not prepare the Excel workbook.');
        @unlink($seed);$archivePath=$seed.'.zip';$archive=null;$reader=null;
        if(!copy($path,$archivePath)){@unlink($archivePath);throw new RuntimeException('Could not prepare the Excel workbook.');}
        try{
            $archive=new PharData($archivePath,FilesystemIterator::SKIP_DOTS,null,Phar::ZIP);
            $sheetEntry='xl/worksheets/sheet1.xml';
            if(!isset($archive[$sheetEntry]))throw new InvalidArgumentException('The Excel workbook does not contain the Products worksheet.');
            if((int)$archive[$sheetEntry]->getSize()>self::MAX_XLSX_XML_BYTES)throw new InvalidArgumentException('The Excel worksheet is too large to import safely.');
            $shared=[];$sharedEntry='xl/sharedStrings.xml';
            if(isset($archive[$sharedEntry])){
                if((int)$archive[$sharedEntry]->getSize()>self::MAX_XLSX_XML_BYTES)throw new InvalidArgumentException('The Excel shared-string table is too large to import safely.');
                $shared=self::readSharedStrings(self::pharUri($archivePath,$sharedEntry));
            }
            $reader=new XMLReader();
            if(!$reader->open(self::pharUri($archivePath,$sheetEntry),null,LIBXML_NONET|LIBXML_COMPACT|LIBXML_NOERROR|LIBXML_NOWARNING))throw new InvalidArgumentException('Could not read the Excel worksheet.');
            $indexes=null;$rows=[];$fallbackLine=0;
            while($reader->read()){
                if($reader->nodeType!==XMLReader::ELEMENT||$reader->localName!=='row')continue;
                $fallbackLine++;$line=(int)($reader->getAttribute('r')?:$fallbackLine);
                $values=self::parseXlsxRow($reader->readOuterXml(),$shared);
                if($indexes===null){
                    $indexes=self::headerIndexes($values,'Excel workbook');
                    continue;
                }
                $nonempty=false;foreach($values as $value){if(trim((string)$value)!==''){$nonempty=true;break;}}if(!$nonempty)continue;
                if(count($rows)>=self::MAX_ROWS)throw new InvalidArgumentException('The Excel workbook exceeds the 5,000 product limit.');
                $row=[];foreach($indexes as $key=>$index)$row[$key]=trim((string)($values[$index]??''));$rows[$line]=$row;
            }
            $reader->close();$reader=null;unset($archive);@unlink($archivePath);
            if($indexes===null)throw new InvalidArgumentException('The Excel header row is missing.');
            if(!$rows)throw new InvalidArgumentException('The Excel workbook contains no product rows.');
            return $rows;
        }catch(Throwable $e){
            if($reader instanceof XMLReader){try{$reader->close();}catch(Throwable $ignored){}}unset($archive);@unlink($archivePath);
            if($e instanceof InvalidArgumentException)throw $e;
            throw new InvalidArgumentException('The uploaded Excel workbook is invalid or damaged.',0,$e);
        }
    }

    private static function readSharedStrings(string $uri): array
    {
        $reader=new XMLReader();if(!$reader->open($uri,null,LIBXML_NONET|LIBXML_COMPACT|LIBXML_NOERROR|LIBXML_NOWARNING))throw new InvalidArgumentException('Could not read Excel shared strings.');$shared=[];
        while($reader->read())if($reader->nodeType===XMLReader::ELEMENT&&$reader->localName==='si')$shared[]=self::xlsxText($reader->readOuterXml());
        $reader->close();return $shared;
    }

    private static function parseXlsxRow(string $xml,array $shared): array
    {
        $row=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT|LIBXML_NOERROR|LIBXML_NOWARNING);if($row===false)throw new InvalidArgumentException('An Excel row could not be read.');$values=[];
        foreach($row->xpath('./*[local-name()="c"]')?:[] as $cell){
            $reference=(string)$cell['r'];if(!preg_match('/^([A-Z]+)[0-9]+$/i',$reference,$match))continue;$index=self::columnIndex(strtoupper($match[1]))-1;
            if(($cell->xpath('./*[local-name()="f"]')?:[])!==[])throw new InvalidArgumentException('Excel formulas are not supported. Replace the formula in cell '.$reference.' with its displayed value.');
            $type=(string)$cell['t'];
            if($type==='inlineStr')$value=self::xlsxNodeText($cell);
            else{$nodes=$cell->xpath('./*[local-name()="v"]')?:[];$raw=isset($nodes[0])?(string)$nodes[0]:'';$value=$type==='s'?($shared[(int)$raw]??''):$raw;}
            $values[$index]=$value;
        }
        return $values;
    }

    private static function xlsxText(string $xml): string
    {
        $node=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT|LIBXML_NOERROR|LIBXML_NOWARNING);if($node===false)return '';return self::xlsxNodeText($node);
    }

    private static function xlsxNodeText(SimpleXMLElement $node): string
    {
        $value='';foreach($node->xpath('.//*[local-name()="t"]')?:[] as $text)$value.=(string)$text;return $value;
    }

    private static function headerIndexes(array $header,string $source): array
    {
        $indexes=[];foreach($header as $i=>$label){$key=self::headerKey((string)$label);if($key==='')continue;if(isset($indexes[$key]))throw new InvalidArgumentException($source.' contains a duplicate column: '.$label);$indexes[$key]=$i;}
        foreach(['name','skuid','mrp','quantity','producttype'] as $required)if(!isset($indexes[$required]))throw new InvalidArgumentException($source.' is missing required column: '.$required);
        $known=array_fill_keys(array_merge(array_keys(self::MAP),array_map(fn($n)=>'image'.$n,range(1,10)),['video1','video2']),true);
        foreach(array_keys($indexes) as $key)if(!isset($known[$key]))throw new InvalidArgumentException('Unknown '.$source.' column: '.$header[$indexes[$key]]);
        return $indexes;
    }

    private static function columnName(int $number): string
    {
        $name='';while($number>0){$number--;$name=chr(65+($number%26)).$name;$number=intdiv($number,26);}return $name;
    }

    private static function columnIndex(string $name): int
    {
        $number=0;foreach(str_split($name) as $character)$number=$number*26+(ord($character)-64);return $number;
    }

    private static function pharUri(string $archivePath,string $entry): string
    {
        return 'phar://'.str_replace('\\','/',$archivePath).'/'.$entry;
    }

    private static function assertUniqueIdentifiers(array $rows): void
    {
        $seen=['product_id'=>[],'sku'=>[],'product_code'=>[]];
        foreach($rows as $rowNumber=>$raw){
            $row=self::normalizeRow($raw);
            $values=[
                'product_id'=>ltrim(trim((string)($row['product_id']??'')),'0'),
                'sku'=>ProductAdminService::normalizeSku((string)($row['sku']??'')),
                'product_code'=>strtoupper(trim((string)($row['product_code']??''))),
            ];
            foreach($values as $field=>$value){
                if($value===''||$value===self::CLEAR_MARKER)continue;
                if(isset($seen[$field][$value])){
                    $label=['product_id'=>'Product ID','sku'=>'Sku Id','product_code'=>'Product Code'][$field];
                    throw new InvalidArgumentException($label.' is duplicated in CSV rows '.$seen[$field][$value].' and '.$rowNumber.'.');
                }
                $seen[$field][$value]=$rowNumber;
            }
        }
    }

    private static function headerKey(string $value): string
    {
        $value=preg_replace('/^\xEF\xBB\xBF/','',$value)??$value;
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/','',$value)??'');
    }

    private static function importCell(string $value): string
    {
        return preg_match('/^\'[=+\-@]/u',$value) ? substr($value,1) : $value;
    }

    private static function sellingUnit(string $value): ?string
    {
        $key=self::headerKey($value);
        $aliases=[
            'piece'=>'piece','pieces'=>'piece','pc'=>'piece','pcs'=>'piece','unit'=>'piece','units'=>'piece','each'=>'piece',
            'meter'=>'meter','meters'=>'meter','metre'=>'meter','metres'=>'meter','m'=>'meter',
            'set'=>'set','sets'=>'set',
        ];
        return $aliases[$key]??null;
    }

    private static function normalizeRow(array $raw): array
    {
        $row=[];foreach(self::MAP as $csv=>$field)$row[$field]=self::importCell(trim((string)($raw[$csv]??'')));
        for($i=1;$i<=10;$i++)$row['image'.$i]=self::importCell(trim((string)($raw['image'.$i]??'')));
        for($i=1;$i<=2;$i++)$row['video'.$i]=self::importCell(trim((string)($raw['video'.$i]??'')));
        return $row;
    }

    private static function databaseValues(array $product): array
    {
        $catalog=json_decode((string)($product['catalog_data']??''),true);
        if(!is_array($catalog))$catalog=[];
        $unit=in_array((string)($product['unit_type']??''),['piece','meter','set'],true)?(string)$product['unit_type']:'piece';
        $values=[
            'product_id'=>(string)($product['id']??''),'product_code'=>(string)($product['product_code']??''),
            'amazon_asin'=>(string)($product['amazon_asin']??''),'name'=>(string)($product['name']??''),'sku'=>(string)($product['sku']??''),
            'sale_price'=>($product['sale_price']??null)!==null?(string)$product['sale_price']:'','price'=>(string)($product['price']??''),
            'cost_price'=>($product['cost_price']??null)!==null?(string)$product['cost_price']:'',
            'quantity'=>$unit==='meter'?(string)($product['stock_meters']??0):(string)($product['stock']??0),
            'unit_type'=>$unit,'meter_options'=>(string)($product['meter_options']??''),'parcel_length_cm'=>(string)($product['parcel_length_cm']??''),
            'parcel_width_cm'=>(string)($product['parcel_width_cm']??''),'parcel_height_cm'=>(string)($product['parcel_height_cm']??''),
            'shipping_weight_kg'=>(string)($product['shipping_weight_kg']??''),'gst_rate'=>(string)($product['gst_rate']??''),
            'category'=>(string)($product['category']??''),'size'=>(string)($product['size']??''),'color'=>(string)($product['color']??''),
            'description'=>(string)($product['description']??''),'visibility'=>(string)($product['status']??'draft'),'hsn_code'=>(string)($product['hsn_code']??''),
        ];
        foreach(ProductAdminService::CATALOG_ATTRIBUTE_FIELDS as $field)$values[$field]=(string)($catalog[$field]??'');
        return $values;
    }

    private static function revisionToken(array $product,array $media): string
    {
        $payload=[
            'values'=>self::databaseValues($product),
            'updated_at'=>(string)($product['updated_at']??''),
            'images'=>array_values((array)($media['image']??[])),
            'videos'=>array_values((array)($media['video']??[])),
        ];
        return hash('sha256',(string)json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function currentRevision(mysqli $conn,array $product,bool $lock=false): string
    {
        $id=(int)($product['id']??0);$suffix=$lock?' FOR UPDATE':'';
        $stmt=$conn->prepare("SELECT media_type,filename FROM fabric_media WHERE fabric_id=? ORDER BY media_type,is_primary DESC,sort_order,id".$suffix);$stmt->bind_param('i',$id);$stmt->execute();
        $media=['image'=>[],'video'=>[]];$result=$stmt->get_result();while($row=$result->fetch_assoc()){$type=(string)$row['media_type'];if(isset($media[$type]))$media[$type][]=(string)$row['filename'];}
        return self::revisionToken($product,$media);
    }

    private static function assertCurrentRevision(mysqli $conn,int $id,string $submittedRevision): void
    {
        $stmt=$conn->prepare('SELECT * FROM fabrics WHERE id=? LIMIT 1 FOR UPDATE');$stmt->bind_param('i',$id);$stmt->execute();$product=$stmt->get_result()->fetch_assoc();
        if(!$product||!hash_equals(self::currentRevision($conn,$product,true),$submittedRevision))throw new RuntimeException('This product changed after validation. Download a fresh workbook and reapply this row.');
    }

    private static function applyRoundTripSemantics(array $row,?array $existing): array
    {
        $errors=[];
        if($existing===null){
            foreach($row as $field=>$value){
                if((string)$value===self::CLEAR_MARKER)$errors[]=self::CLEAR_MARKER.' can only be used when updating an existing product.';
            }
            return [$row,array_values(array_unique($errors))];
        }
        $current=self::databaseValues($existing);
        foreach(self::MAP as $field){
            if(in_array($field,['product_id','product_revision'],true))continue;
            $value=trim((string)($row[$field]??''));
            if($value===''){
                $row[$field]=(string)($current[$field]??'');
            }elseif($value===self::CLEAR_MARKER){
                if(in_array($field,self::CLEARABLE_FIELDS,true))$row[$field]='';
                else $errors[]=self::CLEAR_MARKER.' is not allowed for required field '.$field.'.';
            }
        }
        $row['product_id']=(string)$existing['id'];
        return [$row,array_values(array_unique($errors))];
    }

    private static function categories(mysqli $conn): array
    {
        $out=[];$result=$conn->query("SELECT name,slug,default_unit_type,status FROM categories");
        while($r=$result->fetch_assoc()){
            $category=['slug'=>(string)$r['slug'],'default_unit_type'=>(string)($r['default_unit_type']??''),'status'=>(string)($r['status']??'inactive')];
            foreach([$r['name'],$r['slug']] as $value)$out[self::headerKey((string)$value)]=$category;
        }
        return $out;
    }

    private static function validateRow(mysqli $conn,array $row,array $categories,string $defaultUnit,?array $existing=null): array
    {
        $updating=$existing!==null;
        $errors=[];$warnings=[];$name=mb_substr(trim($row['name']),0,255);if($name==='')$errors[]='Name is required.';
        $productIdRaw=trim((string)($row['product_id']??''));
        $productId=$productIdRaw!==''&&ctype_digit($productIdRaw)?(int)$productIdRaw:0;
        if($productIdRaw!==''&&$productId<=0)$errors[]='Product ID must be a positive whole number or blank for a new product.';
        $sku=ProductAdminService::normalizeSku($row['sku']);if($sku===''||strlen($sku)<3)$errors[]='Sku Id must contain at least 3 valid characters.';
        $code=strtoupper(trim($row['product_code']));if($code!==''&&!preg_match('/^[A-Z0-9_-]{1,100}$/',$code))$errors[]='Product Code has invalid characters.';
        $asin=strtoupper(trim($row['amazon_asin']));if($asin!==''&&!preg_match('/^[A-Z0-9]{10}$/',$asin))$errors[]='Amazon ASIN must contain exactly 10 letters or numbers.';
        $categoryKey=self::headerKey($row['category']);$categoryData=$categories[$categoryKey]??null;$category=is_array($categoryData)?(string)($categoryData['slug']??''):'';
        $currentCategory=(string)($existing['category']??'');$preservingCurrent=$updating&&$currentCategory!==''&&self::headerKey($currentCategory)===$categoryKey;
        if($category===''&&$preservingCurrent){$category=$currentCategory;$categoryData=['slug'=>$currentCategory,'default_unit_type'=>'','status'=>'inactive'];}
        if($category===''||((string)($categoryData['status']??'inactive')!=='active'&&(!$preservingCurrent||$category!==$currentCategory)))$errors[]='Product Type must match an active category; an existing inactive category may only be left unchanged.';
        $price=self::number($row['price'],'MRP',$errors,true);$sale=self::number($row['sale_price'],'Selling Price',$errors,false,true);$cost=self::number($row['cost_price'],'Cost Price',$errors,false,true)??0.0;
        if($sale!==null&&$price!==null&&$sale>=$price)$errors[]='Selling Price must be below MRP.';
        $qty=self::number($row['quantity'],'Quantity',$errors,false)??0.0;
        $sellingUnit=trim((string)($row['unit_type']??''));
        if($sellingUnit!==''){
            $unit=self::sellingUnit($sellingUnit);
            if($unit===null){$errors[]='Selling Unit must be Piece, Meter, or Set.';$unit=$defaultUnit;}
        }else{
            // Backward compatibility for templates created before Selling Unit
            // became an explicit column. New imports should use Selling Unit.
            $sizeType=mb_strtolower((string)$row['size_type']);
            $unit=(str_contains($sizeType,'meter')||str_contains($sizeType,'metre'))?'meter':(str_contains($sizeType,'set')?'set':$defaultUnit);
        }
        $categoryDefaultUnit=is_array($categoryData)?(string)($categoryData['default_unit_type']??''):'';
        if($updating&&$preservingCurrent&&(string)($categoryData['status']??'inactive')!=='active')$categoryDefaultUnit=(string)($existing['unit_type']??$categoryDefaultUnit);
        $normalizedUnit=ProductAdminService::normalizeDraftUnitType((string)$unit,$categoryDefaultUnit);
        if($normalizedUnit!==$unit)$warnings[]='Selling Unit changed to '.ucfirst($normalizedUnit).' because the selected product type requires it.';
        $unit=$normalizedUnit;
        if($unit==='meter'&&$updating){
            $existingStep=trim((string)($existing['qty_step']??''));
            if($existingStep!==''&&(!is_numeric($existingStep)||((float)$existingStep!=0.0&&!meter_qty_step_is_representable($existingStep))))$errors[]='Existing Meter Quantity Step must use no more than two decimal places. Correct it in the product editor before importing this row.';
        }
        if(in_array($unit,['piece','set'],true)&&floor($qty)!=$qty)$errors[]='Quantity must be a whole number for piece/set products.';
        $parsedMeterOptions=CartService::parse_meter_options((string)($row['meter_options']??''),0.01);
        $normalizedMeterOptions=implode(', ',array_map(static fn($value):string=>format_meter_quantity((float)$value),$parsedMeterOptions));
        $retainingLegacyBlankMeterOptions=$unit==='meter'
            &&$updating
            &&$preservingCurrent
            &&(string)($existing['unit_type']??'')==='meter'
            &&trim((string)($existing['meter_options']??''))===''
            &&$normalizedMeterOptions==='';
        if($unit==='meter'&&$normalizedMeterOptions===''){
            if($retainingLegacyBlankMeterOptions)$warnings[]='This existing meter product has no Meter Length Options. The blank legacy value was retained; add options before publishing.';
            else $errors[]='Meter Length Options are required for meter products (for example: 10, 20, 30).';
        }
        if($unit!=='meter')$normalizedMeterOptions='';
        $gst=self::number($row['gst_rate'],'GST %',$errors,false,true);if($gst!==null&&$gst>100)$errors[]='GST % must be between 0 and 100.';
        $hsn=trim($row['hsn_code']);if($hsn!==''&&!preg_match('/^[0-9]{4,8}$/',$hsn))$errors[]='HSN Code must contain 4 to 8 digits.';
        $measure=[];foreach(['parcel_length_cm'=>'Packaging Length','parcel_width_cm'=>'Packaging Breadth','parcel_height_cm'=>'Packaging Height','shipping_weight_kg'=>'Packaging Weight'] as $field=>$label){$measure[$field]=self::number($row[$field],$label,$errors,true,true);}
        $media=[];$mediaAction='keep';$mediaClear=false;$mediaInvalid=false;
        foreach(array_merge(array_map(fn($n)=>['image'.$n,'image'],range(1,10)),array_map(fn($n)=>['video'.$n,'video'],range(1,2))) as [$field,$type]){
            if($row[$field]===self::CLEAR_MARKER){$mediaClear=true;continue;}
            if($row[$field]==='')continue;$mediaAction='replace';$filename=basename((string)(parse_url($row[$field],PHP_URL_PATH)?:$row[$field]));
            if(!preg_match('/^[A-Za-z0-9._-]{1,255}$/',$filename)||!is_file(fabric_upload_path($filename))){$mediaInvalid=true;$warnings[]=$field.' was not linked because the file is not present in images/fabrics.';continue;}
            $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));$allowed=$type==='image'?['jpg','jpeg','png','webp','gif']:['mp4','webm','mov'];if(!in_array($ext,$allowed,true)){$mediaInvalid=true;$warnings[]=$field.' has an unsupported file type.';continue;}$media[]=['type'=>$type,'filename'=>$filename];
        }
        if($mediaClear&&$mediaAction==='replace')$errors[]=self::CLEAR_MARKER.' cannot be combined with image or video filenames.';
        if($mediaClear)$mediaAction='clear';
        elseif($updating&&$mediaInvalid){$mediaAction='keep';$media=[];$warnings[]='Existing media was retained because one or more media cells were invalid.';}
        elseif($mediaAction==='replace'&&$media===[])$mediaAction='keep';
        $visibility=self::headerKey($row['visibility']);$requested=in_array($visibility,['active','visible','published','publish','yes'],true)?'active':(in_array($visibility,['inactive','hidden','no'],true)?'inactive':'draft');
        $catalog=[];foreach(ProductAdminService::CATALOG_ATTRIBUTE_FIELDS as $field)$catalog[$field]=mb_substr((string)($row[$field]??''),0,1000);
        $data=['product_id'=>$productId,'product_revision'=>strtolower(trim((string)($row['product_revision']??''))),'name'=>$name,'sku'=>$sku,'product_code'=>$code,'amazon_asin'=>$asin,'category'=>$category,'unit_type'=>$unit,'meter_options'=>$normalizedMeterOptions,'price'=>$price??0.0,'sale_price'=>$sale,'cost_price'=>$cost,'quantity'=>$qty,'size'=>mb_substr($row['size'],0,100),'color'=>mb_substr($row['color'],0,100),'description'=>trim($row['description']),'hsn_code'=>$hsn,'gst_rate'=>$gst,'requested_status'=>$requested,'media'=>$media,'media_action'=>$mediaAction,'catalog_data'=>$catalog]+$measure;
        return [$data,$errors,$warnings];
    }

    private static function number(string $raw,string $label,array &$errors,bool $positive=false,bool $optional=false): ?float
    {
        $raw=trim(str_replace([',','₹'], '', $raw));if($raw===''){if(!$optional)$errors[]=$label.' is required.';return null;}if(!is_numeric($raw)){$errors[]=$label.' must be numeric.';return null;}$value=(float)$raw;if(($positive&&$value<=0)||(!$positive&&$value<0))$errors[]=$label.' has an invalid value.';return $value;
    }

    private static function existing(mysqli $conn,array $row): ?array
    {
        $idRaw=trim((string)($row['product_id']??''));
        $sku=ProductAdminService::normalizeSku((string)($row['sku']??''));
        $code=strtoupper(trim((string)($row['product_code']??'')));
        if($idRaw!==''){
            if(!ctype_digit($idRaw)||(int)$idRaw<=0)throw new InvalidArgumentException('Product ID must be a positive whole number or blank for a new product.');
            $id=(int)$idRaw;$stmt=$conn->prepare('SELECT * FROM fabrics WHERE id=? LIMIT 1');$stmt->bind_param('i',$id);$stmt->execute();$existing=$stmt->get_result()->fetch_assoc();
            if(!$existing)throw new InvalidArgumentException('Product ID '.$id.' does not exist.');
            $conflict=$conn->prepare("SELECT id FROM fabrics WHERE id<>? AND ((?<>'' AND sku=?) OR (?<>'' AND product_code=?)) LIMIT 1");
            $conflict->bind_param('issss',$id,$sku,$sku,$code,$code);$conflict->execute();
            if($conflict->get_result()->fetch_assoc())throw new InvalidArgumentException('Sku Id or Product Code belongs to a different product than Product ID '.$id.'.');
            return $existing;
        }
        $stmt=$conn->prepare("SELECT * FROM fabrics WHERE (?<>'' AND sku=?) OR (?<>'' AND product_code=?) LIMIT 2");$stmt->bind_param('ssss',$sku,$sku,$code,$code);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);if(count($rows)>1)throw new InvalidArgumentException('SKU and Product Code match different existing products.');return $rows[0]??null;
    }

    private static function createProduct(mysqli $conn,array $d): int
    {
        $slug=ProductAdminService::uniqueSlug($conn,'',$d['name']);$status=$d['requested_status']==='inactive'?'inactive':'draft';$available=0;$stock=$d['unit_type']==='meter'?0:$d['quantity'];$meters=$d['unit_type']==='meter'?$d['quantity']:0;$catalog=json_encode($d['catalog_data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$conn->prepare("INSERT INTO fabrics(product_code,amazon_asin,name,sku,slug,category,product_type,unit_type,meter_options,size,color,description,catalog_data,hsn_code,gst_rate,shipping_weight_kg,parcel_length_cm,parcel_width_cm,parcel_height_cm,price,sale_price,cost_price,stock,stock_meters,min_order_meters,qty_step,status,is_available) VALUES(NULLIF(?,''),NULLIF(?,''),?,?,?,?,'simple',?,?,?,?,?,?,NULLIF(?,''),?,?,?,?,?,?,?,?,?,?,1,1,?,?)");
        $stmt->bind_param('sssssssssssssddddddddddsi',$d['product_code'],$d['amazon_asin'],$d['name'],$d['sku'],$slug,$d['category'],$d['unit_type'],$d['meter_options'],$d['size'],$d['color'],$d['description'],$catalog,$d['hsn_code'],$d['gst_rate'],$d['shipping_weight_kg'],$d['parcel_length_cm'],$d['parcel_width_cm'],$d['parcel_height_cm'],$d['price'],$d['sale_price'],$d['cost_price'],$stock,$meters,$status,$available);$stmt->execute();return (int)$conn->insert_id;
    }

    private static function updateProduct(mysqli $conn,int $id,array $d): int
    {
        $stock=$d['unit_type']==='meter'?0:$d['quantity'];$meters=$d['unit_type']==='meter'?$d['quantity']:0;$catalog=json_encode($d['catalog_data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$conn->prepare("UPDATE fabrics SET product_code=NULLIF(?,''),amazon_asin=NULLIF(?,''),name=?,sku=?,category=?,product_type='simple',unit_type=?,meter_options=?,size=?,color=?,description=?,catalog_data=?,hsn_code=NULLIF(?,''),gst_rate=?,shipping_weight_kg=?,parcel_length_cm=?,parcel_width_cm=?,parcel_height_cm=?,price=?,sale_price=?,cost_price=?,stock=?,stock_meters=?,status='draft',is_available=0 WHERE id=?");
        $stmt->bind_param('ssssssssssssddddddddddi',$d['product_code'],$d['amazon_asin'],$d['name'],$d['sku'],$d['category'],$d['unit_type'],$d['meter_options'],$d['size'],$d['color'],$d['description'],$catalog,$d['hsn_code'],$d['gst_rate'],$d['shipping_weight_kg'],$d['parcel_length_cm'],$d['parcel_width_cm'],$d['parcel_height_cm'],$d['price'],$d['sale_price'],$d['cost_price'],$stock,$meters,$id);$stmt->execute();return $id;
    }

    private static function mediaMatches(mysqli $conn,int $id,array $submitted): bool
    {
        $stmt=$conn->prepare("SELECT media_type,filename FROM fabric_media WHERE fabric_id=? ORDER BY media_type,is_primary DESC,sort_order,id");$stmt->bind_param('i',$id);$stmt->execute();
        $current=[];$result=$stmt->get_result();while($row=$result->fetch_assoc())$current[]=['type'=>(string)$row['media_type'],'filename'=>(string)$row['filename']];
        return $current===$submitted;
    }

    private static function replaceMedia(mysqli $conn,int $id,array $media,string $action): void
    {
        if($action==='keep')return;
        $altByMedia=[];$current=$conn->prepare('SELECT media_type,filename,alt_text FROM fabric_media WHERE fabric_id=?');$current->bind_param('i',$id);$current->execute();$currentResult=$current->get_result();
        while($row=$currentResult->fetch_assoc())$altByMedia[(string)$row['media_type']."\0".(string)$row['filename']]=(string)($row['alt_text']??'');
        $stmt=$conn->prepare('DELETE FROM fabric_media WHERE fabric_id=?');$stmt->bind_param('i',$id);$stmt->execute();if($action==='clear')return;$sort=['image'=>0,'video'=>0];
        foreach($media as $m){$type=$m['type'];$filename=$m['filename'];$alt=$altByMedia[$type."\0".$filename]??'';$primary=$type==='image'&&$sort['image']===0?1:0;$order=$sort[$type]++;$q=$conn->prepare('INSERT INTO fabric_media(fabric_id,media_type,filename,alt_text,is_primary,sort_order) VALUES(?,?,?,?,?,?)');$q->bind_param('isssii',$id,$type,$filename,$alt,$primary,$order);$q->execute();}
    }
}
