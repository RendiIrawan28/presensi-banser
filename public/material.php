<?php
/** Authenticated streaming of uploaded V7 materials, outside public storage. */
declare(strict_types=1);
ini_set('display_errors','0');
session_name('ABSENSI_SESSION');session_start();
if(empty($_SESSION['auth']) || time()-(int)($_SESSION['seen']??0)>14400){http_response_code(401);exit('Login diperlukan.');}
if(!extension_loaded('pdo_sqlite')){http_response_code(503);exit('Storage belum siap.');}
$id=(string)($_GET['id']??'');if(!preg_match('/^mat_[a-f0-9]{20}$/D',$id)){http_response_code(400);exit('ID tidak valid.');}
$db=new PDO('sqlite:'.dirname(__DIR__).'/data/absensi.sqlite');$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$s=$db->prepare('SELECT event_id,kind,path,mime,title FROM v7_materials WHERE id=?');$s->execute([$id]);$r=$s->fetch();
if(!$r||$r['kind']!=='file'){http_response_code(404);exit('Materi tidak ditemukan.');}
if(($_SESSION['role']??'admin')!=='admin'){
  $s=$db->prepare('SELECT 1 FROM v7_user_events WHERE user_id=? AND event_id=?');$s->execute([$_SESSION['user_id']??'',$r['event_id']]);
  if(!$s->fetchColumn()){http_response_code(403);exit('Akses ditolak.');}
}
$basename=(string)$r['path'];if(!preg_match('/^file_[a-f0-9]{20}\.(pdf|png|jpg|docx|xlsx)$/D',$basename)){http_response_code(404);exit('File tidak valid.');}
$path=dirname(__DIR__).'/storage/materials/'.$basename;
if(!is_file($path)){http_response_code(404);exit('Berkas tidak tersedia.');}
header('X-Content-Type-Options: nosniff');header('Cache-Control: private,no-store');header('Content-Security-Policy: sandbox');
header('Content-Type: '.(string)$r['mime']);header('Content-Disposition: attachment; filename="materi-'.$basename.'"');
header('Content-Length: '.filesize($path));readfile($path);
