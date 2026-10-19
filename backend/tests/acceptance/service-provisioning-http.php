<?php
if (getenv('HOWSTACK_PROVISIONING_HTTP_ACCEPTANCE') !== '1') exit('仅显式本地验收');
$base=dirname(__DIR__, 2);require $base.'/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();support\App::loadAllConfig(['route']);
if(in_array(strtolower((string)getenv('APP_ENV')),['production','prod'],true))exit('禁止生产运行');
use app\modules\member\infrastructure\model\{MemberModel,MemberSessionModel};
use app\modules\service_instance\infrastructure\model\ServiceInstanceModel;
$member=null;$other=null;$service=null;
function check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);
function callApi($path,$data=null,$origin='http://127.0.0.1:8989'){
 global $curl;
 curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1:8989'.$path,CURLOPT_CUSTOMREQUEST=>$data===null?'GET':'POST',CURLOPT_POSTFIELDS=>$data===null?null:json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Origin: '.$origin],CURLOPT_HEADER=>true]);
 $response=curl_exec($curl);$length=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$headers=substr($response,0,$length);$json=json_decode(substr($response,$length),true,flags:JSON_THROW_ON_ERROR);
 check(array_keys($json)===['code','message','data','request_id'],'响应字段不统一');check(stripos($headers,'X-Request-Id:')!==false,'缺少请求标识');
 return [curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$json,$headers];
}
try{
 [$status]=callApi('/api/console/v1/services');check($status===401,'匿名访问服务应拒绝');
 $create=function($name){return MemberModel::query()->create(['public_id'=>\app\modules\member\domain\PublicMemberId::generate(),'mobile'=>'139'.random_int(10000000,99999999),'nickname'=>$name,'password_hash'=>password_hash('Fixture@123456',PASSWORD_DEFAULT),'status'=>1]);};
 $member=$create('开通接口本地验收');$other=$create('开通接口越权验收');
 $service=ServiceInstanceModel::query()->create(['instance_no'=>'http-fixture-'.bin2hex(random_bytes(8)),'product_type'=>'cloud_server','member_id'=>$member->id,'display_name'=>'本地验收实例','status'=>'active','activated_at'=>date('Y-m-d H:i:s'),'expires_at'=>date('Y-m-d H:i:s',time()+86400)]);
 [$status]=callApi('/api/console/v1/auth/login',['account'=>$member->mobile,'password'=>'Fixture@123456','remember_me'=>false]);check($status===200,'本地会员登录失败');
 [$status,$json,$headers]=callApi('/api/console/v1/services/'.$service->id);check($status===200,'本人详情失败');check($json['data']['id']===$service->id,'服务归属不正确');check(!isset($json['data']['password']),'普通详情泄漏密码');check(stripos($headers,'no-store')!==false,'缺少禁止缓存');
 [$status]=callApi('/api/console/v1/services/'.$service->id.'/initial-password',[],'https://foreign.example.test');check($status===403,'跨站密码读取应拒绝');
 [$status]=callApi('/api/console/v1/services/'.$service->id.'/initial-password',[]);check($status===409,'无初始凭据不能返回密码');
 [$status]=callApi('/api/admin/v1/service-instances/'.$service->id.'/retry-provisioning',[]);check($status===401,'会员会话不能执行后台生产操作');
 [$status]=callApi('/api/console/v1/auth/login',['account'=>$other->mobile,'password'=>'Fixture@123456','remember_me'=>false]);check($status===200,'另一会员登录失败');
 [$status]=callApi('/api/console/v1/services/'.$service->id);check($status===404,'他人服务详情应拒绝');
 [$status]=callApi('/api/console/v1/services/'.$service->id.'/initial-password',[]);check($status===404,'他人初始密码应拒绝');
 echo "真实 Webman/MySQL HTTP：匿名拒绝、本人详情、跨会员隔离、同源保护、敏感响应与后台边界通过\n";
}finally{
 curl_close($curl);if($service)$service->delete();foreach([$member,$other]as$m)if($m){MemberSessionModel::query()->where('member_id',$m->id)->delete();$m->delete();}
}
