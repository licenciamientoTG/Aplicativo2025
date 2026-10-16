<?php
declare(strict_types=1);

/**
 * Resumable Monday -> Tableros importer. Default mode is read-only. The apply
 * path must be reviewed and invoked explicitly on the application host.
 */
const MONDAY_IMPORT_ACTOR = 6371;
const MONDAY_IMPORT_API_VERSION = '2026-07';
const MONDAY_IMPORT_PILOT_ID = '9149354961';

function appRoot(): string {
    static $resolved=null;
    if($resolved!==null)return $resolved;
    $configured=getenv('MONDAY_IMPORT_APP_ROOT');
    $candidate=is_string($configured)&&trim($configured)!==''?trim($configured):dirname(__DIR__);
    $root=realpath($candidate);
    if($root===false||!is_dir($root)||!is_file($root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')
        ||!is_file($root.DIRECTORY_SEPARATOR.'_assets'.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'TablerosConnection.class.php')
        ||!is_file($root.DIRECTORY_SEPARATOR.'_assets'.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'MySqlPdoHandler.class.php')
        ||!is_file($root.DIRECTORY_SEPARATOR.'_assets'.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'TablerosAccess.class.php')
        ||!is_file($root.DIRECTORY_SEPARATOR.'_assets'.DIRECTORY_SEPARATOR.'models'.DIRECTORY_SEPARATOR.'TablerosModel.php')) {
        throw new RuntimeException('MONDAY_IMPORT_APP_ROOT inválido: se requieren vendor y clases de conexión.');
    }
    return $resolved=$root;
}

function usage(): void {
    echo "Monday -> Tableros (lectura por defecto)\n\n";
    echo "php tools/monday_import_cli.php --map=outputs/monday_board_api_key_map_2026-10-14.json --workbook=C:/secure/Segmentacion_Usuarios_Monday_TOTALGAS.xlsx [options]\n\n";
    echo "  --dry-run                 Leer Monday y reportar conteos/IDs (default)\n";
    echo "  --apply                   Importar; requiere --run-key y --mapping-file\n";
    echo "  --run-key=KEY             Clave estable para reanudar el run\n";
    echo "  --mapping-file=PATH       JSON de asociaciones explícitas Monday -> local\n";
    echo "  --board-ids=ID,ID         Limitar por IDs fuente exactos (nunca por nombre)\n";
    echo "  --limit=N                 Limitar boards para una pasada controlada\n";
    echo "  --summary                 Suprimir una línea por board en dry-run\n";
    echo "  --ca-bundle=PATH          CA PEM para cURL; nunca desactiva validación TLS\n";
    echo "  MONDAY_IMPORT_APP_ROOT    Raíz de app opcional; requiere vendor y clases DB\n";
    echo "  --help                    Mostrar esta ayuda\n\n";
    echo "Se leen claves API de la columna F de la hoja Segmentación. No se imprimen ni persisten.\n";
}

function parseArgs(array $argv): array {
    $out = ['apply'=>false,'board_ids'=>[],'limit'=>null,'summary'=>false];
    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, ['--help','-h'], true)) { $out['help']=true; continue; }
        if ($arg==='--apply') { $out['apply']=true; continue; }
        if ($arg==='--dry-run') { $out['apply']=false; continue; }
        if ($arg==='--summary') { $out['summary']=true; continue; }
        if (!str_starts_with($arg,'--') || !str_contains($arg,'=')) throw new InvalidArgumentException('Argumento no válido.');
        [$key,$value]=explode('=',substr($arg,2),2);
        if ($value==='') throw new InvalidArgumentException('Opción vacía.');
        if ($key==='board-ids') {
            foreach(explode(',',$value) as $id) { if(!preg_match('/^[0-9]+$/',$id)) throw new InvalidArgumentException('ID de board no válido.'); $out['board_ids'][]=$id; }
        } elseif ($key==='limit') {
            if(!ctype_digit($value)||(int)$value<1) throw new InvalidArgumentException('Límite no válido.'); $out['limit']=(int)$value;
        } elseif(in_array($key,['map','workbook','run-key','mapping-file','ca-bundle'],true)) $out[str_replace('-','_',$key)]=$value;
        else throw new InvalidArgumentException('Opción desconocida.');
    }
    return $out;
}

function jsonFile(string $path): array {
    $raw=@file_get_contents($path);
    if($raw===false) throw new RuntimeException('No se pudo leer un archivo requerido.');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($data)) throw new RuntimeException('El JSON requerido no tiene la estructura esperada.');
    return $data;
}

function workbookKeys(string $path,array $allowedRows=[]): array {
    require_once appRoot().'/vendor/autoload.php';
    $reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $book=$reader->load($path); $sheet=$book->getSheetByName('Segmentación');
    if(!$sheet) throw new RuntimeException('No se encontró la hoja Segmentación.');
    $keys=[];
    for($row=1;$row<=$sheet->getHighestDataRow();$row++) {
        if($allowedRows&&!isset($allowedRows[$row]))continue;
        $token=trim((string)$sheet->getCell('F'.$row)->getFormattedValue());
        if($token!=='') $keys[$row]=$token;
    }
    $book->disconnectWorksheets(); unset($book,$sheet);
    return $keys;
}

