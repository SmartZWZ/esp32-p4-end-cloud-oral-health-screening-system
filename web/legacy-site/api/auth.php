<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$action=(string)($_GET['action']??'');
if($action==='me'){ $u=require_user();json_response(['ok'=>true,'user'=>['public_id'=>$u['public_id'],'email'=>$u['email'],'nickname'=>$u['nickname'],'is_admin'=>is_admin_user($u)],'csrf'=>csrf()]); }
if($action==='register'){
  $d=request_data();$email=strtolower(trim((string)($d['email']??'')));$nickname=trim((string)($d['nickname']??''));$password=(string)($d['password']??'');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email)>191)json_response(['ok'=>false,'error'=>'请输入有效邮箱。'],422);
  if(mb_strlen($nickname)<2||mb_strlen($nickname)>64)json_response(['ok'=>false,'error'=>'昵称需要 2 到 64 个字符。'],422);
  if(strlen($password)<8)json_response(['ok'=>false,'error'=>'密码至少需要 8 个字符。'],422);
  $pdo=db();try{$pdo->beginTransaction();$s=$pdo->prepare('INSERT INTO users(public_id,email,password_hash,nickname) VALUES(?,?,?,?)');$s->execute([public_id(),$email,password_hash($password,PASSWORD_DEFAULT),$nickname]);$userId=(int)$pdo->lastInsertId();$s=$pdo->prepare("INSERT INTO family_members(public_id,user_id,name,relationship,is_default) VALUES(?,?,?,?,1)");$s->execute([public_id(),$userId,$nickname,'本人']);$pdo->commit();start_session();session_regenerate_id(true);$_SESSION['uid']=$userId;$_SESSION['csrf']=bin2hex(random_bytes(24));json_response(['ok'=>true],201);}catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();if($e->getCode()==='23000')json_response(['ok'=>false,'error'=>'该邮箱已注册，请直接登录。'],409);throw $e;}
}
if($action==='login'){
  $d=request_data();$s=db()->prepare('SELECT * FROM users WHERE email=? AND status=1 LIMIT 1');$s->execute([strtolower(trim((string)($d['email']??'')))]);$u=$s->fetch();if(!$u||!password_verify((string)($d['password']??''),$u['password_hash']))json_response(['ok'=>false,'error'=>'邮箱或密码不正确。'],401);start_session();session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['csrf']=bin2hex(random_bytes(24));json_response(['ok'=>true]);
}
if($action==='logout'){require_user();require_csrf();$_SESSION=[];session_destroy();json_response(['ok'=>true]);}
json_response(['ok'=>false,'error'=>'接口不存在。'],404);
