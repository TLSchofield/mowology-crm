<?php
declare(strict_types=1);
if(!defined('APP_ROOT')){$__dir=__DIR__;for($__i=0;$__i<5;$__i++){$__dir=dirname($__dir);if(is_file($__dir.'/app/Core/paths.php')){require_once $__dir.'/app/Core/paths.php';break;}}unset($__dir,$__i);}
header('Content-Type: application/json; charset=utf-8');
require_once PUBLIC_ROOT.'/loginAuth/auth.php';
require_once APP_ROOT.'/Modules/Settings/Services/Registry/OrgBookBcProvider.php';
require_once APP_ROOT.'/Modules/Settings/Services/BusinessIdentityService.php';
require_once __DIR__.'/BusinessIdentityRequest.php';
$user=requireLoginOrJwt();$authHeader=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';$isJwt=strncmp($authHeader,'Bearer ',7)===0;
$method=$_SERVER['REQUEST_METHOD']??'GET';$mode=(string)($_GET['mode']??'');$write=$method==='POST'&&in_array($mode,['start','confirm','manual'],true);
$permission=$write?'settings.edit':'settings.view';$allowed=$isJwt?jwtUserHasPermission($user,$permission):userHasPermission($permission);
if(!$allowed){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Permission denied']);exit;}
$body=[];if($method==='POST'){$request=BusinessIdentityRequest::post((string)file_get_contents('php://input'),$_POST,$_SERVER,$isJwt);$body=$request['body'];
    if($request['csrf_required']&&!verifyCSRFToken($request['csrf_token'])){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']);exit;}}
try{
    $db=getDB();$tenantStatement=$db->prepare('SELECT DATABASE()');$tenantStatement->execute();$tenant=(string)$tenantStatement->fetchColumn();$service=new BusinessIdentityService($db,new OrgBookBcProvider($tenant),(int)($user['id']??0));
    $canEdit=$isJwt?jwtUserHasPermission($user,'settings.edit'):userHasPermission('settings.edit');
    if($method==='GET'&&$mode==='current')$result=$service->current($canEdit);
    elseif($method==='GET'&&$mode==='contacts')$result=['ok'=>true,'contacts'=>$service->contacts(),'google'=>'not_configured'];
    elseif($method==='GET'&&$mode==='search'){$name=trim((string)($_GET['q']??''));validateIdentityText($name,'q',2,120,true);$result=$service->search($name);}
    elseif($method==='POST'&&$mode==='start'){$name=trim((string)($body['name']??''));validateIdentityText($name,'name',2,120,true);$result=$service->start($name,$canEdit);}
    elseif($method==='POST'&&$mode==='confirm'){$id=(string)($body['record_id']??'');if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,119}$/',$id))throw new InvalidArgumentException('Invalid record_id');$result=$service->confirm($id,$canEdit);}
    elseif($method==='POST'&&$mode==='manual'){$result=$service->manual($body,$canEdit);}
    else{http_response_code(400);$result=['ok'=>false,'error'=>'Unsupported method or mode'];}
}catch(InvalidArgumentException $e){http_response_code(422);$result=['ok'=>false,'error'=>'Please check what you typed and try again.'];} // never echo exception text (NoRawExceptionMessagesTest)
catch(Throwable $e){error_log('business-identity endpoint failure: '.get_class($e));http_response_code(503);$result=['ok'=>false,'error'=>'Business identity is temporarily unavailable'];}
if(empty($result['ok'])&&http_response_code()===200)http_response_code(503);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
function validateIdentityText(string $value,string $field,int $min,int $max,bool $required):void{$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);if(($required&&$length<$min)||$length>$max)throw new InvalidArgumentException($field.' must be '.$min.'–'.$max.' characters');}
