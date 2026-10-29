<?php
declare(strict_types=1);

/** Read only the assigned person's signature; an optional PDO supplies a source in tests. */
function employee_saved_signature(int $employeeId, string $email, ?PDO $source = null, bool $requireApprover = true): ?string
{
    $s = db()->prepare('SELECT source_user_id,source_company_id FROM employees WHERE id=? AND is_active=1'.($requireApprover?' AND can_approve=1':'').' AND email=?');
    $s->execute([$employeeId,$email]);
    $employee = $s->fetch();
    if (!$employee || !$employee['source_user_id'] || !$employee['source_company_id']) return null;
    try {
        if ($source === null) {
            $c = cfg('employee_source');
            if (!$c) return null;
            $source = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'],$c['port'],$c['name']),$c['user'],$c['pass'],[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3,
            ]);
        }
        $s = $source->prepare('SELECT u.signature_data FROM purchase_users u
            JOIN purchase_user_companies uc ON uc.user_id=u.id JOIN purchase_companies c ON c.id=uc.company_id
            WHERE u.id=? AND uc.company_id=? AND u.email=? AND u.is_active=1 AND u.deleted_at IS NULL AND c.is_active=1 AND c.deleted_at IS NULL');
        $s->execute([$employee['source_user_id'],$employee['source_company_id'],$email]);
        $data = $s->fetchColumn();
        if (!$data || !is_string($data)) return null;
        $in = new Input(['signature_data'=>$data]);
        $signature = validate_signature($in);
        $in->check();
        return $signature;
    } catch (Throwable $e) {
        // Drawing a new signature remains available when the source or image is unavailable.
        return null;
    }
}
