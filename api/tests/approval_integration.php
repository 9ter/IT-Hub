<?php
declare(strict_types=1);

// Runs against connection-local TEMPORARY copies: no production rows are changed.
// SMTP is replaced with a loopback sink; no real email is sent.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/assets.php';
require __DIR__ . '/../src/admin.php';
$pdo = db();
foreach (['employees','tickets','ticket_logs','ticket_approvals','ticket_requester_signatures','ticket_it_signatures','ticket_receipt_signatures','ticket_borrow_decisions','ticket_archives','it_documents','maintenance_records','admins','assets'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `fixture_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `fixture_$table` RENAME TO `$table`");
}
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $fn, int $status): void {
    try { $fn(); } catch (HttpError $e) { verify($e->status === $status, 'Unexpected HTTP status: ' . $e->status); return; }
    throw new RuntimeException('Expected rejection ' . $status);
}
function signature(bool $blank = false, bool $variant = false): string {
    $image = imagecreatetruecolor(600,200);
    imagefilledrectangle($image,0,0,599,199,imagecolorallocate($image,255,255,255));
    if (!$blank) {
        $color = imagecolorallocate($image,15,23,42);
        imagesetthickness($image,3);
        imageline($image,60,110,160,40,$color);
        imageline($image,160,40,240,140,$color);
        imageline($image,240,140,400,70,$color);
        if ($variant) imageline($image,400,70,500,120,$color);
    }
    ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
    return 'data:image/png;base64,'.base64_encode($bytes);
}
$capture = tempnam(sys_get_temp_dir(),'ithub-smtp-test-');
$process = proc_open(['python3',__DIR__.'/smtp_fixture.py',$capture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start local SMTP fixture');
try {
    $port = (int)fgets($pipes[1]);
    verify($port > 0, 'SMTP fixture port');
    $GLOBALS['ithub_config']['smtp'] = ['enabled'=>true,'host'=>'127.0.0.1','port'=>$port,'encryption'=>'','username'=>'fixture','password'=>'fixture','from_email'=>'it-hub@example.com'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.2';
    $person = ['full_name'=>'Test Approver','email'=>'approver@example.com','company'=>'Test Company','department'=>'Test Department','can_approve'=>true];
    employee_save(null,$person);
    $employeeId = (int)$pdo->query('SELECT MAX(id) FROM employees')->fetchColumn();
    $people = employee_approvers();
    verify(count($people) === 1 && !isset($people[0]['email']) && !isset($people[0]['phone']), 'Public approver list only exposes dropdown identity');
    rejects(fn()=>employee_save(null,[...$person,'email'=>'invalid']),422);
    rejects(fn()=>employee_save(null,[...$person,'email'=>'']),422);
    rejects(fn()=>employee_save(9999999,$person),404);
    $pdo->exec("INSERT INTO assets (asset_code,brand_model,location) VALUES ('FIXTURE-DEVICE','Fixture Model','Fixture Floor')");
    $assetId = (int)$pdo->query('SELECT MAX(id) FROM assets')->fetchColumn();
    $body = ['type'=>'service','requester_name'=>'Test Requester','requester_dept'=>'Test Department','description'=>'Integration fixture: request software installation','device_kind'=>'computer','actions'=>['install'],'approver_id'=>$employeeId,
        'asset_code'=>'NONEXISTENT-QR-CONTEXT','location'=>'QR location to ignore','requester_signature_data'=>signature()];
    rejects(fn()=>create_ticket([...$body,'approver_id'=>''],null),422);
    rejects(fn()=>create_ticket([...$body,'requester_signature_data'=>''],null),422);
    rejects(fn()=>create_ticket([...$body,'requester_signature_data'=>signature(true)],null),422);
    rejects(fn()=>create_ticket([...$body,'requester_signature_data'=>'data:image/svg+xml;base64,PHN2Zy8+'],null),422);
    verify((int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM ticket_requester_signatures')->fetchColumn() === 0, 'Invalid requester signatures create no records');
    employee_save($employeeId,[...$person,'is_active'=>false]);
    rejects(fn()=>create_ticket($body,null),422);
    employee_save($employeeId,$person);
    $result = create_ticket($body,null);
    verify($result['mail_status'] === 'sent', 'Request delivers to local SMTP');
    $id = (int)$pdo->query('SELECT id FROM tickets')->fetchColumn();
    $requesterSignature = requester_signature_for_ticket($id);
    verify($requesterSignature['requester_name'] === $body['requester_name'] && $requesterSignature['signature_data'] === $body['requester_signature_data'] && $requesterSignature['signed_at'] !== null, 'Requester signature, name and server timestamp persisted');
    verify(!isset($requesterSignature['signed_ip']), 'Requester signature payload excludes IP');
    verify(ticket_admin($id)['requester_signature'] === $requesterSignature, 'Admin detail and print receive requester signature');
    verify(!array_key_exists('requester_signature',ticket_public($result['ticket_no'])) && !array_key_exists('requester_signature_data',ticket_public($result['ticket_no'])), 'Public tracker never exposes requester signature');
    verify(!array_key_exists('requester_signature',ticket_list([])['data'][0]), 'Ticket list does not include signature data');
    $stored = $pdo->query('SELECT asset_id,location FROM tickets')->fetch();
    verify($stored['asset_id'] === null && $stored['location'] === null, 'Non-repair submission discards QR context even with unknown asset code');
    // Simulate a request saved before repair-only linking, without touching its approval record.
    $pdo->prepare('UPDATE tickets SET asset_id=?,location=? WHERE id=?')->execute([$assetId,'Fixture Floor',$id]);
    $legacy = ticket_admin($id);
    verify($legacy['asset_id'] === null && $legacy['asset_code'] === null && $legacy['asset_model'] === null && $legacy['location'] === null, 'Legacy non-repair detail/print has no equipment context');
    verify(ticket_public($result['ticket_no'])['asset_code'] === null, 'Legacy non-repair tracking has no asset');
    verify(ticket_list([])['data'][0]['asset_code'] === null && dashboard()['queue'][0]['asset_code'] === null, 'Ticket list and dashboard omit legacy equipment context');
    verify(asset_public('FIXTURE-DEVICE')['open_tickets'] === [] && asset_detail($assetId)['tickets'] === [], 'Asset history excludes non-repair requests');
    verify((int)asset_list(['all'=>true])['data'][0]['open_tickets'] === 0, 'Non-repair requests do not inflate equipment workload');
    $mail = json_decode(file_get_contents($capture),true);
    $token = $mail['token'];
    verify(is_string($token) && strlen($token) === 64, 'SMTP contains approval link');
    verify(str_contains($mail['to'],'approver@example.com') && str_contains($mail['from'],'it-hub@example.com'), 'Recipient and sender');
    verify($pdo->query('SELECT token_hash FROM ticket_approvals')->fetchColumn() === hash('sha256',$token), 'Only hashed token stored');
    $view = approval_view(['token'=>$token]);
    verify($view['saved_signature_data'] === null && $view['saved_signature_hash'] === null, 'Manual employee has no saved source signature');
    verify($view['asset_code'] === null && $view['asset_model'] === null, 'Legacy approval view omits equipment context');
    verify($view['requester_name'] === $body['requester_name'] && !isset($view['token_hash']) && !isset($view['signed_ip']) && !isset($view['approver_email']), 'Approval view fields');
    verify(ticket_public($result['ticket_no'])['approval_status'] === 'pending', 'Public pending status');
    verify(!isset(ticket_public($result['ticket_no'])['approval']), 'Public tracker has no signature or recipient email');
    rejects(fn()=>approval_view(['token'=>'invalid']),404);
    rejects(fn()=>update_ticket($id,['status'=>'in_progress'],['id'=>1,'display_name'=>'Test Admin']),422);
    $decision = ['token'=>$token,'decision'=>'approved','consent'=>true,'signature_data'=>signature()];
    rejects(fn()=>approval_decide([...$decision,'consent'=>false]),422);
    rejects(fn()=>approval_decide([...$decision,'signature_data'=>signature(true)]),422);
    rejects(fn()=>approval_decide([...$decision,'signature_data'=>'data:image/svg+xml;base64,PHN2Zy8+']),422);
    rejects(fn()=>approval_decide([...$decision,'decision'=>'rejected']),422);
    $pdo->exec('UPDATE ticket_approvals SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)');
    rejects(fn()=>approval_decide($decision),410);
    approval_send($id,true);
    $newToken = json_decode(file_get_contents($capture),true)['token'];
    verify($newToken !== $token, 'Resend rotates link');
    rejects(fn()=>approval_view(['token'=>$token]),404);
    rejects(fn()=>approval_send($id,true),429);
    employee_save($employeeId,[...$person,'is_active'=>false]);
    rejects(fn()=>approval_view(['token'=>$newToken]),409);
    employee_save($employeeId,$person);
    // A source fixture on this connection tests saved signatures without modifying E-Procurement.
    $pdo->exec('CREATE TEMPORARY TABLE purchase_users (id BIGINT PRIMARY KEY,email VARCHAR(150),signature_data MEDIUMTEXT,is_active TINYINT,deleted_at DATETIME NULL)');
    $pdo->exec('CREATE TEMPORARY TABLE purchase_user_companies (user_id BIGINT,company_id BIGINT)');
    $pdo->exec('CREATE TEMPORARY TABLE purchase_companies (id BIGINT PRIMARY KEY,is_active TINYINT,deleted_at DATETIME NULL)');
    $pdo->exec('INSERT INTO purchase_companies VALUES (501,1,NULL)');
    $pdo->exec('INSERT INTO purchase_user_companies VALUES (1024,501)');
    $pdo->prepare('INSERT INTO purchase_users VALUES (1024,?,?,1,NULL)')->execute([$person['email'],$decision['signature_data']]);
    $pdo->prepare('UPDATE employees SET source_user_id=1024,source_company_id=501 WHERE id=?')->execute([$employeeId]);
    $savedView = approval_view(['token'=>$newToken],$pdo);
    verify($savedView['saved_signature_data'] === $decision['signature_data'] && $savedView['saved_signature_hash'] === hash('sha256',$decision['signature_data']), 'Token holder sees assigned source signature and its hash');
    verify(approval_for_ticket($id)['status'] === 'pending' && approval_for_ticket($id)['signature_data'] === null, 'Viewing saved signature does not sign or approve');
    verify(!isset(employee_approvers()[0]['signature_data']), 'Public dropdown has no source signature');
    $savedDecision = ['token'=>$newToken,'decision'=>'approved','consent'=>true,'use_saved_signature'=>true,'saved_signature_hash'=>$savedView['saved_signature_hash']];
    rejects(fn()=>approval_decide([...$savedDecision,'consent'=>false],$pdo),422);
    rejects(fn()=>approval_decide([...$savedDecision,'saved_signature_hash'=>''],$pdo),422);
    rejects(fn()=>approval_decide([...$savedDecision,'saved_signature_hash'=>str_repeat('0',64)],$pdo),409);
    $pdo->exec("UPDATE purchase_users SET email='other@example.com'");
    verify(employee_saved_signature($employeeId,$person['email'],$pdo) === null, 'Source email must match assigned email');
    rejects(fn()=>approval_decide($savedDecision,$pdo),409);
    $pdo->prepare('UPDATE purchase_users SET email=?')->execute([$person['email']]);
    $pdo->exec('UPDATE purchase_companies SET is_active=0');
    verify(employee_saved_signature($employeeId,$person['email'],$pdo) === null, 'Inactive source company cannot supply signature');
    $pdo->exec('UPDATE purchase_companies SET is_active=1');
    $pdo->exec('UPDATE purchase_user_companies SET company_id=502');
    verify(employee_saved_signature($employeeId,$person['email'],$pdo) === null, 'Source company membership must match');
    $pdo->exec('UPDATE purchase_user_companies SET company_id=501');
    $pdo->exec('UPDATE purchase_users SET is_active=0');
    verify(employee_saved_signature($employeeId,$person['email'],$pdo) === null, 'Inactive source user cannot supply signature');
    $pdo->exec('UPDATE purchase_users SET is_active=1,deleted_at=NOW()');
    verify(employee_saved_signature($employeeId,$person['email'],$pdo) === null, 'Deleted source user cannot supply signature');
    $pdo->prepare('UPDATE purchase_users SET deleted_at=NULL,signature_data=?')->execute([signature(true)]);
    verify(employee_saved_signature($employeeId,$person['email'],$pdo) === null, 'Blank source image is rejected');
    $pdo->prepare('UPDATE purchase_users SET signature_data=?')->execute([signature(false,true)]);
    rejects(fn()=>approval_decide($savedDecision,$pdo),409);
    $pdo->prepare('UPDATE purchase_users SET signature_data=?')->execute([$decision['signature_data']]);
    approval_decide($savedDecision,$pdo);
    verify(count(array_filter(ticket_logs($id,false),fn($log) => str_contains($log['note'] ?? '', 'E-Procurement') && !$log['is_public'])) === 1, 'Saved signature origin recorded in internal audit log');
    $pdo->prepare('UPDATE purchase_users SET signature_data=?')->execute([signature(false,true)]);
    verify(approval_for_ticket($id)['signature_data'] === $decision['signature_data'], 'Approved signature snapshot stays unchanged after source update');
    verify(approval_for_ticket($id)['status'] === 'approved', 'Signature persisted');
    verify(approval_for_ticket($id)['signature_data'] === $decision['signature_data'], 'Signature preserved');
    rejects(fn()=>approval_decide([...$decision,'token'=>$newToken]),409);
    rejects(fn()=>approval_send($id,true),409);
    update_ticket($id,['status'=>'in_progress'],['id'=>1,'display_name'=>'Test Admin']);
    verify(ticket_public($result['ticket_no'])['status'] === 'in_progress', 'Approved request can start');
    employee_save($employeeId,[...$person,'full_name'=>'Updated Directory Name']);
    verify(approval_for_ticket($id)['approver_name'] === 'Test Approver', 'Signed identity is immutable snapshot');
    // Rejection, cancellation, reassign and failed-mail paths.
    $result = create_ticket($body,null);
    $id2 = (int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
    $token2 = json_decode(file_get_contents($capture),true)['token'];
    approval_decide([...$decision,'token'=>$token2,'decision'=>'rejected','decision_note'=>'Fixture rejection']);
    verify(ticket_public($result['ticket_no'])['status'] === 'rejected', 'Rejected request closes');
    rejects(fn()=>update_ticket($id2,['status'=>'done'],['id'=>1,'display_name'=>'Test Admin']),422);
    $result = create_ticket($body,null);
    $id3 = (int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
    $token3 = json_decode(file_get_contents($capture),true)['token'];
    update_ticket($id3,['status'=>'cancelled'],['id'=>1,'display_name'=>'Test Admin']);
    rejects(fn()=>approval_decide([...$decision,'token'=>$token3]),409);
    $GLOBALS['ithub_config']['smtp']['enabled'] = false;
    $result = create_ticket($body,null);
    $id4 = (int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
    verify($result['mail_status'] === 'failed' && approval_for_ticket($id4)['mail_status'] === 'failed', 'Mail failure preserves saved request');
    verify(approval_for_ticket($id4)['mail_error'] !== null, 'Safe error recorded');
    employee_save(null,[...$person,'full_name'=>'Alternate Approver','email'=>'alternate@example.com']);
    $alternate = (int)$pdo->query('SELECT MAX(id) FROM employees')->fetchColumn();
    $pdo->prepare('UPDATE ticket_approvals SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE ticket_id=?')->execute([$id4]);
    $GLOBALS['ithub_config']['smtp']['enabled'] = true;
    approval_send($id4,true,$alternate);
    verify(approval_for_ticket($id4)['approver_email'] === 'alternate@example.com', 'Pending request can change recipient');
    $token4 = json_decode(file_get_contents($capture),true)['token'];
    approval_decide([...$decision,'token'=>$token4]);
    verify(approval_for_ticket($id4)['approver_name'] === 'Alternate Approver', 'New approver snapshot signed');
    $repair = create_ticket(['type'=>'repair','requester_name'=>'Test Requester','requester_dept'=>'Test Department','description'=>'Fixture repair','equipment'=>'Test computer'],null);
    verify(!isset($repair['mail_status']), 'Other form types keep original workflow');
    verify(ticket_admin((int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn())['requester_signature'] === null, 'Unsigned legacy/other requests return null signature');
    $forms = [
        ['type'=>'email','name_th'=>'Test Employee','name_en'=>'Test Employee','position'=>'Test Position','company'=>'ats'],
        ['type'=>'borrow','device_kind'=>'notebook','item'=>'Test Notebook','quantity'=>1,'start_date'=>date('Y-m-d')],
        ['type'=>'purchase','items'=>['notebook']],
    ];
    foreach ($forms as $form) {
        $other = create_ticket([...$body,...$form],null);
        $s = $pdo->prepare('SELECT asset_id,location FROM tickets WHERE ticket_no=?');
        $s->execute([$other['ticket_no']]);
        $row = $s->fetch();
        verify($row['asset_id'] === null && $row['location'] === null, 'No QR context for '.$form['type']);
    }
    $repair = create_ticket([...$body,'type'=>'repair','asset_code'=>'FIXTURE-DEVICE','location'=>''],null);
    $repairId = (int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
    $linked = ticket_admin($repairId);
    verify((int)$linked['asset_id'] === $assetId && $linked['asset_code'] === 'FIXTURE-DEVICE' && $linked['location'] === 'Fixture Floor', 'Repair retains equipment and inherited location');
    verify(count(asset_public('FIXTURE-DEVICE')['open_tickets']) === 1 && count(asset_detail($assetId)['tickets']) === 1, 'Repair appears in equipment history');
    echo "PASS: $checks integration checks; temporary tables only; loopback SMTP only.\n";
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);
    unlink($capture);
}
