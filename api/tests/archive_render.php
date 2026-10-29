<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$out=$argv[1]??throw new RuntimeException('Pass a private output directory');
$pdo=db();foreach(['tickets','ticket_logs','admins','employees','assets','maintenance_records','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','it_documents','it_document_signatures','it_document_logs'] as $table){$pdo->exec("CREATE TEMPORARY TABLE f_$table LIKE $table");$pdo->exec("ALTER TABLE f_$table RENAME TO $table");}
$pdo->exec("INSERT INTO admins(id,username,password_hash,display_name) VALUES(1,'fixture','fixture','Fixture IT')");$actor=['id'=>1,'display_name'=>'Fixture IT'];
$details=[
 'repair'=>['job_type'=>'repair','equipment'=>'FIXTURE-COMPUTER','found_at'=>'2026-10-28 09:00:00'],
 'service'=>['position'=>'Fixture','device_kind'=>'computer','actions'=>['install'],'os_target'=>'Fixture OS','other_text'=>''],
 'purchase'=>['position'=>'Fixture','urgent'=>false,'need_by'=>'2026-11-01','items'=>['notebook'],'spec_cpu'=>'Fixture CPU','spec_ram'=>'32 GB','spec_storage'=>'1 TB','spec_display'=>'27','spec_other'=>'Fixture'],
 'email'=>['name_th'=>'Fixture requester','name_en'=>'Fixture requester','position'=>'Fixture','company'=>'ats','desired_email'=>'fixture@example.com'],
 'borrow'=>['device_kind'=>'mixed','start_date'=>'2026-10-28','end_date'=>'2026-11-01','borrow_items'=>[['device_kind'=>'notebook','item'=>'BORROW01','quantity'=>1],['device_kind'=>'other','item'=>'BORROW02','quantity'=>2]]],
];
foreach($details as $type=>$d){
 $pdo->prepare('INSERT INTO tickets(ticket_no,type,title,description,details,requester_name,requester_dept,client_ip) VALUES(?,?,?,?,?,?,?,?)')
     ->execute(['FIXTURE-'.strtoupper($type),$type,'Fixture '.$type,'Fixture purpose '.$type,json_encode($d),'Fixture requester','Fixture department','127.0.0.50']);$id=(int)$pdo->lastInsertId();
 if($type==='borrow')borrow_decide($id,['decision'=>'approved'],$actor);
 update_ticket($id,['status'=>'done','resolution'=>'ARCHIVE-END-'.strtoupper($type)],$actor);
 $payload=archive_snapshot($id);file_put_contents($out.'/'.$type.'.json',json_encode($payload,JSON_UNESCAPED_UNICODE));
 archive_process(['/usr/bin/node',__DIR__.'/../bin/archive-render.cjs'],['payload'=>$payload,'output'=>$out.'/'.$type.'.pdf','runtime'=>archive_dir().'/node_modules/playwright','chromium'=>archive_dir().'/chromium/chrome','dist'=>dirname(__DIR__,2).'/web/dist'],90);
 echo 'Rendered '.$type.PHP_EOL;
}
echo "PASS: offline renderer for all five ticket types; temporary DB tables, no NAS upload\n";
