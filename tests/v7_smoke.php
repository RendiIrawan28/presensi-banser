<?php
/** Standalone regression suite: php tests/v7_smoke.php (requires pdo_sqlite). */
declare(strict_types=1);
if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"SKIP: pdo_sqlite tidak tersedia (jalankan di VPS dengan php8.3-sqlite3).\n");exit(77);}
require dirname(__DIR__).'/src/v7.php';
function failure(string $message,int $code=400): never {throw new DomainException($message,$code);}
function failureWithRollback(PDO $db,string $m,int $c): never {if($db->inTransaction())$db->exec('ROLLBACK');failure($m,$c);}
function validId(mixed $id):bool{return is_string($id)&&preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id)===1;}
function validateState(mixed $s):void{if(!is_array($s)||!isset($s['people'],$s['sessions'],$s['logs']))failure('Bad state');}
$checks=0;function assertTrue(bool $cond,string $what):void{global $checks;if(!$cond)throw new RuntimeException('FAIL: '.$what);echo "PASS: $what\n";$checks++;}
$_SESSION=['auth'=>true,'user'=>'test-admin','role'=>'admin','user_id'=>'legacy-admin'];
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE app_state(id INTEGER PRIMARY KEY,revision INTEGER,state_json TEXT)');
$db->exec('CREATE TABLE attendance(sid TEXT,pid TEXT,t INTEGER,m TEXT,PRIMARY KEY(sid,pid))');
$legacy=['ev'=>'PKD lama','active'=>'s1','people'=>[['id'=>'PKD0126NGK','name'=>'Satu','unit'=>'PAC A']], 'sessions'=>[['id'=>'s1','name'=>'Hari 1 – Materi','start'=>'08:00','end'=>'09:00']], 'cert'=>['thr'=>75], 'approved'=>['PKD0126NGK'=>['t'=>1]],'grades'=>['PKD0126NGK'=>['Materi'=>82]]];
$db->prepare('INSERT INTO app_state VALUES(1,5,?)')->execute([json_encode($legacy)]);
$db->exec("INSERT INTO attendance VALUES('s1','PKD0126NGK',1770000000000,'scan')");
$db->beginTransaction();v7Migrate($db);$db->commit();
assertTrue(v7Ready($db),'Skema V7 terpasang');
assertTrue(count(v7State($db)['people'])===1 && count(v7State($db)['logs'])===1,'Data peserta dan presensi legacy utuh');
assertTrue(isset(v7State($db)['approved']->PKD0126NGK),'Persetujuan sertifikat legacy dipertahankan');
$db->beginTransaction();v7Migrate($db);$db->commit();
assertTrue((int)$db->query('SELECT COUNT(*) FROM v7_events')->fetchColumn()===1,'Migrasi ulang idempotent');
$r=v7Scan($db,['sid'=>'s1','pid'=>'PKD0126NGK','m'=>'scan']);assertTrue($r['st']==='dup','Scan ganda ditolak di server');
$r=v7Action($db,'v7.scores.save',['sid'=>'s1','pid'=>'PKD0126NGK','score'=>91]);
assertTrue($r['ok'] && (float)v7State($db)['grades']->PKD0126NGK['Materi']===91.0,'Nilai sesi menjadi nilai sertifikat');
$before=v7State($db);$newEvent=v7Action($db,'v7.events.create',['name'=>'PKD Lain','prefix'=>'PKD','suffix'=>'NGK']);
$_SESSION['event_id']=$newEvent['id'];
assertTrue(count(v7State($db)['logs'])===0 && count(v7State($db)['people'])===0,'Kegiatan baru terisolasi');
$st=v7Action($db,'v7.participants.add',['name'=>'Peserta Dua','unit'=>'PAC B']);
assertTrue(str_starts_with($st['id'],'PKD') && count(v7State($db)['people'])===1,'ID peserta PKD dibuat di server');
$_SESSION['event_id']='legacy_1';
assertTrue(count(v7State($db)['people'])===1,'Kegiatan legacy tetap utuh');
$del=v7Action($db,'v7.attendance.delete',['scope'=>'one','sid'=>'s1','pid'=>'PKD0126NGK','reason'=>'Koreksi scan ganda']);
assertTrue($del['count']===1 && count(v7State($db)['logs'])===0,'Pembatalan absensi tercatat per event');
assertTrue((int)$db->query("SELECT COUNT(*) FROM v7_audit WHERE action='attendance.delete.one'")->fetchColumn()===1,'Audit log penonaktifan tercatat');
$conflict=v7Commit($db,['state'=>$before,'revision'=>$before['revision']]);
assertTrue(!empty($conflict['conflict']),'Stale commit mendapat conflict tanpa menulis database');
assertTrue(count(v7State($db)['logs'])===0,'Snapshot lama tidak menghidupkan kembali presensi yang dihapus');
$_SESSION['role']='scanner';
try{v7Action($db,'v7.attendance.delete',['scope'=>'event','reason'=>'Tidak diperbolehkan','confirm'=>'HAPUS']);throw new RuntimeException('Forbidden action unexpectedly succeeded');}
catch(DomainException $e){assertTrue($e->getCode()===403,'Role scanner tidak boleh menghapus presensi');}
assertTrue($db->query('PRAGMA integrity_check')->fetchColumn()==='ok','Integritas database OK');
echo "TOTAL PASS: $checks\n";