function requestMonday(string $token,string $query,array $variables=[],?string $caBundle=null,int $maxAttempts=5,string $stage='request',?string $contextId=null): array {
    // GraphQL requires variables to be a JSON object; PHP's empty [] otherwise
    // serializes as a list and Monday rejects the request before parsing query.
    $body=json_encode(['query'=>$query,'variables'=>(object)$variables],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $lastPhase='connect'; $lastStatus=0; $lastCurl=0; $lastGraph='';
    for($n=0;$n<$maxAttempts;$n++) {
        $ch=curl_init('https://api.monday.com/v2');
        $opts=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: '.$token,'API-Version: '.MONDAY_IMPORT_API_VERSION],
            CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>180,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
        if($caBundle!==null) $opts[CURLOPT_CAINFO]=$caBundle;
        curl_setopt_array($ch,$opts);
        $response=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $curlNo=curl_errno($ch); curl_close($ch);
        $lastStatus=$status; $lastCurl=$curlNo;
        if($curlNo!==0) {
            $lastPhase=$curlNo===60?'tls':'transport';
            if(!in_array($curlNo,[6,7,18,28,35,52,55,56,60,65],true)) throw new RuntimeException('Monday transport failed stage='.$stage.' context_id='.(preg_match('/^[0-9]+$/',(string)$contextId)?$contextId:'none').' curl_errno='.$curlNo.' http_status='.$status.'.');
        } elseif($status>=200&&$status<300&&is_string($response)) {
            $decoded=json_decode($response,true);
            if(!is_array($decoded)) throw new RuntimeException('Monday returned invalid JSON (http_status='.$status.').');
            if(empty($decoded['errors'])) return $decoded['data']??[];
            $codes=[];
            foreach($decoded['errors'] as $err) { $code=strtoupper((string)($err['extensions']['code']??$err['extensions']['error_code']??'GRAPHQL_ERROR')); if(!preg_match('/^[A-Z0-9_]{1,48}$/',$code))$code='GRAPHQL_ERROR'; $codes[]=$code; }
            $lastGraph=implode(',',array_unique($codes)); $lastPhase='graphql';
            $retry=in_array('RATE_LIMIT_EXCEEDED',$codes,true)||in_array('COMPLEXITY_BUDGET_EXCEEDED',$codes,true)||in_array('INTERNAL_SERVER_ERROR',$codes,true);
            if(!$retry) throw new RuntimeException('Monday GraphQL rejected query stage='.$stage.' context_id='.(preg_match('/^[0-9]+$/',(string)$contextId)?$contextId:'none').' error_class='.$lastGraph.' http_status='.$status.'.');
        } elseif(!in_array($status,[0,408,429,500,502,503,504],true)) {
            if(is_string($response)) {
                $errorBody=json_decode($response,true);$codes=[];
                foreach(($errorBody['errors']??[]) as $err) { $code=strtoupper((string)($err['extensions']['code']??$err['extensions']['error_code']??''));if(preg_match('/^[A-Z0-9_]{1,48}$/',$code))$codes[]=$code; }
                $topCode=strtoupper((string)($errorBody['error_code']??$errorBody['code']??''));if(preg_match('/^[A-Z0-9_]{1,48}$/',$topCode))$codes[]=$topCode;
                $lastGraph=implode(',',array_unique($codes));
            }
            $lastPhase=$lastGraph!==''?'graphql_http':'http';
            throw new RuntimeException('Monday HTTP rejected query stage='.$stage.' context_id='.(preg_match('/^[0-9]+$/',(string)$contextId)?$contextId:'none').' phase='.$lastPhase.' http_status='.$status.($lastGraph!==''?' error_class='.$lastGraph:'').'.');
        } else $lastPhase='http';
        if($n+1<$maxAttempts) usleep((int)(min(60,2**$n)*1000000+random_int(0,400000)));
    }
    throw new RuntimeException('Monday request exhausted stage='.$stage.' context_id='.(preg_match('/^[0-9]+$/',(string)$contextId)?$contextId:'none').' phase='.$lastPhase.' http_status='.$lastStatus.' curl_errno='.$lastCurl.($lastGraph!==''?' error_class='.$lastGraph:'').'.');
}

function fetchBoard(string $token,string $id,?string $caBundle): array {
    $query=<<<'GQL'
query($ids:[ID!]) {
  boards(ids:$ids,limit:1) {
    id name description state updated_at workspace{id name}
    folder{id name color parent{id name color parent{id name color parent{id name color parent{id name color parent{id name color}}}}}}
    groups{id title color position}
    columns{id title type settings_str}
    items_page(limit:500) {
      cursor
      items {
        id name state group{id} parent_item{id} column_values{id type text value}
        assets{id name public_url url file_extension file_size created_at}
        subitems{id name state group{id} column_values{id type text value} assets{id name public_url url file_extension file_size created_at}}
      }
    }
  }
}
GQL;
    try {$base=requestMonday($token,$query,['ids'=>[$id]],$caBundle,5,'board_primary',$id);}
    catch(Throwable $queryError) {
        if(!str_contains($queryError->getMessage(),'PARSING_ERROR'))throw $queryError;
        // Monday can reject a large mixed selection with a parser error even when
        // each fragment is valid. Retry the same board by independently parsed selections.
        $base=['boards'=>[]];$fields=[
            'id name description state updated_at workspace{id name}',
            'folder{id name color parent{id name color parent{id name color parent{id name color parent{id name color parent{id name color}}}}}}',
            'groups{id title color position}',
            'columns{id title type settings_str}',
            'items_page(limit:500){cursor items{id name state group{id} parent_item{id} column_values{id type text value} assets{id name public_url url file_extension file_size created_at} subitems{id name state group{id} column_values{id type text value} assets{id name public_url url file_extension file_size created_at}}}}',
        ];
        foreach($fields as $selection) {
            $part=requestMonday($token,'query($ids:[ID!]){boards(ids:$ids,limit:1){'.$selection.'}}',['ids'=>[$id]],$caBundle,5,'board_fragment',$id);
            $boardPart=$part['boards'][0]??null;if(!is_array($boardPart))throw new RuntimeException('Monday board not visible for selected key row.');
            if(!$base['boards'])$base['boards'][0]=[];
            $base['boards'][0]=array_merge($base['boards'][0],$boardPart);
        }
    }
    $board=$base['boards'][0]??null;
    if(!is_array($board)) throw new RuntimeException('Monday board not visible for selected key row.');
    $items=$board['items_page']['items']??[]; $cursor=$board['items_page']['cursor']??null;
    while($cursor) {
        try {
            $next=requestMonday($token,
              'query($id:ID!,$cursor:String!){next_items_page(board_id:$id,cursor:$cursor,limit:500){cursor items{id name state group{id} parent_item{id} column_values{id type text value} assets{id name public_url url file_extension file_size created_at} subitems{id name state group{id} column_values{id type text value} assets{id name public_url url file_extension file_size created_at}}}}}',
              ['id'=>$id,'cursor'=>$cursor],$caBundle,5,'board_items_next_page',$id);
        } catch(Throwable $e) {
            if(str_contains($e->getMessage(),'CURSOR_EXPIRED')) throw new RuntimeException('Monday cursor expired; rerun board from first page for safe replay.');
            throw $e;
        }
        $page=$next['next_items_page']??[]; $items=array_merge($items,$page['items']??[]); $cursor=$page['cursor']??null;
    }
    $board['items_page']['items']=$items;
    // Resolve the folder ancestry by stable Monday IDs. A bounded nested selection
    // covers the board response; fetch missing ancestors by ID so depth is not lost.
    if(is_array($board['folder']??null)) {
        $folder=$board['folder'];$depth=0;
        while(!empty($folder['parent']['id'])&&$depth<50) {
            $parent=$folder['parent'];
            if(isset($parent['parent'])&&is_array($parent['parent'])) {$folder=$parent;$depth++;continue;}
            $data=requestMonday($token,'query($ids:[ID!]){folders(ids:$ids){id name color parent{id}}}',['ids'=>[(string)$parent['id']]],$caBundle,5,'folder_ancestor',$id);
            $f=$data['folders'][0]??null;if(!is_array($f))throw new RuntimeException('source_folder_parent_unavailable');
            $folder['parent']=$f;$folder=$f;$depth++;
        }
        if($depth>=50&&!empty($folder['parent']['id']))throw new RuntimeException('source_folder_depth_limit');
        $board['_folder_leaf']=$board['folder'];
    }
    return $board;
}

function flattenItems(array $items,?string $parentId=null,?string $parentGroupId=null): array {
    $out=[];
    foreach($items as $item) {
        if(!is_array($item)||!isset($item['id'])||($item['state']??'active')==='deleted') continue;
        if($parentId!==null && empty($item['parent_item']['id'])) $item['parent_item']=['id'=>$parentId];
        if($parentId!==null && empty($item['group']['id']) && $parentGroupId!==null) $item['group']=['id'=>$parentGroupId];
        $groupForChildren=(string)($item['group']['id']??$parentGroupId??'');
        $out[]=$item;
        foreach(($item['subitems']??[]) as $sub) $out=array_merge($out,flattenItems([$sub],(string)$item['id'],$groupForChildren));
    }
    return $out;
}

function stableSourcePayload(array $value): string {
    $sanitize=function($v) use (&$sanitize) {
        if(!is_array($v)) return $v;
        $out=[];
        foreach($v as $k=>$child) {
            if(in_array((string)$k,['url','public_url','signed_url','download_url'],true)) continue;
            $out[$k]=$sanitize($child);
        }
        return $out;
    };
    return json_encode($sanitize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function boardCounts(array $board): array {
    $items=flattenItems($board['items_page']['items']??[]); $files=0;
    foreach($items as $item) $files+=count($item['assets']??[]);
    return ['groups'=>count($board['groups']??[]),'columns'=>count($board['columns']??[]),'items'=>count($items),'subitems'=>count(array_filter($items,fn($i)=>!empty($i['parent_item']['id']))),'attachments'=>$files];
}

function db(): PDO {
    require_once appRoot().'/_assets/classes/common/MySqlPdoHandler.class.php';
    require_once appRoot().'/_assets/classes/TablerosConnection.class.php';
    require_once appRoot().'/_assets/classes/TablerosAccess.class.php';
    require_once appRoot().'/_assets/models/TablerosModel.php';
    $h=MySqlPdoHandler::getInstance(); $h->connect('TG'); return TablerosConnection::get();
}
function one(PDO $pdo,string $sql,array $params=[]): ?array { $s=$pdo->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null; }
function all(PDO $pdo,string $sql,array $params=[]): array { $s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC); }
function insertId(PDO $pdo,string $sql,array $params=[]): int { $s=$pdo->prepare($sql);$s->execute($params);return (int)$s->fetchColumn(); }
function execute(PDO $pdo,string $sql,array $params=[]): void { $s=$pdo->prepare($sql);$s->execute($params); }

function entity(PDO $pdo,string $account,string $type,string $mid,?int $local,?string $boardStatus,string $hash,int $runId): array {
    $existing=one($pdo,'SELECT id,local_id FROM dbo.tb_monday_import_entity WITH (UPDLOCK,HOLDLOCK) WHERE source_account_id=? AND entity_type=? AND monday_id=?',[$account,$type,$mid]);
    if($existing) {
        execute($pdo,'UPDATE dbo.tb_monday_import_entity SET local_id=COALESCE(?,local_id),source_status=?,payload_sha256=?,last_seen_run_id=?,updated_at=SYSUTCDATETIME() WHERE id=?',[$local,$boardStatus,$hash,$runId,$existing['id']]);
        return ['id'=>(int)$existing['id'],'local_id'=>$local??($existing['local_id']===null?null:(int)$existing['local_id'])];
    }
    $id=insertId($pdo,'INSERT INTO dbo.tb_monday_import_entity(source_account_id,entity_type,monday_id,local_id,source_status,payload_sha256,last_seen_run_id) OUTPUT INSERTED.id VALUES(?,?,?,?,?,?,?)',[$account,$type,$mid,$local,$boardStatus,$hash,$runId]);
    return ['id'=>$id,'local_id'=>$local];
}
function checkpoint(PDO $pdo,int $runId,int $entityId,string $account,string $status,string $hash,?string $errorCode=null): void {
    $sql="IF EXISTS(SELECT 1 FROM dbo.tb_monday_import_checkpoint WHERE run_id=? AND entity_id=?)\n".
      "UPDATE dbo.tb_monday_import_checkpoint SET status=?,payload_sha256=?,attempt_count=attempt_count+1,error_code=?,error_summary=NULL,started_at=CASE WHEN ?='in_progress' THEN SYSUTCDATETIME() ELSE started_at END,completed_at=CASE WHEN ?='complete' THEN SYSUTCDATETIME() ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE run_id=? AND entity_id=?\n".
      "ELSE INSERT dbo.tb_monday_import_checkpoint(run_id,entity_id,source_account_id,status,payload_sha256,attempt_count,error_code,started_at,completed_at) VALUES(?,?,?, ?, ?,1,?,CASE WHEN ?='in_progress' THEN SYSUTCDATETIME() ELSE NULL END,CASE WHEN ?='complete' THEN SYSUTCDATETIME() ELSE NULL END)";
    execute($pdo,$sql,[$runId,$entityId,$status,$hash,$errorCode,$status,$status,$runId,$entityId,$runId,$entityId,$account,$status,$hash,$errorCode,$status,$status]);
}
function checkpointIsComplete(PDO $pdo,int $runId,int $entityId,string $hash): bool {
    $r=one($pdo,'SELECT status,payload_sha256 FROM dbo.tb_monday_import_checkpoint WHERE run_id=? AND entity_id=?',[$runId,$entityId]);
    return $r && $r['status']==='complete' && strtolower((string)$r['payload_sha256'])===strtolower($hash);
}
function assessCoverage(array $map,array $inventory): array {
    $keys=$map['keys']??[];$excluded=$map['excluded_keys']??[];$failed=$map['failed_keys']??[];
    if(!is_array($keys)||!is_array($excluded)||!is_array($failed))throw new RuntimeException('coverage_arrays_invalid');
    $row31KnownNonexistent=false;foreach($failed as $entry)if((int)($entry['source_row']??0)===31&&($entry['status']??'')==='http_error'&&(int)($entry['http_status']??0)===401)$row31KnownNonexistent=true;
    $rows=[];$okRows=[];$excludedRows=[];$failedRows=[];$boardSets=[];
    foreach($keys as $key){$row=(int)($key['source_row']??0);$status=(string)($key['status']??'');if($row<1||isset($rows[$row]))throw new RuntimeException('coverage_key_rows_invalid');$rows[$row]=$status;
        if($status==='ok'){$okRows[$row]=true;$ids=$key['boards']??[];if(!is_array($ids)||(int)($key['board_count']??-1)!==count($ids))throw new RuntimeException('coverage_key_board_count_mismatch');$set=[];foreach($ids as $b){$id=is_array($b)?(string)($b['id']??''):(string)$b;if(!preg_match('/^[0-9]+$/',$id)||isset($set[$id]))throw new RuntimeException('coverage_board_id_invalid');$set[$id]=true;}$boardSets[$row]=$set;}
        elseif($status==='excluded_nonexistent_key'||($row===31&&$status==='http_error'&&$row31KnownNonexistent))$excludedRows[$row]=true;
        elseif(in_array($status,['failed','error','unauthorized','http_error'],true))$failedRows[$row]=true;
        else throw new RuntimeException('coverage_key_status_unclassified');
    }
    $listedExcluded=[];foreach($excluded as $entry){$r=(int)($entry['source_row']??0);$explicit=($entry['status']??'')==='excluded_nonexistent_key';$known401=$r===31&&($entry['status']??'')==='http_error'&&(int)($entry['http_status']??0)===401;if((!$explicit&&!$known401)||isset($listedExcluded[$r]))throw new RuntimeException('coverage_excluded_rows_invalid');$listedExcluded[$r]=true;}
    if(!$listedExcluded&&isset($excludedRows[31]))$listedExcluded[31]=true; // Legacy inventory map: the user identified row 31 / HTTP 401 as a nonexistent key.
    $listedFailed=[];foreach($failed as $entry){$r=(int)($entry['source_row']??0);if($r===31&&($entry['status']??'')==='http_error'&&(int)($entry['http_status']??0)===401)continue;if(isset($listedFailed[$r]))throw new RuntimeException('coverage_failed_rows_invalid');$listedFailed[$r]=true;}
    ksort($excludedRows);ksort($listedExcluded);ksort($failedRows);ksort($listedFailed);
    if(array_keys($excludedRows)!==array_keys($listedExcluded)||array_keys($failedRows)!==array_keys($listedFailed))throw new RuntimeException('coverage_row_arrays_disagree');
    if(array_keys($excludedRows)!==[31])throw new RuntimeException('coverage_expected_nonexistent_key_row31_missing');
    if((int)($map['key_count']??-1)!==count($keys)||(int)($map['successful_key_count']??-1)!==count($okRows)||(isset($map['existing_key_count'])&&(int)$map['existing_key_count']!==count($okRows)+count($failedRows))||(isset($map['excluded_nonexistent_key_count'])&&(int)$map['excluded_nonexistent_key_count']!==count($excludedRows)))throw new RuntimeException('coverage_declared_counts_disagree');
    $expected=[];$stateCounts=['active'=>0,'archived'=>0,'deleted'=>0];foreach($inventory as $b){$id=(string)($b['id']??'');$state=(string)($b['state']??'');if(!preg_match('/^[0-9]+$/',$id)||isset($expected[$id])||!array_key_exists($state,$stateCounts))throw new RuntimeException('coverage_inventory_id_or_state_invalid');$r=(int)($b['selected_key_source_row']??0);if(!isset($boardSets[$r])||!isset($boardSets[$r][$id]))throw new RuntimeException('coverage_inventory_board_not_backed_by_successful_key');$expected[$id]=$state;$stateCounts[$state]++;}
    $listed=[];foreach($boardSets as $set)foreach($set as $id=>$_){if(!isset($expected[$id]))throw new RuntimeException('coverage_key_board_missing_from_inventory');$listed[$id]=$expected[$id];}
    ksort($expected);ksort($listed);
    $complete=count($okRows)===15&&count($excludedRows)===1&&count($failedRows)===0&&count($keys)===16&&count($expected)===800&&$stateCounts===['active'=>391,'archived'=>113,'deleted'=>296]&&array_keys($expected)===array_keys($listed)&&$expected===$listed;
    return ['complete'=>$complete,'key_count'=>count($keys),'successful_key_count'=>count($okRows),'existing_key_count'=>count($okRows)+count($failedRows),'excluded_count'=>count($excludedRows),'failed_count'=>count($failedRows),'active_count'=>$stateCounts['active'],'archived_count'=>$stateCounts['archived'],'deleted_count'=>$stateCounts['deleted'],'inventory_count'=>count($expected),'importable_count'=>$stateCounts['active']+$stateCounts['archived']];
}
function ensureRun(PDO $pdo,string $account,string $runKey,array $inventory,string $cutoff,bool $coverageComplete,string $coverageHash): int {
    try{$cutoffUtc=(new DateTimeImmutable($cutoff))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');}catch(Throwable $e){throw new RuntimeException('inventory_cutoff_invalid');}
    $row=one($pdo,'SELECT id,expected_board_count,active_board_count,archived_board_count,inventory_complete,inventory_sha256,source_cutoff_at,source_api_version FROM dbo.tb_monday_import_run WHERE source_account_id=? AND run_key=?',[$account,$runKey]);
    $importable=array_values(array_filter($inventory,fn($b)=>!empty($b['importable'])&&in_array($b['state']??'', ['active','archived'],true)));
    $active=count(array_filter($importable,fn($b)=>($b['state']??'')==='active'));$archived=count($importable)-$active;
    $hash=hash('sha256',stableSourcePayload(['boards'=>array_map(fn($b)=>[(string)$b['id'],$b['name']??'',$b['state']??'',$b['selected_key_source_row']??null],$importable),'key_coverage_sha256'=>$coverageHash]));
    $complete=$coverageComplete&&count($importable)===504&&$active+$archived===504;
    if($row) {
        $sameCounts=(int)$row['expected_board_count']===504&&(int)$row['active_board_count']===$active&&(int)$row['archived_board_count']===$archived;
        $sameHash=strtolower((string)$row['inventory_sha256'])===$hash;
        $sameCoverage=(int)$row['inventory_complete']===(int)$complete;
        $sameApi=(string)$row['source_api_version']===MONDAY_IMPORT_API_VERSION;
        $sameCutoff=one($pdo,'SELECT CASE WHEN CONVERT(DATETIME2(3),?)=source_cutoff_at THEN 1 ELSE 0 END ok FROM dbo.tb_monday_import_run WHERE id=?',[$cutoffUtc,$row['id']]);
        if(!$sameCounts||!$sameHash||!$sameCoverage||!$sameApi||empty($sameCutoff['ok']))throw new RuntimeException('run_key_provenance_mismatch_use_new_run_key');
        return (int)$row['id'];
    }
    return insertId($pdo,"INSERT dbo.tb_monday_import_run(source_account_id,run_key,expected_board_count,active_board_count,archived_board_count,inventory_complete,inventory_sha256,source_cutoff_at,source_api_version,actor_user_id,target_visibility,status,error_code,error_summary) OUTPUT INSERTED.id VALUES(?,?,504,?,?,?, ?,CONVERT(DATETIME2(3),?),?,6371,'private',?,?,?)",[$account,$runKey,$active,$archived,$complete?1:0,$hash,$cutoffUtc,MONDAY_IMPORT_API_VERSION,$complete?'inventory_ready':($coverageComplete?'in_progress':'paused'),$coverageComplete?null:'coverage_incomplete',$coverageComplete?null:'La cobertura de API keys es incompleta; puede faltar contenido fuente.']);
}

function ensureLocalWorkspace(PDO $pdo,string $account,array $source,int $runId): int {
    $mid=(string)($source['id']??'monday-default'); $name=trim((string)($source['name']??'Monday')); if($name==='')$name='Monday';
    $hash=hash('sha256',stableSourcePayload($source)); $e=entity($pdo,$account,'workspace',$mid,null,null,$hash,$runId);
    if($e['local_id']) { checkpoint($pdo,$runId,$e['id'],$account,'complete',$hash); return (int)$e['local_id']; }
    $key='monday-'.substr(hash('sha256',$account.':'.$mid),0,64);
    $id=insertId($pdo,'INSERT dbo.tb_workspace(workspace_key,workspace_type,name,description,visibility,created_by,created_at,updated_at) OUTPUT INSERTED.id VALUES(?,\'workspace\',?,NULL,\'private\',?,SYSUTCDATETIME(),SYSUTCDATETIME())',[$key,mb_substr($name,0,200,'UTF-8'),MONDAY_IMPORT_ACTOR]);
    entity($pdo,$account,'workspace',$mid,$id,null,$hash,$runId); checkpoint($pdo,$runId,$e['id'],$account,'complete',$hash);
    return $id;
}
function ensureLocalFolder(PDO $pdo,string $account,array $source,int $workspaceId,int $runId): ?int {
    if(empty($source['id'])) return null;
    if(isset($source['_monday_parent_local_id'])) $parentId=(int)$source['_monday_parent_local_id'];
    else $parentId=ensureLocalFolder($pdo,$account,is_array($source['parent']??null)?$source['parent']:[],$workspaceId,$runId);
    $mid=(string)$source['id']; $name=trim((string)($source['name']??'Folder')); if($name==='')$name='Folder';
    $hash=hash('sha256',stableSourcePayload($source)); $e=entity($pdo,$account,'folder',$mid,null,null,$hash,$runId);
    if($e['local_id']) {
        $local=one($pdo,'SELECT id,workspace_id,parent_folder_id FROM dbo.tb_folder WHERE id=? AND deleted_at IS NULL',[$e['local_id']]);
        if(!$local||(int)$local['workspace_id']!==$workspaceId||($local['parent_folder_id']===null?null:(int)$local['parent_folder_id'])!==($parentId?:null))throw new RuntimeException('mapped_folder_parent_mismatch');
        checkpoint($pdo,$runId,$e['id'],$account,'complete',$hash); return (int)$e['local_id'];
    }
    $collision=one($pdo,'SELECT id FROM dbo.tb_folder WHERE workspace_id=? AND ((parent_folder_id=?) OR (parent_folder_id IS NULL AND ? IS NULL)) AND name=? AND deleted_at IS NULL',[$workspaceId,$parentId,$parentId,$name]);
    if($collision) {
        $base=mb_substr($name,0,145,'UTF-8');$name=$base.' (Monday '.substr($mid,-40).')';
        $again=one($pdo,'SELECT id FROM dbo.tb_folder WHERE workspace_id=? AND ((parent_folder_id=?) OR (parent_folder_id IS NULL AND ? IS NULL)) AND name=? AND deleted_at IS NULL',[$workspaceId,$parentId,$parentId,$name]);
        if($again)$name=mb_substr($base,0,130,'UTF-8').' (Monday '.substr(hash('sha256',$account.':folder:'.$mid),0,32).')';
        $last=one($pdo,'SELECT id FROM dbo.tb_folder WHERE workspace_id=? AND ((parent_folder_id=?) OR (parent_folder_id IS NULL AND ? IS NULL)) AND name=? AND deleted_at IS NULL',[$workspaceId,$parentId,$parentId,$name]);
        if($last)throw new RuntimeException('deterministic_folder_name_collision');
    }
    $key='monday-'.substr(hash('sha256',$account.':folder:'.$mid),0,64);
    $id=insertId($pdo,'INSERT dbo.tb_folder(workspace_id,parent_folder_id,folder_key,name,color,sort_order,created_by,created_at,updated_at) OUTPUT INSERTED.id VALUES(?,?,?,?,?,0,?,SYSUTCDATETIME(),SYSUTCDATETIME())',[$workspaceId,$parentId,$key,mb_substr($name,0,200,'UTF-8'),$source['color']??null,MONDAY_IMPORT_ACTOR]);
    entity($pdo,$account,'folder',$mid,$id,null,$hash,$runId); checkpoint($pdo,$runId,$e['id'],$account,'complete',$hash); return $id;
}

function localBoardName(PDO $pdo,int $workspaceId,?int $folderId,string $sourceName,string $sourceId,bool $sourceNameDuplicated=false,?int $excludeId=null): array {
    $name=mb_substr(trim($sourceName)===''?'Monday board':$sourceName,0,200,'UTF-8');
    $conflict=function(string $candidate)use($pdo,$workspaceId,$folderId,$excludeId):bool {
        $sql='SELECT id FROM dbo.tb_board WHERE workspace_id=? AND ((folder_id=?) OR (folder_id IS NULL AND ? IS NULL)) AND name=? AND deleted_at IS NULL';
        $params=[$workspaceId,$folderId,$folderId,$candidate];if($excludeId!==null){$sql.=' AND id<>?';$params[]=$excludeId;}
        return one($pdo,$sql,$params)!==null;
    };
    if(!$sourceNameDuplicated&&!$conflict($name))return [$name,false];
    $suffix=' (Monday '.$sourceId.')';$candidate=mb_substr($name,0,200-mb_strlen($suffix,'UTF-8'),'UTF-8').$suffix;
    if($conflict($candidate)) {$suffix=' (M-'.substr(hash('sha256',$sourceId),0,32).')';$candidate=mb_substr($name,0,200-mb_strlen($suffix,'UTF-8'),'UTF-8').$suffix;}
    if($conflict($candidate)) throw new RuntimeException('deterministic_board_name_collision');
    return [$candidate,true];
}

function localType(string $mondayType,array &$unsupported,string $columnId): string {
    $map=['name'=>'name','text'=>'text','long_text'=>'long_text','numbers'=>'numbers','formula'=>'formula','progress'=>'progress','rating'=>'rating','people'=>'people','person'=>'person','team'=>'team','status'=>'status','color'=>'status','dropdown'=>'dropdown','tags'=>'tags','date'=>'date','timeline'=>'timeline','hour'=>'hour','file'=>'file','email'=>'email','phone'=>'phone','country'=>'country','link'=>'link','location'=>'location','board_relation'=>'board_relation','subitems'=>'subtasks','dependency'=>'dependency','item_id'=>'item_id'];
    if(isset($map[$mondayType])) return $map[$mondayType];
    $unsupported[]=['column_id'=>$columnId,'reason'=>'unsupported_source_type','source_type'=>$mondayType]; return 'text';
}
function optionJson(array $column): string {
    $settings=json_decode((string)($column['settings_str']??''),true);
    if(!is_array($settings)) $settings=[];
    $options=$settings['labels']??$settings['options']??$settings;
    $payload=['monday_settings'=>$settings];
    if(is_array($options)) $payload['options']=$options;
    return json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function decimalCanonical($value): ?string {
    $value=trim((string)$value);
    if(!preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/',$value,$m))return null;
    $whole=ltrim($m[2],'0');if($whole==='')$whole='0';$fraction=rtrim($m[3]??'','0');
    if(strlen($whole)>28||strlen($fraction)>10)return null;
    if($whole==='0'&&$fraction==='')return '0';
    return (($m[1]??'')==='-'?'-':'').$whole.($fraction!==''?'.'.$fraction:'');
}
function valueForCell(array $columnValue,string $type): array {
    $text=isset($columnValue['text'])?(string)$columnValue['text']:'';
    $raw=$columnValue['value']??null; $decoded=is_string($raw)?json_decode($raw,true):$raw;
    $empty=$text===''&&($raw===null||$decoded===null||$decoded===[]||$decoded==='');
    if($empty)return ['skip'=>true,'reason'=>'empty_source_value','value'=>null];
    if(in_array($type,['people','person','team'],true)) {
        return ['skip'=>false,'field'=>'value_json','value'=>json_encode(['source_type'=>$type,'source_value'=>$decoded??$raw,'source_text'=>$text],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'unmapped_identity'=>true];
    }
    if(in_array($type,['board_relation','dependency'],true)) return ['skip'=>false,'field'=>'value_json','value'=>json_encode(['source_type'=>$type,'source_value'=>$decoded??$raw,'source_text'=>$text],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'preserved_relation_json'=>true];
    if(in_array($type,['file','subtasks'],true)) return ['skip'=>true,'reason'=>'handled_separately','value'=>null];
    if(in_array($type,['numbers','progress','rating','item_id'],true)) {
        $v=$decoded['number']??$decoded['value']??$text;
        if(!is_numeric($v)||decimalCanonical($v)===null)throw new RuntimeException('unsupported_value_conversion');
        return ['skip'=>false,'field'=>'value_number','value'=>(string)$v];
    }
    if($type==='date') {
        $date=$decoded['date']??$text; if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('unsupported_value_conversion');
        return ['skip'=>false,'field'=>'value_date','value'=>$date];
    }
    if($type==='timeline') return ['skip'=>false,'field'=>'value_json','value'=>json_encode($decoded??['text'=>$text],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    if($type==='tags') return ['skip'=>false,'field'=>'value_json','value'=>json_encode($decoded??$text,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    if(in_array($type,['status','dropdown'],true)) {
        $label=is_array($decoded)?($decoded['label']??$decoded['text']??$text):$text;
        return $label===''?['skip'=>true,'reason'=>'empty_source_value','value'=>null]:['skip'=>false,'field'=>'value_text','value'=>(string)$label];
    }
    return ['skip'=>false,'field'=>'value_text','value'=>$text];
}

function saveCell(PDO $pdo,string $account,int $runId,string $itemId,string $columnId,int $localItem,int $localColumn,int $boardId,string $type,array $columnValue,int $actor,array &$skipCounts): ?int {
    $v=valueForCell($columnValue,$type); $rawHash=hash('sha256',stableSourcePayload(['item'=>$itemId,'column'=>$columnId,'value'=>$columnValue]));
    $mid=$itemId.':'.$columnId; $e=entity($pdo,$account,'cell',$mid,null,null,$rawHash,$runId);
    if($v['skip']) {
        $old=one($pdo,'SELECT id FROM dbo.tb_cell WHERE item_id=? AND column_id=? AND deleted_at IS NULL',[$localItem,$localColumn]);
        if($old)execute($pdo,'UPDATE dbo.tb_cell SET value_text=NULL,value_number=NULL,value_date=NULL,value_datetime=NULL,value_boolean=NULL,value_json=NULL,deleted_at=SYSUTCDATETIME(),updated_by=?,updated_at=SYSUTCDATETIME() WHERE id=?',[$actor,$old['id']]);
        checkpoint($pdo,$runId,$e['id'],$account,'skipped',$rawHash,$v['reason']);
        return null;
    }
    if(!empty($v['unmapped_identity']))$skipCounts['unmapped_identity_values_preserved']++;
    if(!empty($v['preserved_relation_json']))$skipCounts['relation_values_preserved_json']++;
    $storage=['value_text'=>null,'value_number'=>null,'value_date'=>null,'value_datetime'=>null,'value_boolean'=>null,'value_json'=>null];
    $storage[$v['field']]=$v['value'];
    $row=one($pdo,'SELECT id FROM dbo.tb_cell WHERE item_id=? AND column_id=?',[$localItem,$localColumn]);
    if($row) {
        execute($pdo,'UPDATE dbo.tb_cell SET board_id=?,value_text=?,value_number=?,value_date=?,value_datetime=?,value_boolean=?,value_json=?,created_by=COALESCE(created_by,?),updated_by=?,updated_at=SYSUTCDATETIME(),deleted_at=NULL WHERE id=?',[$boardId,$storage['value_text'],$storage['value_number'],$storage['value_date'],$storage['value_datetime'],$storage['value_boolean'],$storage['value_json'],$actor,$actor,$row['id']]);
        $cellId=(int)$row['id'];
    } else $cellId=insertId($pdo,'INSERT dbo.tb_cell(item_id,column_id,board_id,value_text,value_number,value_date,value_datetime,value_boolean,value_json,created_by,updated_by,updated_at) OUTPUT INSERTED.id VALUES(?,?,?,?,?,?,?,?,?,?,?,SYSUTCDATETIME())',[$localItem,$localColumn,$boardId,$storage['value_text'],$storage['value_number'],$storage['value_date'],$storage['value_datetime'],$storage['value_boolean'],$storage['value_json'],$actor,$actor]);
    entity($pdo,$account,'cell',$mid,$cellId,null,$rawHash,$runId); checkpoint($pdo,$runId,$e['id'],$account,'complete',$rawHash); return $cellId;
}

function reconcileBoardBeforeCommit(PDO $pdo,string $account,int $runId,string $sourceBoardId,int $localBoard,int $workspaceId,?int $folderId,array $board): void {
    $localBoardRow=one($pdo,'SELECT workspace_id,folder_id,created_by,visibility FROM dbo.tb_board WHERE id=? AND deleted_at IS NULL',[$localBoard]);
    if(!$localBoardRow||(int)$localBoardRow['workspace_id']!==$workspaceId||($localBoardRow['folder_id']===null?null:(int)$localBoardRow['folder_id'])!==$folderId||(int)$localBoardRow['created_by']!==MONDAY_IMPORT_ACTOR||$localBoardRow['visibility']!=='private')throw new RuntimeException('board_reconciliation_location_or_visibility_mismatch');
    $expectedGroups=$board['groups']??[];$expectedColumns=$board['columns']??[];$expectedItems=flattenItems($board['items_page']['items']??[]);$expectedFiles=flatAssets($board['items_page']['items']??[]);
    foreach([['group',$expectedGroups,'tb_group'],['column',$expectedColumns,'tb_column'],['item',$expectedItems,'tb_item']] as [$type,$rows,$table]) {
        if((int)(one($pdo,"SELECT COUNT_BIG(*) n FROM dbo.{$table} WHERE board_id=? AND deleted_at IS NULL",[$localBoard])['n']??-1)!==count($rows))throw new RuntimeException('board_reconciliation_entity_count_mismatch');
        foreach($rows as $row) {
            $mid=(string)$row['id'];$map=one($pdo,'SELECT local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type=? AND monday_id=?',[$account,$type,$mid]);
            if(!$map||$map['local_id']===null||!one($pdo,"SELECT id FROM dbo.{$table} WHERE id=? AND board_id=? AND deleted_at IS NULL",[$map['local_id'],$localBoard]))throw new RuntimeException('board_reconciliation_entity_id_missing');
            if($type==='item') {
                $local=one($pdo,'SELECT group_id,parent_item_id,name FROM dbo.tb_item WHERE id=? AND board_id=? AND deleted_at IS NULL',[$map['local_id'],$localBoard]);
                if(!$local||trim((string)$local['name'])!==(string)($row['name']??''))throw new RuntimeException('board_reconciliation_item_name_mismatch');
                $sourceGroup=(string)($row['group']['id']??'');$gm=one($pdo,"SELECT local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='group' AND monday_id=?",[$account,$sourceGroup]);
                if(!$local||!$gm||(int)$local['group_id']!==(int)$gm['local_id'])throw new RuntimeException('board_reconciliation_item_group_mismatch');
                $parentMid=(string)($row['parent_item']['id']??'');$expectedParent=null;if($parentMid!==''){$pm=one($pdo,"SELECT local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='item' AND monday_id=?",[$account,$parentMid]);$expectedParent=$pm['local_id']??null;}
                if(($local['parent_item_id']===null?null:(int)$local['parent_item_id'])!==($expectedParent===null?null:(int)$expectedParent))throw new RuntimeException('board_reconciliation_item_parent_mismatch');
            }
        }
    }
    $cells=[]; $expectedLocalCells=[]; $sourceColumns=[]; $unsupported=[];
    foreach($expectedColumns as $col)$sourceColumns[(string)$col['id']]=$col;
    foreach($expectedItems as $item)foreach($item['column_values']??[] as $cv)if(isset($cv['id'])) {
        $itemMid=(string)$item['id'];$colMid=(string)$cv['id'];$mid=$itemMid.':'.$colMid;$cells[$mid]=true;
        $im=one($pdo,"SELECT local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='item' AND monday_id=?",[$account,$itemMid]);
        $cm=one($pdo,"SELECT local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='column' AND monday_id=?",[$account,$colMid]);
        if(!$im||!$im['local_id']||!$cm||!$cm['local_id'])throw new RuntimeException('board_reconciliation_cell_mapping_missing');
        $type=localType(strtolower((string)($sourceColumns[$colMid]['type']??$cv['type']??'text')),$unsupported,$colMid);
        $value=valueForCell($cv,$type);
        $tracked=one($pdo,"SELECT e.id,c.status,c.error_code FROM dbo.tb_monday_import_entity e LEFT JOIN dbo.tb_monday_import_checkpoint c ON c.entity_id=e.id AND c.run_id=? WHERE e.source_account_id=? AND e.entity_type='cell' AND e.monday_id=?",[$runId,$account,$mid]);
        if(!$tracked)throw new RuntimeException('board_reconciliation_cell_checkpoint_missing');
        if($type==='name') {
            if(($tracked['status']??'')!=='complete')throw new RuntimeException('board_reconciliation_name_cell_checkpoint_missing');
            continue; // The name column is represented by tb_item.name, checked above.
        }
        if($value['skip']) {
            if(($tracked['status']??'')!=='skipped'||($tracked['error_code']??'')!==$value['reason'])throw new RuntimeException('board_reconciliation_cell_skip_mismatch');
            continue;
        }
        if(($tracked['status']??'')!=='complete')throw new RuntimeException('board_reconciliation_cell_checkpoint_missing');
        $localCell=one($pdo,'SELECT value_text,value_number,value_date,value_datetime,value_boolean,value_json FROM dbo.tb_cell WHERE item_id=? AND column_id=? AND deleted_at IS NULL',[$im['local_id'],$cm['local_id']]);
        if(!$localCell)throw new RuntimeException('board_reconciliation_cell_missing');
        $field=$value['field'];$actual=$localCell[$field]??null;$expected=$value['value'];
        if($field==='value_number') { if(decimalCanonical($actual)===null||decimalCanonical($actual)!==decimalCanonical($expected))throw new RuntimeException('board_reconciliation_cell_value_mismatch'); }
        elseif($field==='value_json') { $aj=json_decode((string)$actual,true);$ej=json_decode((string)$expected,true);$af=json_encode($aj,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$ef=json_encode($ej,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if($af!==$ef)throw new RuntimeException('board_reconciliation_cell_value_mismatch'); }
        elseif((string)$actual!==(string)$expected)throw new RuntimeException('board_reconciliation_cell_value_mismatch');
        $expectedLocalCells[(int)$im['local_id'].':'.(int)$cm['local_id']]=true;
    }
    $tracked=all($pdo,"SELECT e.monday_id,c.status FROM dbo.tb_monday_import_entity e LEFT JOIN dbo.tb_monday_import_checkpoint c ON c.entity_id=e.id AND c.run_id=? WHERE e.source_account_id=? AND e.entity_type='cell' AND e.last_seen_run_id=?",[$runId,$account,$runId]);
    $cellStatuses=[];foreach($tracked as $row)$cellStatuses[(string)$row['monday_id']]=(string)($row['status']??'');
    foreach(array_keys($cells) as $mid)if(!isset($cellStatuses[$mid])||!in_array($cellStatuses[$mid],['complete','skipped'],true))throw new RuntimeException('board_reconciliation_cell_checkpoint_missing');
    $activeLocalCells=all($pdo,'SELECT item_id,column_id FROM dbo.tb_cell WHERE board_id=? AND deleted_at IS NULL',[$localBoard]);
    foreach($activeLocalCells as $cell)if(!isset($expectedLocalCells[(int)$cell['item_id'].':'.(int)$cell['column_id']]))throw new RuntimeException('board_reconciliation_extra_local_cell');
    if(count($activeLocalCells)!==count($expectedLocalCells))throw new RuntimeException('board_reconciliation_cell_count_mismatch');
    foreach($expectedFiles as $fileRow) {
        $assetId=(string)$fileRow['asset']['id'];$fm=one($pdo,"SELECT e.id,e.local_id,c.status,fc.transfer_status,fc.derivatives_status FROM dbo.tb_monday_import_entity e JOIN dbo.tb_monday_import_checkpoint c ON c.entity_id=e.id AND c.run_id=? LEFT JOIN dbo.tb_monday_import_file_checkpoint fc ON fc.entity_id=e.id AND fc.run_id=? WHERE e.source_account_id=? AND e.entity_type='file' AND e.monday_id=?",[$runId,$runId,$account,$assetId]);
        if(!$fm||$fm['local_id']===null||$fm['status']!=='complete'||$fm['transfer_status']!=='complete'||!in_array($fm['derivatives_status'],['complete','not_required'],true))throw new RuntimeException('board_reconciliation_file_checkpoint_missing');
        if(!one($pdo,'SELECT id FROM dbo.tb_file WHERE id=? AND board_id=? AND deleted_at IS NULL',[$fm['local_id'],$localBoard]))throw new RuntimeException('board_reconciliation_file_id_missing');
    }
}

function flatAssets(array $items): array {
    $out=[]; foreach(flattenItems($items) as $item) foreach(($item['assets']??[]) as $asset) if(is_array($asset)&&isset($asset['id'])) $out[]=['asset'=>$asset,'item'=>$item]; return $out;
}
function columnForAsset(array $item,string $assetId,array $sourceColumns,array $localColumns): ?string {
    foreach($item['column_values']??[] as $cv) {
        $columnId=(string)($cv['id']??''); $column=$sourceColumns[$columnId]??null; if(!$column||($column['type']??'')!=='file')continue;
        $value=json_decode((string)($cv['value']??''),true); $ids=[];
        $walk=function($v)use(&$walk,&$ids){if(!is_array($v))return;foreach($v as $k=>$child){if(in_array((string)$k,['assetId','asset_id','id'],true)&&is_scalar($child))$ids[]=(string)$child;$walk($child);}};$walk($value);
        if(in_array($assetId,$ids,true)) return $localColumns[$columnId]??null;
    }
    return null;
}
function downloadAsset(string $assetId,string $token,?string $caBundle): array {
    $max=500*1024*1024;
    for($attempt=0;$attempt<5;$attempt++) {
        // Monday public_url expires after one hour; refresh it immediately before each transfer.
        $fresh=requestMonday($token,'query($ids:[ID!]){assets(ids:$ids){id name url public_url file_extension file_size created_at}}',['ids'=>[$assetId]],$caBundle);
        $asset=$fresh['assets'][0]??null;if(!is_array($asset))throw new RuntimeException('asset_not_visible');
        $url=(string)($asset['public_url']??'');$authorizedUrl=false;if($url===''){$url=(string)($asset['url']??'');$authorizedUrl=true;}
        if($url===''||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')throw new RuntimeException('asset_url_unavailable');
        $path=tempnam(sys_get_temp_dir(),'monday-import-');if($path===false)throw new RuntimeException('temp_create_failed');
        $out=fopen($path,'wb');if(!is_resource($out)){@unlink($path);throw new RuntimeException('temp_open_failed');}
        $currentUrl=$url;$sendAuthorization=$authorizedUrl;$ok=false;$status=0;$errno=0;
        for($hop=0;$hop<5;$hop++) {
            $location=null;$headers=$sendAuthorization?['Authorization: '.$token]:[];$ch=curl_init($currentUrl);
            $opts=[CURLOPT_FILE=>$out,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>30,CURLOPT_TIMEOUT=>600,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_NOPROGRESS=>false,
              CURLOPT_HEADERFUNCTION=>static function($resource,string $line)use(&$location):int{if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},
              CURLOPT_PROGRESSFUNCTION=>static function($resource,$dlTotal,$dlNow,$ulTotal,$ulNow)use($max){return $dlNow>$max?1:0;}];
            if($caBundle!==null)$opts[CURLOPT_CAINFO]=$caBundle;curl_setopt_array($ch,$opts);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
            if($ok!==false&&in_array($status,[301,302,303,307,308],true)&&is_string($location)&&$location!=='') {
                $base=parse_url($currentUrl);$loc=parse_url($location);
                if(!is_array($base)||!is_array($loc)){$ok=false;break;}
                if(isset($loc['scheme']))$next=$location;
                elseif(str_starts_with($location,'//'))$next='https:'.$location;
                elseif(str_starts_with($location,'/'))$next='https://'.$base['host'].(isset($base['port'])?':'.$base['port']:'').$location;
                else {$pathPart=(string)($base['path']??'/');$next='https://'.$base['host'].(isset($base['port'])?':'.$base['port']:'').substr($pathPart,0,(int)strrpos($pathPart,'/')+1).$location;}
                if(strtolower((string)parse_url($next,PHP_URL_SCHEME))!=='https'||parse_url($next,PHP_URL_USER)!==null||parse_url($next,PHP_URL_PASS)!==null){$ok=false;break;}
                $currentUrl=$next;$sendAuthorization=false;ftruncate($out,0);rewind($out);continue;
            }
            break;
        }
        fclose($out);$size=filesize($path);
        if($ok!==false&&$status>=200&&$status<300&&$size!==false&&$size>0&&$size<=$max)return [$asset,$path];
        @unlink($path);
        if(!in_array($status,[0,401,403,408,429,500,502,503,504],true)&&!in_array($errno,[6,7,18,28,35,52,55,56,60],true))throw new RuntimeException('asset_download_failed_http_'.$status.'_curl_'.$errno);
        if($attempt<4)usleep((int)(min(30,2**$attempt)*1000000+random_int(0,300000)));
    }
    throw new RuntimeException('asset_download_retries_exhausted');
}

function storageDownload(string $key,?string $target=null): ?string {
    $driver=strtolower((string)(getenv('TABLEROS_STORAGE_DRIVER')?:'local'));
    if($driver!=='nas_service') {
        $root=(string)(getenv('TABLEROS_PRIVATE_STORAGE')?:appRoot().'/uploads/tableros');
        $path=rtrim($root,'\\/').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$key);
        return is_file($path)?$path:null;
    }
    $base=rtrim((string)getenv('TABLEROS_STORAGE_SERVICE_URL'),'/');$token=(string)getenv('TABLEROS_STORAGE_SERVICE_TOKEN');
    if($base===''||strlen($token)<32)throw new RuntimeException('storage_service_unconfigured');
    $scheme=strtolower((string)parse_url($base,PHP_URL_SCHEME));$host=strtolower(trim((string)parse_url($base,PHP_URL_HOST),'[]'));
    if(!(($scheme==='https')||($scheme==='http'&&in_array($host,['127.0.0.1','::1'],true))))throw new RuntimeException('storage_service_transport_rejected');
    $path=$target??tempnam(sys_get_temp_dir(),'monday-storage-check-');if($path===false)throw new RuntimeException('storage_temp_failed');
    $out=fopen($path,'wb');if(!is_resource($out))throw new RuntimeException('storage_temp_failed');
    $ch=curl_init($base.'?key='.rawurlencode($key));curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_FILE=>$out,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>600,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);fclose($out);
    if($ok===false||$status===404){@unlink($path);return null;}if($status<200||$status>=300){@unlink($path);throw new RuntimeException('storage_get_failed_http_'.$status.'_curl_'.$errno);}return $path;
}
function expectedDerivatives(string $objectKey): array {
    if(preg_match('/^[a-f0-9]{64}$/',$objectKey))$prefix='1/1/1970/01';
    elseif(preg_match('~^([1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2]))/[a-f0-9]{2}/[a-f0-9]{64}$~',$objectKey,$m))$prefix=$m[1];
    else return [];
    $original=$objectKey;
    $thumb=hash('sha256','tableros-thumbnail-webp-v1:'.$original);$preview=hash('sha256','tableros-image-preview-webp-v1:'.$original);
    return ['thumbnail'=>$prefix.'/'.substr($thumb,0,2).'/'.$thumb,'preview'=>$prefix.'/'.substr($preview,0,2).'/'.$preview];
}
function verifyExistingFile(PDO $pdo,int $boardId,int $fileId,string $expectedHash,int $expectedSize,string $objectKey,bool $allowArchived=false): bool {
    if($allowArchived&&!one($pdo,"SELECT b.id FROM dbo.tb_board b JOIN dbo.tb_trash_entry t ON t.entity_type='board' AND t.entity_id=b.id AND t.entry_kind='archive' AND t.restored_at IS NULL WHERE b.id=? AND b.deleted_at IS NOT NULL",[$boardId]))return false;
    $row=one($pdo,'SELECT v.byte_size,LOWER(CONVERT(VARCHAR(64),v.sha256_hash,2)) sha256_hash,v.storage_object_key,v.content_type FROM dbo.tb_file f JOIN dbo.tb_file_version v ON v.id=f.current_version_id WHERE f.id=? AND f.board_id=? AND (?=1 OR f.deleted_at IS NULL)',[$fileId,$boardId,$allowArchived?1:0]);
    if(!$row||strtolower((string)$row['sha256_hash'])!==strtolower($expectedHash)||(int)$row['byte_size']!==$expectedSize||(string)$row['storage_object_key']!==$objectKey)return false;
    $original=storageDownload($objectKey);if($original===null)return false;
    $nas=strtolower((string)(getenv('TABLEROS_STORAGE_DRIVER')?:'local'))==='nas_service';
    try {if(filesize($original)!==$expectedSize||!hash_equals($expectedHash,(string)hash_file('sha256',$original)))return false;}finally{if($nas)@unlink($original);}
    if(str_starts_with(strtolower((string)$row['content_type']),'image/')) {
        $derivatives=expectedDerivatives($objectKey);if(count($derivatives)!==2)return false;
        foreach($derivatives as $key) {
        $derivative=storageDownload($key);if($derivative===null)return false;
        try{$info=@getimagesize($derivative);if(!is_array($info)||($info['mime']??'')!=='image/webp')return false;}finally{if($nas)@unlink($derivative);}
        }
    }
    return true;
}
function verifyArchivedBoardFiles(PDO $pdo,string $account,int $runId,int $boardId,array $board): bool {
    $archive=one($pdo,"SELECT b.id FROM dbo.tb_board b JOIN dbo.tb_trash_entry t ON t.entity_type='board' AND t.entity_id=b.id AND t.entry_kind='archive' AND t.restored_at IS NULL WHERE b.id=? AND b.deleted_at IS NOT NULL",[$boardId]);if(!$archive)return false;
    $assets=flatAssets($board['items_page']['items']??[]);$storedCount=(int)(one($pdo,'SELECT COUNT_BIG(*) n FROM dbo.tb_file WHERE board_id=?',[$boardId])['n']??0);
    if($storedCount!==count($assets))return false;$seen=[];
    foreach($assets as $assetRow){$assetId=(string)$assetRow['asset']['id'];$file=one($pdo,"SELECT e.id,e.local_id,e.payload_sha256,c.status checkpoint_status,fc.transfer_status,fc.derivatives_status,fc.original_sha256,fc.original_byte_size FROM dbo.tb_monday_import_entity e JOIN dbo.tb_monday_import_checkpoint c ON c.entity_id=e.id AND c.run_id=? LEFT JOIN dbo.tb_monday_import_file_checkpoint fc ON fc.entity_id=e.id AND fc.run_id=? WHERE e.source_account_id=? AND e.entity_type='file' AND e.monday_id=?",[$runId,$runId,$account,$assetId]);
        $sourceAssetHash=hash('sha256',stableSourcePayload($assetRow['asset']));
        if(!$file||!$file['local_id']||strtolower((string)$file['payload_sha256'])!==$sourceAssetHash||$file['checkpoint_status']!=='complete'||$file['transfer_status']!=='complete'||!preg_match('/^[a-f0-9]{64}$/i',(string)$file['original_sha256'])||(int)$file['original_byte_size']<1)return false;
        $localFile=(int)$file['local_id'];if(isset($seen[$localFile]))return false;$seen[$localFile]=true;
        $stored=one($pdo,'SELECT v.storage_object_key,v.content_type FROM dbo.tb_file f JOIN dbo.tb_file_version v ON v.id=f.current_version_id WHERE f.id=? AND f.board_id=?',[$localFile,$boardId]);if(!$stored)return false;
        $image=str_starts_with(strtolower((string)$stored['content_type']),'image/');if($file['derivatives_status']!==($image?'complete':'not_required'))return false;
        if(!verifyExistingFile($pdo,$boardId,$localFile,(string)$file['original_sha256'],(int)$file['original_byte_size'],(string)$stored['storage_object_key'],true))return false;
    }
    return true;
}
function setFileCheckpoint(PDO $pdo,int $runId,int $entityId,string $sha,int $size,bool $hasImageDerivative): void {
    $deriv=$hasImageDerivative?'complete':'not_required';
    execute($pdo,"IF EXISTS(SELECT 1 FROM dbo.tb_monday_import_file_checkpoint WHERE run_id=? AND entity_id=?) UPDATE dbo.tb_monday_import_file_checkpoint SET transfer_status='complete',derivatives_status=?,original_sha256=?,original_byte_size=?,attempt_count=attempt_count+1,error_code=NULL,error_summary=NULL,updated_at=SYSUTCDATETIME() WHERE run_id=? AND entity_id=? ELSE INSERT dbo.tb_monday_import_file_checkpoint(run_id,entity_id,transfer_status,derivatives_status,original_sha256,original_byte_size,attempt_count) VALUES(?,?,'complete',?,?,?,1)",[$runId,$entityId,$deriv,$sha,$size,$runId,$entityId,$runId,$entityId,$deriv,$sha,$size]);
}

function recordPilotAssociation(PDO $pdo,string $account,int $runId,array $entry,array $mapping,array $proof): void {
    $pdo->beginTransaction();
    try {
        $e=entity($pdo,$account,'board',MONDAY_IMPORT_PILOT_ID,(int)$mapping['local_id'],'active',$entry['hash'],$runId);
        foreach(['group'=>'groups','column'=>'columns','item'=>'items'] as $type=>$bucket)foreach(($proof['maps'][$bucket]??[]) as $mid=>$localId){
            $source=$proof['source'][$bucket][$mid]??[];$hash=hash('sha256',stableSourcePayload($source));$child=entity($pdo,$account,$type,(string)$mid,(int)$localId,null,$hash,$runId);checkpoint($pdo,$runId,$child['id'],$account,'complete',$hash);
        }
        foreach(($proof['cells']??[]) as $cell){$hash=hash('sha256',stableSourcePayload(['item'=>$cell['item_id'],'column'=>$cell['column_id'],'value'=>$cell['value']]));$mid=(string)$cell['item_id'].':'.(string)$cell['column_id'];$ce=entity($pdo,$account,'cell',$mid,$cell['local_cell_id']??null,null,$hash,$runId);checkpoint($pdo,$runId,$ce['id'],$account,$cell['status'],$hash,$cell['status']==='skipped'?$cell['reason']:null);}
        foreach(($proof['files']??[]) as $file){$hash=hash('sha256',stableSourcePayload($file['asset']));$fe=entity($pdo,$account,'file',(string)$file['asset']['id'],(int)$file['local_file_id'],null,$hash,$runId);checkpoint($pdo,$runId,$fe['id'],$account,'complete',$hash);setFileCheckpoint($pdo,$runId,$fe['id'],$file['sha256'],$file['size'],$file['is_image']);}
        checkpoint($pdo,$runId,$e['id'],$account,!empty($proof['ok'])?'complete':'skipped',$entry['hash'],!empty($proof['ok'])?null:(string)($proof['reason']??'needs_reconciliation'));
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function relationTargetIds($value): array {
    if(is_string($value)) { $parsed=json_decode($value,true); if(is_array($parsed))$value=$parsed; }
    $ids=[];
    $wanted=['linkedpulseids','linked_pulse_ids','itemids','item_ids','dependencies','depends_on','predecessors','successors','pulseid','itemid','linkeditemid'];
    $walk=function($node,?string $parentKey=null)use(&$walk,&$ids,$wanted) {
        if(is_array($node)) { foreach($node as $key=>$child) $walk($child,strtolower((string)$key)); return; }
        if($parentKey!==null&&in_array($parentKey,$wanted,true)&&is_scalar($node)&&preg_match('/^[0-9]+$/',(string)$node))$ids[]=(string)$node;
    };
    $walk($value);return array_values(array_unique($ids));
}

function resolveRelations(PDO $pdo,string $account,int $runId,array $boards,array &$totals): array {
    $boardDone=[];
    foreach($boards as $sourceBoardId=>$entry) {
        if($sourceBoardId===MONDAY_IMPORT_PILOT_ID)continue;
        if(($entry['meta']['state']??'')==='archived') {
            $boardDone[$sourceBoardId]=true;continue;
        }
        $pdo->beginTransaction();
        try {
        $boardMap=one($pdo,"SELECT local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='board' AND monday_id=?",[$account,$sourceBoardId]);
        if(!$boardMap||!$boardMap['local_id']){$pdo->commit();continue;}
        $sourceLocalBoard=(int)$boardMap['local_id'];$allResolved=true;
        foreach(flattenItems($entry['board']['items_page']['items']??[]) as $item) {
            $itemMid=(string)$item['id'];$itemMap=one($pdo,"SELECT local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='item' AND monday_id=?",[$account,$itemMid]);
            if(!$itemMap||!$itemMap['local_id'])continue;
            foreach($item['column_values']??[] as $cv) {
                $colMid=(string)($cv['id']??'');$sourceCol=null;foreach($entry['board']['columns']??[] as $c)if((string)$c['id']===$colMid){$sourceCol=$c;break;}
                $type=strtolower((string)($sourceCol['type']??$cv['type']??''));if(!in_array($type,['board_relation','dependency'],true))continue;
                $hash=hash('sha256',stableSourcePayload(['item'=>$itemMid,'column'=>$colMid,'value'=>$cv]));$cellMid=$itemMid.':'.$colMid;$cell=entity($pdo,$account,'cell',$cellMid,null,null,$hash,$runId);$columnMap=one($pdo,"SELECT local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='column' AND monday_id=?",[$account,$colMid]);$localColumn=$columnMap&&$columnMap['local_id']?(int)$columnMap['local_id']:null;
                $targets=relationTargetIds($cv['value']??null);$resolved=0;
                foreach($targets as $targetMid) {
                    $target=one($pdo,"SELECT e.local_id,i.board_id,b.deleted_at,be.source_status target_source_status FROM dbo.tb_monday_import_entity e JOIN dbo.tb_item i ON i.id=e.local_id JOIN dbo.tb_board b ON b.id=i.board_id LEFT JOIN dbo.tb_monday_import_entity be ON be.source_account_id=e.source_account_id AND be.entity_type='board' AND be.local_id=i.board_id WHERE e.source_account_id=? AND e.entity_type='item' AND e.monday_id=? AND e.local_id IS NOT NULL",[$account,$targetMid]);
                    if(!$target){$totals['unresolved_relations']++;$allResolved=false;continue;}
                    if($target['deleted_at']!==null||($target['target_source_status']??'')==='archived')continue;
                    $targetItem=(int)$target['local_id'];$targetBoard=(int)$target['board_id'];if($targetItem===(int)$itemMap['local_id'])continue;
                    if($type==='board_relation') {
                        $exists=one($pdo,'SELECT id FROM dbo.tb_item_relation WHERE source_item_id=? AND target_board_id=? AND target_item_id=? AND relation_type=? AND deleted_at IS NULL',[$itemMap['local_id'],$targetBoard,$targetItem,'related']);
                        if(!$exists)execute($pdo,"INSERT dbo.tb_item_relation(source_board_id,source_item_id,target_board_id,target_item_id,column_id,relation_type,created_by,created_at) VALUES(?,?,?,?,?,'related',?,SYSUTCDATETIME())",[$sourceLocalBoard,$itemMap['local_id'],$targetBoard,$targetItem,$localColumn,MONDAY_IMPORT_ACTOR]);
                    } else {
                        $exists=one($pdo,'SELECT id FROM dbo.tb_item_dependency WHERE predecessor_item_id=? AND successor_item_id=? AND dependency_type=? AND deleted_at IS NULL',[$targetItem,$itemMap['local_id'],'finish_to_start']);
                        if(!$exists)execute($pdo,"INSERT dbo.tb_item_dependency(board_id,predecessor_item_id,successor_item_id,column_id,dependency_type,lag_minutes,created_by,created_at) VALUES(?,?,?,?,'finish_to_start',0,?,SYSUTCDATETIME())",[$sourceLocalBoard,$targetItem,$itemMap['local_id'],$localColumn,MONDAY_IMPORT_ACTOR]);
                    }
                    $resolved++;$totals['relation_edges']++;
                }
                checkpoint($pdo,$runId,$cell['id'],$account,$allResolved?'complete':'skipped',$hash,$allResolved?null:'unresolved_relation_target');
            }
        }
        $boardDone[$sourceBoardId]=$allResolved;
        $pdo->commit();
        } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    return $boardDone;
}

function importBoard(PDO $pdo,string $account,int $runId,string $sourceId,array $entry,array $explicitMaps,?string $caBundle,array &$totals): array {
    $board=$entry['board'];$state=(string)$entry['meta']['state'];$boardHash=$entry['hash'];
    if($sourceId===MONDAY_IMPORT_PILOT_ID) throw new RuntimeException('pilot_requires_explicit_preserve_path');
    $pdo->beginTransaction();
    try {
        $w=$board['workspace']??['id'=>'monday-default','name'=>'Monday'];$workspaceId=ensureLocalWorkspace($pdo,$account,$w,$runId);
        $folderId=ensureLocalFolder($pdo,$account,$board['folder']??[],$workspaceId,$runId);
        $be=entity($pdo,$account,'board',$sourceId,null,$state,$boardHash,$runId);
        $localBoard=$be['local_id'];
        if($sourceId===MONDAY_IMPORT_PILOT_ID) $localBoard=(int)$explicitMaps['boards'][$sourceId]['local_id'];
        $localNameChanged=false;
        if($localBoard===null) {
            [$localName,$localNameChanged]=localBoardName($pdo,$workspaceId,$folderId,(string)$board['name'],$sourceId,!empty($entry['meta']['source_name_duplicated']));
            $localBoard=insertId($pdo,'INSERT dbo.tb_board(workspace_id,folder_id,name,description,visibility,created_by,created_at,updated_at) OUTPUT INSERTED.id VALUES(?,?,?,?,\'private\',?,SYSUTCDATETIME(),SYSUTCDATETIME())',[$workspaceId,$folderId,$localName,isset($board['description'])?mb_substr((string)$board['description'],0,2000,'UTF-8'):null,MONDAY_IMPORT_ACTOR]);
            $totals['boards_created']++;
        } else {
            $valid=one($pdo,'SELECT id FROM dbo.tb_board WHERE id=? AND created_by=? AND visibility=\'private\' AND deleted_at IS NULL',[$localBoard,MONDAY_IMPORT_ACTOR]);
            if(!$valid) throw new RuntimeException('mapped_board_creator_visibility_mismatch');
            if($sourceId!==MONDAY_IMPORT_PILOT_ID) {[$localName,$localNameChanged]=localBoardName($pdo,$workspaceId,$folderId,(string)$board['name'],$sourceId,!empty($entry['meta']['source_name_duplicated']),$localBoard);execute($pdo,'UPDATE dbo.tb_board SET workspace_id=?,folder_id=?,name=?,description=?,updated_at=SYSUTCDATETIME() WHERE id=?', [$workspaceId,$folderId,$localName,isset($board['description'])?mb_substr((string)$board['description'],0,2000,'UTF-8'):null,$localBoard]);}
        }
        if($localNameChanged)$totals['name_collisions']++;
        entity($pdo,$account,'board',$sourceId,$localBoard,$state,$boardHash,$runId);
        $localGroups=[];$groups=$board['groups']??[];
        foreach($groups as $i=>$g) {
            $mid=(string)$g['id'];$hash=hash('sha256',stableSourcePayload($g));$e=entity($pdo,$account,'group',$mid,null,null,$hash,$runId);$lid=$e['local_id'];
            if($sourceId===MONDAY_IMPORT_PILOT_ID) $lid=(int)($explicitMaps['boards'][$sourceId]['groups'][$mid]??0);
            if(!$lid) $lid=insertId($pdo,'INSERT dbo.tb_group(board_id,name,color,sort_order,created_by,created_at,updated_at) OUTPUT INSERTED.id VALUES(?,?,?,?,?,SYSUTCDATETIME(),SYSUTCDATETIME())',[$localBoard,mb_substr((string)$g['title'],0,200,'UTF-8'),$g['color']??null,$i,MONDAY_IMPORT_ACTOR]);
            elseif($sourceId!==MONDAY_IMPORT_PILOT_ID) execute($pdo,'UPDATE dbo.tb_group SET name=?,color=?,sort_order=?,updated_at=SYSUTCDATETIME() WHERE id=? AND board_id=?',[mb_substr((string)$g['title'],0,200,'UTF-8'),$g['color']??null,$i,$lid,$localBoard]);
            $localGroups[$mid]=$lid;entity($pdo,$account,'group',$mid,$lid,null,$hash,$runId);checkpoint($pdo,$runId,$e['id'],$account,'complete',$hash);
        }
        $sourceColumns=[];$localColumns=[];$unsupported=[];
        foreach($board['columns']??[] as $i=>$c) {
            $mid=(string)$c['id'];$sourceColumns[$mid]=$c;$type=localType(strtolower((string)$c['type']),$unsupported,$mid);$hash=hash('sha256',stableSourcePayload($c));$e=entity($pdo,$account,'column',$mid,null,null,$hash,$runId);$lid=$e['local_id'];
            if($sourceId===MONDAY_IMPORT_PILOT_ID) $lid=(int)($explicitMaps['boards'][$sourceId]['columns'][$mid]??0);
            if(!$lid) $lid=insertId($pdo,'INSERT dbo.tb_column(board_id,name,type,options_json,required,sort_order,enforce_unique,created_by,created_at,updated_at) OUTPUT INSERTED.id VALUES(?,?,?,?,0,?,0,?,SYSUTCDATETIME(),SYSUTCDATETIME())',[$localBoard,mb_substr((string)$c['title'],0,200,'UTF-8'),$type,optionJson($c),$i,MONDAY_IMPORT_ACTOR]);
            elseif($sourceId!==MONDAY_IMPORT_PILOT_ID)execute($pdo,'UPDATE dbo.tb_column SET name=?,type=?,options_json=?,sort_order=?,updated_at=SYSUTCDATETIME() WHERE id=? AND board_id=?',[mb_substr((string)$c['title'],0,200,'UTF-8'),$type,optionJson($c),$i,$lid,$localBoard]);
            $localColumns[$mid]=$lid;entity($pdo,$account,'column',$mid,$lid,null,$hash,$runId);checkpoint($pdo,$runId,$e['id'],$account,'complete',$hash);
        }
        $items=flattenItems($board['items_page']['items']??[]);$localItems=[];$pendingParents=[];$sortByGroup=[];
        // Pass 1: all item IDs exist before assigning parent_item_id.
        foreach($items as $item) {
            $mid=(string)$item['id'];$groupMid=(string)($item['group']['id']??'');$groupId=$localGroups[$groupMid]??null;
            if(!$groupId) throw new RuntimeException('source_item_group_unmapped');
            $name=mb_substr((string)($item['name']??''),0,500,'UTF-8');$hash=hash('sha256',stableSourcePayload($item));$e=entity($pdo,$account,'item',$mid,null,null,$hash,$runId);$lid=$e['local_id'];
            if($sourceId===MONDAY_IMPORT_PILOT_ID) $lid=(int)($explicitMaps['boards'][$sourceId]['items'][$mid]??0);
            $sort=$sortByGroup[$groupMid]??0;$sortByGroup[$groupMid]=$sort+1;
            if(!$lid)$lid=insertId($pdo,'INSERT dbo.tb_item(board_id,group_id,parent_item_id,name,sort_order,created_by,updated_by,created_at,updated_at) OUTPUT INSERTED.id VALUES(?,?,NULL,?,?,?, ?,SYSUTCDATETIME(),SYSUTCDATETIME())',[$localBoard,$groupId,$name,$sort,MONDAY_IMPORT_ACTOR,MONDAY_IMPORT_ACTOR]);
            elseif($sourceId!==MONDAY_IMPORT_PILOT_ID)execute($pdo,'UPDATE dbo.tb_item SET group_id=?,name=?,sort_order=?,updated_by=?,updated_at=SYSUTCDATETIME() WHERE id=? AND board_id=?',[$groupId,$name,$sort,MONDAY_IMPORT_ACTOR,$lid,$localBoard]);
            $localItems[$mid]=$lid;$pendingParents[$mid]=(string)($item['parent_item']['id']??'');entity($pdo,$account,'item',$mid,$lid,null,$hash,$runId);checkpoint($pdo,$runId,$e['id'],$account,'complete',$hash);
        }
        // Pass 2: parent links are applied only after every Monday ID is mapped.
        foreach($pendingParents as $mid=>$parentMid) if($parentMid!==''&&isset($localItems[$parentMid])) execute($pdo,'UPDATE dbo.tb_item SET parent_item_id=? WHERE id=? AND board_id=?',[$localItems[$parentMid],$localItems[$mid],$localBoard]);
        foreach($items as $item) {
            $itemId=(string)$item['id'];$localItem=$localItems[$itemId];
            foreach($item['column_values']??[] as $cv) {
                $colMid=(string)($cv['id']??'');if(!isset($localColumns[$colMid]))continue;$sourceCol=$sourceColumns[$colMid]??[];$type=localType(strtolower((string)($sourceCol['type']??$cv['type']??'text')),$unsupported,$colMid);
                if($type==='name') {$mid=$itemId.':'.$colMid;$cellHash=hash('sha256',stableSourcePayload(['item'=>$itemId,'column'=>$colMid,'value'=>$cv]));$ce=entity($pdo,$account,'cell',$mid,null,null,$cellHash,$runId);checkpoint($pdo,$runId,$ce['id'],$account,'complete',$cellHash);continue;}
                saveCell($pdo,$account,$runId,$itemId,$colMid,$localItem,$localColumns[$colMid],$localBoard,$type,$cv,MONDAY_IMPORT_ACTOR,$totals);
            }
        }
        // File bytes are transferred while the board transaction is open. If SQL rolls back,
        // deterministic NAS keys remain safe to verify/reuse on replay.
        $model=new TablerosModel($pdo);$assetList=flatAssets($board['items_page']['items']??[]);
        foreach($assetList as $assetRow) {
            $asset=$assetRow['asset'];$item=$assetRow['item'];$assetId=(string)$asset['id'];$itemMid=(string)$item['id'];$localItem=$localItems[$itemMid]??null;if(!$localItem)continue;
            $fileHash=hash('sha256',stableSourcePayload($asset));$fe=entity($pdo,$account,'file',$assetId,null,null,$fileHash,$runId);
            [$asset,$temp]=downloadAsset($assetId,(string)$entry['token'],$caBundle);
            try {
                $rawHash=(string)hash_file('sha256',$temp);$size=(int)filesize($temp);$ext=strtolower((string)($asset['file_extension']??pathinfo((string)($asset['name']??''),PATHINFO_EXTENSION)));
                $name=(string)($asset['name']??('monday-'.$assetId.($ext!==''?'.'.$ext:'')));
                $columnMid=columnForAsset($item,$assetId,$sourceColumns,$localColumns);$columnId=$columnMid!==null?(int)($localColumns[$columnMid]??0):null;if($columnId===0)$columnId=null;
                $date=DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM,(string)($asset['created_at']??''))?:new DateTimeImmutable('1970-01-01T00:00:00+00:00');$hash=hash('sha256',$account.':'.$sourceId.':'.$assetId.':'.$rawHash);$objectKey=$localBoard.'/'.$localItem.'/'.$date->format('Y/m').'/'.substr($hash,0,2).'/'.$hash;
                $localFile=$fe['local_id'];$input=['item_id'=>$localItem,'column_id'=>$columnId,'file_id'=>$localFile];
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($temp)?:'application/octet-stream';$isImage=str_starts_with(strtolower($mime),'image/');
                if($localFile!==null&&verifyExistingFile($pdo,(int)$localBoard,(int)$localFile,$rawHash,$size,$objectKey)) {
                    $result=['id'=>(int)$localFile];$totals['files_skipped']++;
                } else {
                    $upload=['name'=>$name,'tmp_name'=>$temp,'size'=>$size,'error'=>UPLOAD_ERR_OK,'_tableros_import_object_key'=>$objectKey];
                    $result=$model->uploadFile((int)$localBoard,MONDAY_IMPORT_ACTOR,$input,$upload);$totals['files_imported']++;
                    if(!verifyExistingFile($pdo,(int)$localBoard,(int)$result['id'],$rawHash,$size,$objectKey)) throw new RuntimeException('uploaded_asset_integrity_check_failed');
                }
                entity($pdo,$account,'file',$assetId,(int)$result['id'],null,$fileHash,$runId);checkpoint($pdo,$runId,$fe['id'],$account,'complete',$fileHash);setFileCheckpoint($pdo,$runId,$fe['id'],$rawHash,$size,$isImage);
            } finally { @unlink($temp); }
        }
        reconcileBoardBeforeCommit($pdo,$account,$runId,$sourceId,(int)$localBoard,$workspaceId,$folderId,$board);
        // Relation IDs are stored for global second-pass resolution after all boards are mapped.
        foreach($unsupported as $u)$totals['unsupported_fields'][]=['board_id'=>$sourceId]+$u;
        $pdo->commit();
        return ['id'=>$sourceId,'status'=>'content_imported','local_id'=>$localBoard,'unsupported_field_count'=>count($unsupported),'counts'=>boardCounts($board)];
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function verifyPilot(PDO $pdo,array $source,array $mapping,string $account,string $token,?string $caBundle): array {
    $fail=fn(string $reason)=>['ok'=>false,'reason'=>$reason];$localId=(int)($mapping['local_id']??0);
    if($localId<=0||empty($mapping['reviewed']))return $fail('pilot_explicit_board_mapping_missing');
    $b=one($pdo,'SELECT id FROM dbo.tb_board WHERE id=? AND created_by=6371 AND visibility=\'private\' AND deleted_at IS NULL',[$localId]);if(!$b)return $fail('pilot_local_board_identity_or_visibility_mismatch');
    $groups=all($pdo,'SELECT id,name FROM dbo.tb_group WHERE board_id=? AND deleted_at IS NULL ORDER BY sort_order,id',[$localId]);$groupByName=[];foreach($groups as $g)$groupByName[(string)$g['name']][]=(int)$g['id'];
    $groupMap=[];$sourceGroups=[];foreach($source['groups']??[] as $g){$mid=(string)$g['id'];$name=(string)$g['title'];$sourceGroups[$mid]=$g;if(count($groupByName[$name]??[])!==1)return $fail('pilot_group_title_match_not_unique');$groupMap[$mid]=$groupByName[$name][0];}
    if(count($groups)!==count($groupMap))return $fail('pilot_group_count_mismatch');
    $columns=all($pdo,'SELECT id,name,type FROM dbo.tb_column WHERE board_id=? AND deleted_at IS NULL ORDER BY sort_order,id',[$localId]);$columnByName=[];foreach($columns as $c)$columnByName[(string)$c['name']][]=$c;
    $columnMap=[];$sourceColumns=[];foreach($source['columns']??[] as $c){$mid=(string)$c['id'];$sourceColumns[$mid]=$c;$name=(string)$c['title'];if(count($columnByName[$name]??[])!==1)return $fail('pilot_column_title_match_not_unique');$candidate=$columnByName[$name][0];$unsupported=[];$mapped=localType(strtolower((string)$c['type']),$unsupported,$mid);if($unsupported||strtolower((string)$candidate['type'])!==$mapped)return $fail('pilot_column_type_mismatch');$columnMap[$mid]=(int)$candidate['id'];}
    if(count($columns)!==count($columnMap))return $fail('pilot_column_count_mismatch');
    $localItems=all($pdo,'SELECT id,group_id,parent_item_id,name FROM dbo.tb_item WHERE board_id=? AND deleted_at IS NULL ORDER BY id',[$localId]);$itemsBySignature=[];
    foreach($localItems as $li){$sig=(int)$li['group_id'].'|'.($li['parent_item_id']===null?'':(int)$li['parent_item_id']).'|'.(string)$li['name'];$itemsBySignature[$sig][]=(int)$li['id'];}
    $sourceItems=flattenItems($source['items_page']['items']??[]);$itemMap=[];$itemByMid=[];
    foreach($sourceItems as $item)$itemByMid[(string)$item['id']]=$item;
    $pending=$sourceItems;$pass=0;
    while($pending&&$pass++<count($sourceItems)+1){$next=[];foreach($pending as $item){$mid=(string)$item['id'];$gmid=(string)($item['group']['id']??'');$parent=(string)($item['parent_item']['id']??'');if(!isset($groupMap[$gmid]))return $fail('pilot_item_group_mapping_missing');if($parent!==''&&!isset($itemMap[$parent])){$next[]=$item;continue;}$sig=$groupMap[$gmid].'|'.($parent===''?'':$itemMap[$parent]).'|'.(string)($item['name']??'');if(count($itemsBySignature[$sig]??[])!==1)return $fail('pilot_item_match_not_unique');$itemMap[$mid]=$itemsBySignature[$sig][0];unset($itemsBySignature[$sig]);}if(count($next)===count($pending))break;$pending=$next;}
    if($pending||count($localItems)!==count($itemMap))return $fail('pilot_item_count_or_parent_match_mismatch');
    $expectedCells=[];$cellProof=[];
    foreach($sourceItems as $item){$im=(string)$item['id'];$localItem=$itemMap[$im];if(trim((string)($item['name']??''))!==(string)one($pdo,'SELECT name FROM dbo.tb_item WHERE id=?',[$localItem])['name'])return $fail('pilot_item_name_mismatch');
        foreach($item['column_values']??[] as $cv){$cm=(string)($cv['id']??'');if(!isset($columnMap[$cm]))return $fail('pilot_cell_column_mapping_missing');$sourceCol=$sourceColumns[$cm];$unsupported=[];$type=localType(strtolower((string)$sourceCol['type']),$unsupported,$cm);$value=valueForCell($cv,$type);$mid=$im.':'.$cm;
            if($type==='name'){$cellProof[]=['item_id'=>$im,'column_id'=>$cm,'value'=>$cv,'status'=>'complete','local_cell_id'=>null];continue;}
            if($value['skip']){$cellProof[]=['item_id'=>$im,'column_id'=>$cm,'value'=>$cv,'status'=>'skipped','reason'=>$value['reason']];continue;}
            $local=one($pdo,'SELECT id,value_text,value_number,value_date,value_datetime,value_boolean,value_json FROM dbo.tb_cell WHERE item_id=? AND column_id=? AND deleted_at IS NULL',[$localItem,$columnMap[$cm]]);if(!$local)return $fail('pilot_local_cell_missing');$field=$value['field'];$actual=$local[$field]??null;$expected=$value['value'];
            if($field==='value_number'){if(decimalCanonical($actual)===null||decimalCanonical($actual)!==decimalCanonical($expected))return $fail('pilot_cell_number_mismatch');}
            elseif($field==='value_json'){try{$a=json_encode(json_decode((string)$actual,true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$e=json_encode(json_decode((string)$expected,true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $ex){return $fail('pilot_cell_json_invalid');}if($a!==$e)return $fail('pilot_cell_json_mismatch');}
            elseif((string)$actual!==(string)$expected)return $fail('pilot_cell_value_mismatch');
            $expectedCells[$localItem.':'.$columnMap[$cm]]=true;$cellProof[]=['item_id'=>$im,'column_id'=>$cm,'value'=>$cv,'status'=>'complete','local_cell_id'=>(int)$local['id']];
        }
    }
    $actualCells=all($pdo,'SELECT item_id,column_id FROM dbo.tb_cell WHERE board_id=? AND deleted_at IS NULL',[$localId]);foreach($actualCells as $cell)if(!isset($expectedCells[(int)$cell['item_id'].':'.(int)$cell['column_id']]))return $fail('pilot_extra_local_cell');if(count($actualCells)!==count($expectedCells))return $fail('pilot_cell_count_mismatch');
    $assetRows=flatAssets($source['items_page']['items']??[]);$localFiles=(int)(one($pdo,'SELECT COUNT_BIG(*) n FROM dbo.tb_file WHERE board_id=? AND deleted_at IS NULL',[$localId])['n']??0);if($localFiles!==count($assetRows))return $fail('pilot_file_count_mismatch');$fileProof=[];$claimed=[];
    foreach($assetRows as $assetRow){$itemMid=(string)$assetRow['item']['id'];$assetId=(string)$assetRow['asset']['id'];[, $temp]=downloadAsset($assetId,$token,$caBundle);try{$sha=(string)hash_file('sha256',$temp);$size=(int)filesize($temp);$candidates=all($pdo,'SELECT f.id,v.storage_object_key,v.content_type FROM dbo.tb_file f JOIN dbo.tb_file_version v ON v.id=f.current_version_id WHERE f.board_id=? AND f.item_id=? AND f.deleted_at IS NULL',[$localId,$itemMap[$itemMid]]);$matches=[];foreach($candidates as $candidate){$fid=(int)$candidate['id'];if(isset($claimed[$fid]))continue;if(verifyExistingFile($pdo,$localId,$fid,$sha,$size,(string)$candidate['storage_object_key']))$matches[]=$candidate;}if(count($matches)!==1)return $fail(count($matches)===0?'pilot_asset_hash_or_storage_mismatch':'pilot_asset_storage_match_ambiguous');$file=$matches[0];$claimed[(int)$file['id']]=true;$fileProof[]=['asset'=>$assetRow['asset'],'local_file_id'=>(int)$file['id'],'sha256'=>$sha,'size'=>$size,'is_image'=>str_starts_with(strtolower((string)$file['content_type']),'image/')];}finally{@unlink($temp);}}
    return ['ok'=>true,'reason'=>'pilot_cells_values_assets_originals_and_webp_verified','maps'=>['groups'=>$groupMap,'columns'=>$columnMap,'items'=>$itemMap],'source'=>['groups'=>$sourceGroups,'columns'=>$sourceColumns,'items'=>$itemByMid],'cells'=>$cellProof,'files'=>$fileProof];
}

function relationSnapshot(array $meta,string $hash,string $account,array $board): array {
    $relationTypes=[];foreach($board['columns']??[] as $c)if(in_array(strtolower((string)$c['type']),['board_relation','dependency'],true))$relationTypes[(string)$c['id']]=true;
    $items=[];
    foreach(flattenItems($board['items_page']['items']??[]) as $item) {
        $cells=array_values(array_filter($item['column_values']??[],fn($cv)=>isset($relationTypes[(string)($cv['id']??'')])));
        $items[]=['id'=>(string)$item['id'],'group'=>$item['group']??null,'parent_item'=>$item['parent_item']??null,'column_values'=>$cells];
    }
    return ['meta'=>$meta,'hash'=>$hash,'account'=>$account,'board'=>['columns'=>array_values(array_map(fn($c)=>['id'=>(string)$c['id'],'type'=>(string)$c['type']],$board['columns']??[])),'items_page'=>['items'=>$items]]];
}
function persistBoardFailure(PDO $pdo,string $account,int $runId,string $id,string $state,string $hash): void {
    $pdo->beginTransaction();try{$e=entity($pdo,$account,'board',$id,null,$state,$hash,$runId);checkpoint($pdo,$runId,$e['id'],$account,'failed',$hash,'entity_import_failed');$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function main(array $argv): int {
    $a=parseArgs($argv);if(!empty($a['help'])){usage();return 0;}
    if(empty($a['map'])||empty($a['workbook']))throw new InvalidArgumentException('Se requieren --map y --workbook.');
    if($a['apply']&&(empty($a['run_key'])||empty($a['mapping_file'])))throw new InvalidArgumentException('--apply requiere --run-key y --mapping-file.');
    $map=jsonFile($a['map']);$inventory=$map['boards']??[];if(!is_array($inventory))throw new RuntimeException('El mapa no contiene boards.');
    $validKeyRows=[];foreach($map['keys']??[] as $km)if(($km['status']??'')==='ok')$validKeyRows[(int)($km['source_row']??0)]=true;
    $keys=workbookKeys($a['workbook'],$validKeyRows);$tokensByRow=[];foreach($map['keys']??[] as $km){$r=(int)($km['source_row']??0);if(($km['status']??'')==='ok'&&isset($keys[$r]))$tokensByRow[$r]=$keys[$r];}unset($keys);
    $coverage=assessCoverage($map,$inventory);
    $coverageMeta=['derived'=>$coverage,'keys'=>array_map(fn($k)=>[(int)($k['source_row']??0),(string)($k['status']??''),(int)($k['http_status']??0),(int)($k['board_count']??0)],$map['keys']??[]),'failed_keys'=>array_map(fn($k)=>[(int)($k['source_row']??0),(string)($k['status']??''),(int)($k['http_status']??0)],$map['failed_keys']??[])];
    $coverageHash=hash('sha256',stableSourcePayload($coverageMeta));
    $existingKeyCount=$coverage['existing_key_count'];
    $coverageComplete=$coverage['complete'];
    $sourceNameCounts=[];foreach($inventory as $sourceMeta)if(!empty($sourceMeta['importable'])&&in_array($sourceMeta['state']??'', ['active','archived'],true)){$nameKey=mb_strtolower(trim((string)($sourceMeta['name']??'')),'UTF-8');$sourceNameCounts[$nameKey]=($sourceNameCounts[$nameKey]??0)+1;}
    $selected=[];foreach($inventory as $meta){$id=(string)($meta['id']??'');if(!preg_match('/^[0-9]+$/',$id)||!in_array($meta['state']??'', ['active','archived'],true)||empty($meta['importable']))continue;if($a['board_ids']&&!in_array($id,$a['board_ids'],true))continue;$nameKey=mb_strtolower(trim((string)($meta['name']??'')),'UTF-8');$meta['source_name_duplicated']=($sourceNameCounts[$nameKey]??0)>1;$selected[$id]=$meta;}
    if($a['limit']!==null)$selected=array_slice($selected,0,$a['limit'],true);if(!$selected){echo "No hay boards activos/archivados seleccionados.\n";return 0;}
    $ca=$a['ca_bundle']??(getenv('MONDAY_CA_BUNDLE')?:null);if($ca!==null&&!is_file($ca))throw new InvalidArgumentException('El CA bundle configurado no existe.');
    $rows=[];foreach($selected as $m){$r=(int)($m['selected_key_source_row']??0);if(isset($tokensByRow[$r]))$rows[$r]=$tokensByRow[$r];}
    if(count($rows)===0)throw new RuntimeException('No hay claves fuente válidas para boards seleccionados.');
    $accountByRow=[];foreach($rows as $r=>$token)$accountByRow[$r]=(string)(requestMonday($token,'query{me{account{id}}}',[],$ca,5,'account_identity',(string)$r)['me']['account']['id']??'');
    if(in_array('',array_values($accountByRow),true)||count(array_unique(array_values($accountByRow)))!==1)throw new RuntimeException('Selected API keys span multiple Monday accounts or lack account identity.');
    $account=(string)reset($accountByRow);$summary=['active'=>0,'archived'=>0,'groups'=>0,'columns'=>0,'items'=>0,'subitems'=>0,'attachments'=>0];$ids=[];
    if(!$a['apply']) {
        $failedBoards=[];
        foreach($selected as $id=>$meta){$id=(string)$id;$row=(int)($meta['selected_key_source_row']??0);if(!isset($tokensByRow[$row])){$failedBoards[]=['board_id'=>$id,'status'=>'failed','stage'=>'key_lookup','error_code'=>'source_key_missing'];continue;}
            try{$board=fetchBoard($tokensByRow[$row],$id,$ca);$state=(string)($board['state']??'');if(!in_array($state,['active','archived'],true))throw new RuntimeException('source_state_not_importable');$counts=boardCounts($board);foreach($counts as $k=>$v)$summary[$k]+=$v;$summary[$state]++;$ids[]=$id;if(!$a['summary'])echo json_encode(['board_id'=>$id,'status'=>'read','state'=>$state,'counts'=>$counts],JSON_UNESCAPED_SLASHES)."\n";unset($board);}
            catch(Throwable $boardError){$message=$boardError->getMessage();$diag=['board_id'=>$id,'status'=>'failed','stage'=>'board_read','error_code'=>'board_read_failed'];if(preg_match('/stage=([a-z0-9_]+)/i',$message,$m))$diag['stage']=$m[1];if(preg_match('/http_status=([0-9]+)/i',$message,$m))$diag['http_status']=(int)$m[1];if(preg_match('/curl_errno=([0-9]+)/i',$message,$m))$diag['curl_errno']=(int)$m[1];if(preg_match('/error_class=([A-Z0-9_,]+)/',$message,$m))$diag['error_class']=$m[1];elseif(preg_match('/^([a-z0-9_]+)$/i',$message,$m))$diag['error_code']=$m[1];$failedBoards[]=$diag;}
        }
        $excludedOutput=array_map(fn($k)=>['source_row'=>(int)($k['source_row']??0),'status'=>(string)($k['status']??'excluded_nonexistent_key')],$map['excluded_keys']??[]);if(!$excludedOutput)$excludedOutput=[['source_row'=>31,'status'=>'excluded_nonexistent_key']];
        $failedKeyOutput=array_values(array_map(fn($k)=>['source_row'=>(int)($k['source_row']??0),'http_status'=>(int)($k['http_status']??0)],array_filter($map['failed_keys']??[],fn($k)=>!((int)($k['source_row']??0)===31&&($k['status']??'')==='http_error'&&(int)($k['http_status']??0)===401))));
        echo json_encode(['mode'=>'dry-run','source_account_id'=>$account,'coverage_complete'=>$coverageComplete,'coverage'=>$coverage,'excluded_key_rows'=>$excludedOutput,'failed_key_rows'=>$failedKeyOutput,'board_count'=>count($ids),'failed_board_count'=>count($failedBoards),'failed_boards'=>$failedBoards,'summary'=>$summary,'board_ids'=>$ids],JSON_UNESCAPED_SLASHES)."\n";return 0;
    }
    $maps=jsonFile($a['mapping_file']);$pdo=db();$schema=(int)$pdo->query("SELECT COUNT(*) FROM sys.tables WHERE name IN('tb_monday_import_run','tb_monday_import_entity','tb_monday_import_checkpoint','tb_monday_import_file_checkpoint')")->fetchColumn();if($schema!==4)throw new RuntimeException('Falta migración 013; no se escribe.');
    // Reconcile the already-imported pilot before opening an import run. Never recreate or rewrite it.
    $pilotSource=null;$pilotEntry=null;$pilotProof=['ok'=>false,'reason'=>'pilot_reconciliation_not_run'];
    if(isset($selected[MONDAY_IMPORT_PILOT_ID])) {
        $pm=$maps['boards'][MONDAY_IMPORT_PILOT_ID]??[];if(empty($pm['reviewed'])||empty($pm['local_id']))throw new RuntimeException('Pilot requiere mapping explícito revisado; no se escribe.');
        $row=(int)$selected[MONDAY_IMPORT_PILOT_ID]['selected_key_source_row'];$pilotSource=fetchBoard($tokensByRow[$row],MONDAY_IMPORT_PILOT_ID,$ca);
        try{$pilotProof=verifyPilot($pdo,$pilotSource,$pm,$account,$tokensByRow[$row],$ca);}catch(Throwable $pilotError){$code=$pilotError->getMessage();$pilotProof=['ok'=>false,'reason'=>preg_match('/^[a-z0-9_]+$/i',$code)?$code:'pilot_readonly_reconciliation_failed'];}
        $pilotMeta=$selected[MONDAY_IMPORT_PILOT_ID];$pilotHash=hash('sha256',stableSourcePayload($pilotSource));$pilotEntry=['meta'=>$pilotMeta,'hash'=>$pilotHash,'account'=>$account,'board'=>$pilotSource];
    }
    $runId=ensureRun($pdo,$account,$a['run_key'],$inventory,(string)($map['inventory_time_utc']??gmdate('c')),$coverageComplete,$coverageHash);
    $totals=['boards_created'=>0,'name_collisions'=>0,'files_imported'=>0,'files_skipped'=>0,'relation_edges'=>0,'unresolved_relations'=>0,'unmapped_identity_values_preserved'=>0,'relation_values_preserved_json'=>0,'unsupported_fields'=>[]];$results=[];$successful=[];$relationBoards=[];$pilotPending=false;
    foreach($selected as $id=>$meta) {
        $id=(string)$id;
        $row=(int)($meta['selected_key_source_row']??0);if(!isset($tokensByRow[$row])){$results[]=['id'=>$id,'status'=>'needs_key'];$pilotPending=true;continue;}
        if($id===MONDAY_IMPORT_PILOT_ID) {
            recordPilotAssociation($pdo,$account,$runId,$pilotEntry,$maps['boards'][$id],$pilotProof);
            if(!empty($pilotProof['ok'])){$successful[$id]=['meta'=>$pilotEntry['meta'],'hash'=>$pilotEntry['hash'],'unsupported_field_count'=>0];$results[]=['id'=>$id,'status'=>'reconciled_preserved','local_id'=>(int)$maps['boards'][$id]['local_id'],'reason'=>'pilot_cells_values_assets_originals_and_webp_verified'];}
            else {$results[]=['id'=>$id,'status'=>'needs_reconciliation','local_id'=>(int)$maps['boards'][$id]['local_id'],'reason'=>(string)$pilotProof['reason']];$pilotPending=true;}
            continue;
        }
        try {
            $board=fetchBoard($tokensByRow[$row],$id,$ca);$live=(string)($board['state']??'');if(!in_array($live,['active','archived'],true))throw new RuntimeException('source_state_changed');
            $meta['state']=$live;$hash=hash('sha256',stableSourcePayload($board));$entry=['meta'=>$meta,'hash'=>$hash,'account'=>$account,'board'=>$board,'token'=>$tokensByRow[$row]];
            $prior=one($pdo,"SELECT id,local_id,payload_sha256 FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='board' AND monday_id=?",[$account,$id]);
            if($prior&&$prior['local_id']) {
                $local=(int)$prior['local_id'];
                $localState=one($pdo,'SELECT id,created_by,visibility,deleted_at FROM dbo.tb_board WHERE id=?',[$local]);
                $archive=one($pdo,"SELECT id FROM dbo.tb_trash_entry WHERE entity_type='board' AND entity_id=? AND entry_kind='archive' AND restored_at IS NULL",[$local]);
                $entityId=(int)$prior['id'];
                if($live==='archived') {
                    if($localState&&(int)$localState['created_by']===MONDAY_IMPORT_ACTOR&&$localState['visibility']==='private'&&$localState['deleted_at']!==null&&$archive&&checkpointIsComplete($pdo,$runId,$entityId,$hash)&&verifyArchivedBoardFiles($pdo,$account,$runId,$local,$board)) {
                        $successful[$id]=['meta'=>$meta,'hash'=>$hash,'unsupported_field_count'=>0];
                        $results[]=['id'=>$id,'status'=>'already_archived_verified','local_id'=>$local];unset($board,$entry);continue;
                    }
                    entity($pdo,$account,'board',$id,$local,$live,$hash,$runId);checkpoint($pdo,$runId,$entityId,$account,'skipped',$hash,'archived_board_reconciliation_required');
                    $results[]=['id'=>$id,'status'=>'needs_reconciliation','local_id'=>$local,'reason'=>'archived_board_requires_current_hash_checkpoint_and_archive_record'];$pilotPending=true;unset($board,$entry);continue;
                }
                if(!$localState||$localState['deleted_at']!==null) {
                    entity($pdo,$account,'board',$id,$local,$live,$hash,$runId);checkpoint($pdo,$runId,$entityId,$account,'skipped',$hash,'local_board_restore_not_supported');
                    $results[]=['id'=>$id,'status'=>'needs_reconciliation','local_id'=>$local,'reason'=>'local_archived_board_restore_not_supported'];$pilotPending=true;unset($board,$entry);continue;
                }
            }
            $result=importBoard($pdo,$account,$runId,$id,$entry,$maps,$ca,$totals);$results[]=$result;$successful[$id]=['meta'=>$meta,'hash'=>$hash,'unsupported_field_count'=>(int)($result['unsupported_field_count']??0)];$relationBoards[$id]=relationSnapshot($meta,$hash,$account,$board);unset($board,$entry);
        } catch(Throwable $e) {
            $safeCode=preg_match('/^[a-z0-9_]+$/i',$e->getMessage())?$e->getMessage():'entity_import_failed';
            try{persistBoardFailure($pdo,$account,$runId,$id,(string)$meta['state'],hash('sha256',$id.':'.$safeCode));}catch(Throwable $ignored){}
            $results[]=['id'=>$id,'status'=>'failed','error_code'=>$safeCode];$pilotPending=true;
        }
    }
    $relationProgress=resolveRelations($pdo,$account,$runId,$relationBoards,$totals);$model=new TablerosModel($pdo);$runStatus='complete';
    foreach($successful as $id=>$entry) {
        $bm=one($pdo,"SELECT id,local_id FROM dbo.tb_monday_import_entity WHERE source_account_id=? AND entity_type='board' AND monday_id=?",[$account,$id]);
        if(!$bm||!$bm['local_id']){$runStatus='paused';continue;}
        try {
            if(($entry['meta']['state']??'')==='archived') {$old=one($pdo,"SELECT id FROM dbo.tb_trash_entry WHERE entity_type='board' AND entity_id=? AND entry_kind='archive' AND restored_at IS NULL",[$bm['local_id']]);if(!$old)$model->trashEntity(MONDAY_IMPORT_ACTOR,['entity_type'=>'board','entity_id'=>(int)$bm['local_id']],'archive');}
            if(($relationProgress[$id]??true)===false||(int)($entry['unsupported_field_count']??0)>0){
                $runStatus='paused';$reason=($relationProgress[$id]??true)===false?'unresolved_relation_target':'unsupported_source_fields';
                checkpoint($pdo,$runId,(int)$bm['id'],$account,'skipped',$entry['hash'],$reason);continue;
            }
            checkpoint($pdo,$runId,(int)$bm['id'],$account,'complete',$entry['hash']);
        } catch(Throwable $e) {$runStatus='paused';$results[]=['id'=>$id,'status'=>'archive_or_finalize_failed','error_code'=>'archive_transition_failed'];}
    }
    if($pilotPending||count($selected)!==504||!$coverageComplete)$runStatus='paused';
    $failed=(bool)array_filter($results,fn($r)=>in_array($r['status']??'', ['failed','archive_or_finalize_failed','needs_key'],true));if($failed)$runStatus='paused';
    $runError=$runStatus==='complete'?null:(!$coverageComplete?'coverage_incomplete':($pilotPending?'pilot_or_entity_reconciliation_pending':'run_incomplete'));
    $runErrorSummary=$runError===null?null:match($runError){'coverage_incomplete'=>'La cobertura del conjunto de claves existentes no está completa.','pilot_or_entity_reconciliation_pending'=>'Hay tableros o entidades pendientes de reconciliación.','run_incomplete'=>'La ejecución terminó con entidades pendientes.'};
    execute($pdo,'UPDATE dbo.tb_monday_import_run SET status=?,error_code=?,error_summary=?,completed_at=CASE WHEN ?=\'complete\' THEN SYSUTCDATETIME() ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE id=?',[$runStatus,$runError,$runErrorSummary,$runStatus,$runId]);
    echo json_encode(['mode'=>'apply','run_id'=>$runId,'status'=>$runStatus,'results'=>$results,'totals'=>$totals],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";return 0;
}

try{exit(main($argv));}catch(Throwable $e){fwrite(STDERR,'ERROR: '.preg_replace('/[\r\n\t]+/',' ',$e->getMessage())."\n");exit(1);}
