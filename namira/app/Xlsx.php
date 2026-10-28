<?php
declare(strict_types=1);
namespace Namira;
final class Xlsx {
    private static function xml(string $xml): \SimpleXMLElement {
        if(str_contains(strtoupper($xml),'<!DOCTYPE')||str_contains(strtoupper($xml),'<!ENTITY')) throw new HttpError(422,'ساختار XML مجاز نیست.');
        $old=libxml_use_internal_errors(true); $data=simplexml_load_string($xml,\SimpleXMLElement::class,LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($old);
        if(!$data) throw new HttpError(422,'ساختار Excel معتبر نیست.'); return $data;
    }
    public static function read(string $path): array {
        if(filesize($path)>5*1048576) throw new HttpError(422,'فایل Excel حداکثر ۵ مگابایت است.');
        $z=new \ZipArchive(); if($z->open($path)!==true) throw new HttpError(422,'فایل XLSX معتبر نیست.');
        try {
            if($z->numFiles>500) throw new HttpError(422,'تعداد اجزای Excel زیاد است.');
            $total=0;
            for($i=0;$i<$z->numFiles;$i++){ $s=$z->statIndex($i); $total+=$s['size']; if($s['size']>20*1048576||$total>30*1048576) throw new HttpError(422,'حجم بازشده Excel بیش از حد است.'); }
            $sheet=$z->getFromName('xl/worksheets/sheet1.xml'); if($sheet===false) throw new HttpError(422,'اطلاعات باید در اولین شیت استاندارد باشند.');
            $shared=[]; $ss=$z->getFromName('xl/sharedStrings.xml');
            if($ss!==false){ $xml=self::xml($ss); foreach($xml->si as $si){ $texts=$si->xpath('.//*[local-name()="t"]'); $shared[]=implode('',array_map('strval',$texts)); } }
            $rows=[]; $xml=self::xml($sheet);
            foreach($xml->sheetData->row as $row){
                if(count($rows)>5000) throw new HttpError(422,'حداکثر ۵۰۰۰ ردیف در هر ورود مجاز است.');
                $cells=[];
                foreach($row->c as $c){ $ref=(string)$c['r']; if(!preg_match('/^([A-Z]+)[0-9]+$/D',$ref,$m)) throw new HttpError(422,'مختصات سلول نامعتبر است.');
                    $type=(string)$c['t']; if(isset($c->f)) throw new HttpError(422,'فرمول در فایل ورود مجاز نیست.');
                    $v=$type==='s'?($shared[(int)$c->v]??''):($type==='inlineStr'?implode('',array_map('strval',$c->xpath('.//*[local-name()="t"]'))):(string)$c->v);
                    if(mb_strlen($v)>2000) throw new HttpError(422,'متن سلول بیش از حد طولانی است.');
                    // Codes must be explicit text: a formatted numeric cell may have lost its leading zeros.
                    if($m[1]==='A'&&count($rows)>0&&$v!==''&&!in_array($type,['s','inlineStr','str'],true)) throw new HttpError(422,'ستون کد باید Text باشد؛ کد عددی پذیرفته نمی‌شود تا صفر ابتدایی حذف نشود.');
                    $cells[$m[1]]=trim($v);
                }
                if(implode('',$cells)!=='') $rows[]=$cells;
            }
            if(!$rows) throw new HttpError(422,'Excel خالی است.');
            $header=array_shift($rows);
            if(!in_array($header['A']??'',['code','کد','کد اختصاصی'],true)||!in_array($header['B']??'',['first_name','نام'],true)||!in_array($header['C']??'',['last_name','نام خانوادگی'],true)) throw new HttpError(422,'سه ستون اول باید code، first_name و last_name باشند.');
            return array_map(fn($r)=>['code'=>$r['A']??'','first_name'=>$r['B']??'','last_name'=>$r['C']??''],$rows);
        } finally { $z->close(); }
    }
    public static function write(array $sheets,string $path,array $numericColumns=[]): void {
        $z=new \ZipArchive(); if($z->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('Cannot write XLSX');
        $types='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $workbook='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'; $i=0;
        foreach($sheets as $name=>$rows){
            $i++; $types.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbook.='<sheet name="'.self::escape($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $rels.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><sheetData>';
            $n=0; if($rows) array_unshift($rows,array_keys($rows[0]));
            foreach($rows as $row){ $n++; $xml.='<row r="'.$n.'">'; $col=0;
                foreach($row as $columnKey=>$v){ $col++; $a=''; $c=$col; while($c>0){ $c--; $a=chr(65+$c%26).$a; $c=intdiv($c,26); }
                    // All values are inline strings: zero-prefixed codes and formula-looking text remain literal.
                    $xml.=is_string($columnKey)&&in_array($columnKey,$numericColumns,true)&&is_numeric($v)?'<c r="'.$a.$n.'"><v>'.self::escape((string)$v).'</v></c>':'<c r="'.$a.$n.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape((string)($v??'')).'</t></is></c>';
                } $xml.='</row>';
            }
            $z->addFromString('xl/worksheets/sheet'.$i.'.xml',$xml.'</sheetData></worksheet>');
        }
        $z->addFromString('[Content_Types].xml',$types.'</Types>');
        $z->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml',$workbook.'</sheets></workbook>'); $z->addFromString('xl/_rels/workbook.xml.rels',$rels.'</Relationships>'); $z->close();
    }
    private static function escape(string $v): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$v),ENT_XML1|ENT_QUOTES,'UTF-8'); }
}
