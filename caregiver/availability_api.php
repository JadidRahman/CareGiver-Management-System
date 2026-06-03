<?php
// caregiver/availability_api.php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

function out($ok, $data = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(['ok' => $ok] + $data);
    exit;
}
function need($k)
{
    return isset($_POST[$k]) ? $_POST[$k] : (isset($_GET[$k]) ? $_GET[$k] : null);
}
function i($v)
{
    return (int) $v;
}
function safe_time($t)
{
    return $t ? substr($t, 0, 8) : null;
}
function is_caregiver(&$con)
{
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'caregiver')
        return null;
    $uid = (int) $_SESSION['user_id'];
    $stmt = $con->prepare("SELECT id FROM caregivers WHERE user_id=? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $cg = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return $cg ? (int) $cg : null;
}

$cgId = is_caregiver($con);
if (!$cgId)
    out(false, ['error' => 'Not authorized'], 403);

$act = $_GET['action'] ?? $_POST['action'] ?? 'get';

try {
    switch ($act) {

        case 'get': {
            // weekly template
            $rows = [];
            $res = $con->query("SELECT dow, start_time, end_time FROM caregiver_availability WHERE caregiver_id={$cgId} ORDER BY dow");
            while ($res && ($r = $res->fetch_assoc())) {
                $rows[] = ['dow' => (int) $r['dow'], 'start' => $r['start_time'], 'end' => $r['end_time']];
            }
            // overrides (next 60 days)
            $ov = [];
            $q = $con->query("SELECT id, `date`, start_time, end_time, is_available, note
                        FROM caregiver_availability_overrides
                        WHERE caregiver_id={$cgId} AND `date` >= CURDATE() - INTERVAL 7 DAY
                        ORDER BY `date` ASC");
            while ($q && ($r = $q->fetch_assoc()))
                $ov[] = $r;
            // time off (current & upcoming)
            $to = [];
            $q2 = $con->query("SELECT id, start_date, end_date, reason, note
                         FROM caregiver_time_off
                         WHERE caregiver_id={$cgId} AND end_date >= CURDATE() - INTERVAL 7 DAY
                         ORDER BY start_date ASC");
            while ($q2 && ($r = $q2->fetch_assoc()))
                $to[] = $r;
            // status flags
            $st = $con->query("SELECT is_accepting, available_until FROM caregivers WHERE id={$cgId}")->fetch_assoc();
            out(true, ['weekly' => $rows, 'overrides' => $ov, 'time_off' => $to, 'status' => $st]);
        }

        case 'save_weekly': {
            $slotsJson = need('slots'); // JSON: [{dow,start,end},...]
            if (!$slotsJson)
                out(false, ['error' => 'Missing slots'], 422);
            $slots = json_decode($slotsJson, true);
            if (!is_array($slots))
                out(false, ['error' => 'Invalid JSON'], 422);

            $con->begin_transaction();
            $con->query("DELETE FROM caregiver_availability WHERE caregiver_id={$cgId}");
            $ins = $con->prepare("INSERT INTO caregiver_availability (caregiver_id,dow,start_time,end_time) VALUES (?,?,?,?)");
            foreach ($slots as $s) {
                $dow = isset($s['dow']) ? (int) $s['dow'] : 0;
                if ($dow < 1 || $dow > 7)
                    continue;
                $st = safe_time($s['start'] ?? null);
                $en = safe_time($s['end'] ?? null);
                if (!$st && !$en)
                    continue;
                $ins->bind_param("iiss", $cgId, $dow, $st, $en);
                $ins->execute();
            }
            $ins->close();
            $con->commit();
            out(true, ['message' => 'Weekly availability saved']);
        }

        case 'set_online': {
            $accepting = i(need('accepting')) ? 1 : 0;
            $stmt = $con->prepare("UPDATE caregivers SET is_accepting=? WHERE id=?");
            $stmt->bind_param("ii", $accepting, $cgId);
            $stmt->execute();
            $stmt->close();
            out(true, ['is_accepting' => $accepting]);
        }

        case 'available_now': {
            $hours = max(1, min(24, i(need('hours'))));
            $stmt = $con->prepare("UPDATE caregivers SET is_accepting=1, available_until=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=?");
            $stmt->bind_param("ii", $hours, $cgId);
            $stmt->execute();
            $stmt->close();
            out(true, ['until' => date('Y-m-d H:i:s', time() + $hours * 3600)]);
        }

        case 'add_override': {
            $date = need('date'); // YYYY-MM-DD
            if (!$date)
                out(false, ['error' => 'Missing date'], 422);
            $st = safe_time(need('start'));
            $en = safe_time(need('end'));
            $avail = i(need('is_available')) ? 1 : 0;
            $note = substr((string) need('note'), 0, 255);
            $stmt = $con->prepare("INSERT INTO caregiver_availability_overrides (caregiver_id,`date`,start_time,end_time,is_available,note) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("isssis", $cgId, $date, $st, $en, $avail, $note);
            $stmt->execute();
            $id = $stmt->insert_id;
            $stmt->close();
            out(true, ['id' => $id]);
        }

        case 'delete_override': {
            $id = i(need('id'));
            if (!$id)
                out(false, ['error' => 'Missing id'], 422);
            $stmt = $con->prepare("DELETE FROM caregiver_availability_overrides WHERE id=? AND caregiver_id=?");
            $stmt->bind_param("ii", $id, $cgId);
            $stmt->execute();
            $aff = $stmt->affected_rows;
            $stmt->close();
            out(true, ['deleted' => $aff > 0]);
        }

        case 'add_timeoff': {
            $sd = need('start_date');
            $ed = need('end_date');
            if (!$sd || !$ed)
                out(false, ['error' => 'Missing dates'], 422);
            $reason = substr((string) need('reason'), 0, 120);
            $note = substr((string) need('note'), 0, 255);
            $stmt = $con->prepare("INSERT INTO caregiver_time_off (caregiver_id,start_date,end_date,reason,note) VALUES (?,?,?,?,?)");
            $stmt->bind_param("issss", $cgId, $sd, $ed, $reason, $note);
            $stmt->execute();
            $id = $stmt->insert_id;
            $stmt->close();
            out(true, ['id' => $id]);
        }

        case 'delete_timeoff': {
            $id = i(need('id'));
            if (!$id)
                out(false, ['error' => 'Missing id'], 422);
            $stmt = $con->prepare("DELETE FROM caregiver_time_off WHERE id=? AND caregiver_id=?");
            $stmt->bind_param("ii", $id, $cgId);
            $stmt->execute();
            $aff = $stmt->affected_rows;
            $stmt->close();
            out(true, ['deleted' => $aff > 0]);
        }

        default:
            out(false, ['error' => 'Unknown action'], 400);
    }

} catch (Throwable $t) {
    error_log($t->getMessage());
    out(false, ['error' => 'Server error'], 500);
}
