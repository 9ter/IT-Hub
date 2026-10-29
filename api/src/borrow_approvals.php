<?php
declare(strict_types=1);

function borrow_approval_for_ticket(array $ticket): array
{
    $s=db()->prepare('SELECT b.decision AS status,b.admin_id,b.admin_name,b.decision_note,b.decided_at,b.document_id,d.document_no,d.status AS document_status
        FROM ticket_borrow_decisions b LEFT JOIN it_documents d ON d.id=b.document_id WHERE b.ticket_id=?');
    $s->execute([$ticket['id']]);
    return $s->fetch() ?: ['status'=>$ticket['status']==='rejected'?'rejected':'pending','admin_id'=>null,'admin_name'=>null,'decision_note'=>null,'decided_at'=>null,'document_id'=>null,'document_no'=>null,'document_status'=>null];
}

function borrow_pending_requests(array $q): array
{
    return paginate("SELECT t.id,t.ticket_no,t.title,t.requester_name,t.requester_dept,t.created_at FROM tickets t
        LEFT JOIN ticket_borrow_decisions b ON b.ticket_id=t.id
        WHERE t.type='borrow' AND b.ticket_id IS NULL AND t.status NOT IN ('done','rejected','cancelled') ORDER BY t.id",
        [],(int)($q['page']??1),12);
}

function borrow_record_validate_update(array $old,array $data): void
{
    $s=db()->prepare("SELECT ticket_id FROM ticket_borrow_decisions WHERE document_id=? AND decision='approved'");$s->execute([$old['id']]);
    if (!$s->fetchColumn()) fail('บันทึกนี้ยังไม่มีการอนุมัติจาก Admin IT Hub กรุณาตรวจคำขอต้นทาง',409);
    if (count($data['rows'])!==count($old['data']['rows'])) fail('เพิ่มหรือลบรายการที่อนุมัติแล้วไม่ได้ การเบิก–ยืมใหม่ต้องส่งคำขอใหม่',409);
    foreach(['device_kind','start_date','end_date'] as $key) if ($data['fields'][$key]!==($old['data']['fields'][$key]??null)) fail('ข้อมูลคำขอผ่านการอนุมัติแล้ว กรุณาส่งคำขอใหม่หากต้องการเปลี่ยนอุปกรณ์หรือช่วงเวลา',409);
    foreach($data['rows'] as $i=>$row) foreach(['department','user','purpose','item','quantity','device_kind'] as $key) if ($row[$key]!==($old['data']['rows'][$i][$key]??null)) fail('ข้อมูลผู้ยืมและอุปกรณ์ผ่านการอนุมัติแล้ว กรุณาส่งคำขอใหม่หากต้องการเปลี่ยน',409);
}

/** Called only inside the decision transaction while the source ticket is locked. */
function borrow_record_create(array $ticket,array $actor): int
{
    $def=document_forms()['FM-IT-008'];$v=json_decode($ticket['details'],true,512,JSON_THROW_ON_ERROR);
    $rows=array_map(fn($item)=>['department'=>$ticket['requester_dept'],'user'=>$ticket['requester_name'],'purpose'=>$ticket['description'],
        'device_kind'=>$item['device_kind'],'item'=>$item['item'],'quantity'=>(string)$item['quantity'],'date'=>$v['start_date'],'issuer'=>$actor['display_name']],borrow_items_for_details($v));
    $data=document_data($def,['fields'=>['device_kind'=>$v['device_kind'],'start_date'=>$v['start_date'],'end_date'=>$v['end_date']??null],
        'rows'=>$rows],'request');
    $no='IT-008-'.thai_year_month().'-'.strtoupper(bin2hex(random_bytes(4)));
    db()->prepare("INSERT INTO it_documents (document_no,form_code,title,document_date,company,status,data,created_by,source_note,source_key)
        VALUES (?,'FM-IT-008',?,CURRENT_DATE(),'บริษัท อัตถสาร จำกัด','in_progress',?,?,?,?)")
        ->execute([$no,$def['title'],json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor['id'],
            'อนุมัติจากคำขอ '.$ticket['ticket_no'],'borrow-ticket:'.$ticket['id']]);
    $id=(int)db()->lastInsertId();
    document_log($id,'Admin IT Hub อนุมัติคำขอ '.$ticket['ticket_no'].' และสร้างบันทึกเบิก–ยืม',(int)$actor['id']);
    return $id;
}

function borrow_decide(int $id,array $body,array $actor): array
{
    $in=new Input($body);$decision=$in->enum('decision',['approved','rejected'],true,'ผลการอนุมัติ');
    $note=$in->str('decision_note',2000,$decision==='rejected','เหตุผลที่ไม่อนุมัติ');$in->check();
    $pdo=db();$pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM tickets WHERE id=? FOR UPDATE');$s->execute([$id]);
        $t=$s->fetch() ?: fail('ไม่พบคำขอ',404);
        if ($t['type']!=='borrow') fail('ขั้นตอนนี้ใช้เฉพาะคำขอเบิก–ยืมอุปกรณ์',422);
        $s=$pdo->prepare('SELECT * FROM ticket_borrow_decisions WHERE ticket_id=?');$s->execute([$id]);$old=$s->fetch();
        if ($old) {
            if ($old['decision']!==$decision) fail('คำขอนี้พิจารณาแล้ว ไม่สามารถเปลี่ยนผลการอนุมัติ',409);
            // A retried click returns the original decision and never duplicates the register.
            $pdo->commit();return ticket_admin($id);
        }
        if (in_array($t['status'],TICKET_CLOSED_STATUSES,true)) fail('คำขอนี้ปิดหรือยกเลิกแล้ว ไม่สามารถอนุมัติ',409);
        $documentId=$decision==='approved'?borrow_record_create($t,$actor):null;
        $pdo->prepare('INSERT INTO ticket_borrow_decisions (ticket_id,decision,admin_id,admin_name,decision_note,document_id) VALUES (?,?,?,?,?,?)')
            ->execute([$id,$decision,$actor['id'],$actor['display_name'],$note,$documentId]);
        $status=$decision==='approved'?'accepted':'rejected';
        $pdo->prepare('UPDATE tickets SET status=?,assigned_to=COALESCE(assigned_to,?),finished_at=? WHERE id=?')
            ->execute([$status,$actor['id'],$decision==='rejected'?now():null,$id]);
        $message=$decision==='approved'?'Admin IT Hub อนุมัติการเบิก–ยืมแล้ว และเพิ่มเข้าบันทึก FM-IT-008':'Admin IT Hub ไม่อนุมัติการเบิก–ยืม';
        $pdo->prepare('INSERT INTO ticket_logs (ticket_id,admin_id,from_status,to_status,note,is_public) VALUES (?,?,?,?,?,1)')
            ->execute([$id,$actor['id'],$t['status'],$status,$message.($note?"\n".$note:'')]);
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ticket_admin($id);
}
