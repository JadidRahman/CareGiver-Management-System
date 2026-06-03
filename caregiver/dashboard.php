<?php
session_start();
require_once __DIR__ . '/../config.php'; // must define $con (mysqli) + url()

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'caregiver') {
    header('Location: ' . url('login.php'));
    exit;
}

$CURRENCY = defined('CURRENCY') ? CURRENCY : '৳';

/* ---------- helpers ---------- */
function safe($s)
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function tryQuery(callable $fn, $fallback)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return $fallback;
    }
}
function table_exists(mysqli $con, string $table): bool
{
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1";
    $st = $con->prepare($sql);
    $st->bind_param("s", $table);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}
function column_exists(mysqli $con, string $table, string $column): bool
{
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $st = $con->prepare($sql);
    $st->bind_param("ss", $table, $column);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}

/* ---------- identify caregiver ---------- */
$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    header('Location: ' . url('login.php'));
    exit;
}

$cg = tryQuery(function () use ($con, $uid) {
    $st = $con->prepare("SELECT * FROM caregivers WHERE user_id=? LIMIT 1");
    $st->bind_param("i", $uid);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: [];
}, []);
if (!$cg) {
    header('Location: ' . url('login.php'));
    exit;
}
$cgId = (int) $cg['id'];

/* ---------- figure out earnings source ---------- */
$hasPayouts = table_exists($con, 'payouts') && column_exists($con, 'payouts', 'caregiver_id');
$paymentsMode = null; // 'direct' (payments.caregiver_id) or 'via_sa' (join with service_assignments)
if (table_exists($con, 'payments')) {
    if (column_exists($con, 'payments', 'caregiver_id')) {
        $paymentsMode = 'direct';
    } elseif (column_exists($con, 'payments', 'service_assignment_id') && table_exists($con, 'service_assignments')) {
        $paymentsMode = 'via_sa';
    }
}
$earnSource = $hasPayouts ? 'payouts' : ($paymentsMode ? 'payments' : 'estimate');

/* ---------- CSV export based on source ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'earnings_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=earnings_' . $cgId . '.csv');
    $out = fopen('php://output', 'w');
    if ($earnSource === 'payouts') {
        fputcsv($out, ['Date', 'Amount', 'Status', 'Method', 'Reference']);
        $rows = tryQuery(function () use ($con, $cgId) {
            $sql = "SELECT created_at, amount, status, method, reference FROM payouts WHERE caregiver_id=? ORDER BY created_at DESC LIMIT 500";
            $st = $con->prepare($sql);
            $st->bind_param("i", $cgId);
            $st->execute();
            $res = $st->get_result();
            $out = [];
            while ($r = $res->fetch_assoc())
                $out[] = $r;
            $st->close();
            return $out;
        }, []);
        foreach ($rows as $r) {
            fputcsv($out, [$r['created_at'], $r['amount'], $r['status'], $r['method'] ?? '', $r['reference'] ?? '']);
        }
    } elseif ($earnSource === 'payments') {
        fputcsv($out, ['Date', 'Amount', 'Status', 'Reference']);
        $hasRef = column_exists($con, 'payments', 'reference');
        $rows = tryQuery(function () use ($con, $cgId, $paymentsMode, $hasRef) {
            if ($paymentsMode === 'direct') {
                $sql = "SELECT created_at, amount, status" . ($hasRef ? ", reference" : "") . " FROM payments WHERE caregiver_id=? AND status IN ('paid','completed','success') ORDER BY created_at DESC LIMIT 500";
                $st = $con->prepare($sql);
                $st->bind_param("i", $cgId);
            } else {
                $sql = "SELECT p.created_at, p.amount, p.status" . ($hasRef ? ", p.reference" : "") . "
              FROM payments p
              JOIN service_assignments sa ON sa.id = p.service_assignment_id
              WHERE sa.caregiver_id=? AND p.status IN ('paid','completed','success')
              ORDER BY p.created_at DESC LIMIT 500";
                $st = $con->prepare($sql);
                $st->bind_param("i", $cgId);
            }
            $st->execute();
            $res = $st->get_result();
            $out = [];
            while ($r = $res->fetch_assoc())
                $out[] = $r;
            $st->close();
            return $out;
        }, []);
        foreach ($rows as $r) {
            fputcsv($out, [$r['created_at'], $r['amount'], $r['status'], $hasRef ? ($r['reference'] ?? '') : '']);
        }
    } else {
        // estimate from assignments
        fputcsv($out, ['Assignment ID', 'Start', 'End', 'Computed Hours', 'Rate Type', 'Rate Amount', 'Estimated Amount']);
        $rows = tryQuery(function () use ($con, $cgId, $cg) {
            if (!table_exists($con, 'service_assignments'))
                return [];
            $hasRa = column_exists($con, 'service_assignments', 'rate_amount');
            $hasRt = column_exists($con, 'service_assignments', 'rate_type');
            $sql = "SELECT id, start_time, end_time" . ($hasRa ? ", rate_amount" : "") . ($hasRt ? ", rate_type" : "") . "
            FROM service_assignments
            WHERE caregiver_id=? AND start_time >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')";
            $st = $con->prepare($sql);
            $st->bind_param("i", $cgId);
            $st->execute();
            $res = $st->get_result();
            $out = [];
            while ($r = $res->fetch_assoc())
                $out[] = $r;
            $st->close();
            return $out;
        }, []);
        foreach ($rows as $r) {
            $st = !empty($r['start_time']) ? strtotime($r['start_time']) : null;
            $en = !empty($r['end_time']) ? strtotime($r['end_time']) : null;
            $hours = ($st && $en && $en > $st) ? round(($en - $st) / 3600, 2) : 0.0;
            $rt = isset($r['rate_type']) ? $r['rate_type'] : ($cg['expected_rate_type'] ?? 'hourly');
            $ra = isset($r['rate_amount']) ? (float) $r['rate_amount'] : (float) ($cg['expected_rate_amount'] ?? 0);
            $amt = 0.0;
            $rtLow = strtolower((string) $rt);
            if ($rtLow === 'hourly') {
                $amt = $ra * $hours;
            } elseif ($rtLow === 'day' || $rtLow === '24h') {
                $days = max(1, (int) ceil($hours / 24));
                $amt = $ra * $days;
            } elseif ($rtLow === 'shift') {
                $shifts = max(1, (int) ceil($hours / 12));
                $amt = $ra * $shifts;
            } else {
                $amt = $ra ?: 0;
            }
            fputcsv($out, [$r['id'], $r['start_time'] ?? '', $r['end_time'] ?? '', $hours, $rt, $ra, round($amt, 2)]);
        }
    }
    fclose($out);
    exit;
}

/* ---------- greeting bits ---------- */
$firstName = trim(($cg['first_name'] ?? '') ?: ($_SESSION['user_name'] ?? 'Caregiver'));

/* ---------- metrics ---------- */
/* combined rating */
$rating = tryQuery(function () use ($con, $cgId) {
    if (!table_exists($con, 'caregiver_reviews'))
        return ['avg' => 0.0, 'count' => 0];
    $st = $con->prepare("SELECT ROUND(AVG(rating),2) a, COUNT(*) c FROM caregiver_reviews WHERE caregiver_id=?");
    $st->bind_param("i", $cgId);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    return ['avg' => (float) ($row[0] ?? 0), 'count' => (int) ($row[1] ?? 0)];
}, ['avg' => 0.0, 'count' => 0]);

/* assigned patients (current) */
$assigned = tryQuery(function () use ($con, $cgId) {
    if (!table_exists($con, 'service_assignments'))
        return [];
    $sql = "SELECT sa.id, sa.status, sa.start_time, sa.end_time,
                 IFNULL(p.full_name, CONCAT('Patient #', sa.patient_id)) patient,
                 p.phone_primary, p.address
          FROM service_assignments sa
          LEFT JOIN patients p ON p.id = sa.patient_id
          WHERE sa.caregiver_id=? AND sa.status IN ('active','ongoing','scheduled')
          ORDER BY COALESCE(sa.start_time, NOW()) ASC
          LIMIT 12";
    $st = $con->prepare($sql);
    $st->bind_param("i", $cgId);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc())
        $out[] = $r;
    $st->close();
    return $out;
}, []);

/* upcoming schedule (next 14 days) */
$upcoming = tryQuery(function () use ($con, $cgId) {
    if (!table_exists($con, 'service_assignments'))
        return [];
    $sql = "SELECT sa.id, sa.start_time, sa.end_time,
                 IFNULL(p.full_name, CONCAT('Patient #', sa.patient_id)) patient
          FROM service_assignments sa
          LEFT JOIN patients p ON p.id = sa.patient_id
          WHERE sa.caregiver_id=? AND sa.start_time >= NOW() - INTERVAL 1 HOUR
          ORDER BY sa.start_time ASC
          LIMIT 20";
    $st = $con->prepare($sql);
    $st->bind_param("i", $cgId);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc())
        $out[] = $r;
    $st->close();
    return $out;
}, []);

/* earnings (6 months) + MoM delta + statements by source */
function month_labels_6(): array
{
    $labels = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = (new DateTime("first day of -$i month"))->format('Y-m-01');
        $labels[] = (new DateTime($ts))->format('M Y');
    }
    return $labels;
}
$earn = ['labels' => month_labels_6(), 'series' => array_fill(0, 6, 0.0), 'last' => 0.0, 'prev' => 0.0, 'source' => $earnSource, 'note' => null];

if ($earnSource === 'payouts') {
    $earn = tryQuery(function () use ($con, $cgId) {
        $labels = month_labels_6();
        $map = array_fill_keys($labels, 0.0);
        $st = $con->prepare("
      SELECT DATE_FORMAT(created_at,'%b %Y') m, COALESCE(SUM(amount),0) s
      FROM payouts
      WHERE caregiver_id=? AND status IN ('paid','completed','success')
        AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
      GROUP BY DATE_FORMAT(created_at,'%Y-%m')
    ");
        $st->bind_param("i", $cgId);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $map[$r['m']] = (float) $r['s'];
        }
        $st->close();
        $series = [];
        foreach ($labels as $L) {
            $series[] = $map[$L] ?? 0.0;
        }
        $n = count($series);
        $last = $n ? $series[$n - 1] : 0.0;
        $prev = $n > 1 ? $series[$n - 2] : 0.0;
        return ['labels' => $labels, 'series' => $series, 'last' => $last, 'prev' => $prev, 'source' => 'payouts', 'note' => null];
    }, $earn);
} elseif ($earnSource === 'payments') {
    $earn = tryQuery(function () use ($con, $cgId, $paymentsMode) {
        $labels = month_labels_6();
        $map = array_fill_keys($labels, 0.0);
        if ($paymentsMode === 'direct') {
            $st = $con->prepare("
        SELECT DATE_FORMAT(created_at,'%b %Y') m, COALESCE(SUM(amount),0) s
        FROM payments
        WHERE caregiver_id=? AND status IN ('paid','completed','success')
          AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
        GROUP BY DATE_FORMAT(created_at,'%Y-%m')
      ");
            $st->bind_param("i", $cgId);
        } else {
            $st = $con->prepare("
        SELECT DATE_FORMAT(p.created_at,'%b %Y') m, COALESCE(SUM(p.amount),0) s
        FROM payments p
        JOIN service_assignments sa ON sa.id = p.service_assignment_id
        WHERE sa.caregiver_id=? AND p.status IN ('paid','completed','success')
          AND p.created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
        GROUP BY DATE_FORMAT(p.created_at,'%Y-%m')
      ");
            $st->bind_param("i", $cgId);
        }
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $map[$r['m']] = (float) $r['s'];
        }
        $st->close();
        $series = [];
        foreach ($labels as $L) {
            $series[] = $map[$L] ?? 0.0;
        }
        $n = count($series);
        $last = $n ? $series[$n - 1] : 0.0;
        $prev = $n > 1 ? $series[$n - 2] : 0.0;
        return ['labels' => $labels, 'series' => $series, 'last' => $last, 'prev' => $prev, 'source' => 'payments', 'note' => null];
    }, $earn);
} else {
    // estimate from assignments
    $earn = tryQuery(function () use ($con, $cgId, $cg) {
        if (!table_exists($con, 'service_assignments')) {
            return ['labels' => month_labels_6(), 'series' => array_fill(0, 6, 0.0), 'last' => 0.0, 'prev' => 0.0, 'source' => 'estimate', 'note' => 'No tables found; showing 0.'];
        }
        $labels = month_labels_6();
        $map = array_fill_keys($labels, 0.0);
        $hasRa = column_exists($con, 'service_assignments', 'rate_amount');
        $hasRt = column_exists($con, 'service_assignments', 'rate_type');

        $st = $con->prepare("SELECT id, start_time, end_time" . ($hasRa ? ", rate_amount" : "") . ($hasRt ? ", rate_type" : "") . "
                       FROM service_assignments
                       WHERE caregiver_id=? AND start_time >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')");
        $st->bind_param("i", $cgId);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $stt = !empty($r['start_time']) ? new DateTime($r['start_time']) : null;
            $ent = !empty($r['end_time']) ? new DateTime($r['end_time']) : null;
            if (!$stt || !$ent)
                continue;
            $hours = max(0.0, round(($ent->getTimestamp() - $stt->getTimestamp()) / 3600, 2));
            $rt = isset($r['rate_type']) ? $r['rate_type'] : ($cg['expected_rate_type'] ?? 'hourly');
            $ra = isset($r['rate_amount']) ? (float) $r['rate_amount'] : (float) ($cg['expected_rate_amount'] ?? 0);
            $amt = 0.0;
            $rtl = strtolower((string) $rt);
            if ($rtl === 'hourly')
                $amt = $ra * $hours;
            elseif ($rtl === 'day' || $rtl === '24h') {
                $days = max(1, (int) ceil($hours / 24));
                $amt = $ra * $days;
            } elseif ($rtl === 'shift') {
                $shifts = max(1, (int) ceil($hours / 12));
                $amt = $ra * $shifts;
            } else
                $amt = $ra ?: 0;
            $bucket = $stt->format('M Y');
            if (isset($map[$bucket]))
                $map[$bucket] += $amt;
        }
        $st->close();
        $series = [];
        foreach ($labels as $L) {
            $series[] = (float) ($map[$L] ?? 0.0);
        }
        $n = count($series);
        $last = $n ? $series[$n - 1] : 0.0;
        $prev = $n > 1 ? $series[$n - 2] : 0.0;
        return ['labels' => $labels, 'series' => $series, 'last' => $last, 'prev' => $prev, 'source' => 'estimate', 'note' => 'Estimated from assignment durations × rates.'];
    }, $earn);
}

/* statements list */
if ($earnSource === 'payouts') {
    $statements = tryQuery(function () use ($con, $cgId) {
        $sql = "SELECT id, created_at, amount, status, reference, method FROM payouts
          WHERE caregiver_id=? ORDER BY created_at DESC LIMIT 8";
        $st = $con->prepare($sql);
        $st->bind_param("i", $cgId);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($r = $res->fetch_assoc())
            $out[] = $r;
        $st->close();
        return $out;
    }, []);
} elseif ($earnSource === 'payments') {
    $hasRef = column_exists($con, 'payments', 'reference');
    $statements = tryQuery(function () use ($con, $cgId, $paymentsMode, $hasRef) {
        if ($paymentsMode === 'direct') {
            $sql = "SELECT created_at, amount, status" . ($hasRef ? ", reference" : "") . "
            FROM payments
            WHERE caregiver_id=? AND status IN ('paid','completed','success')
            ORDER BY created_at DESC LIMIT 8";
            $st = $con->prepare($sql);
            $st->bind_param("i", $cgId);
        } else {
            $sql = "SELECT p.created_at, p.amount, p.status" . ($hasRef ? ", p.reference" : "") . "
            FROM payments p
            JOIN service_assignments sa ON sa.id = p.service_assignment_id
            WHERE sa.caregiver_id=? AND p.status IN ('paid','completed','success')
            ORDER BY p.created_at DESC LIMIT 8";
            $st = $con->prepare($sql);
            $st->bind_param("i", $cgId);
        }
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($r = $res->fetch_assoc())
            $out[] = $r;
        $st->close();
        return $out;
    }, []);
} else {
    // estimate: show recent assignments as "estimated statements"
    $hasRa = table_exists($con, 'service_assignments') && column_exists($con, 'service_assignments', 'rate_amount');
    $hasRt = table_exists($con, 'service_assignments') && column_exists($con, 'service_assignments', 'rate_type');
    $statements = tryQuery(function () use ($con, $cgId, $cg, $hasRa, $hasRt) {
        if (!table_exists($con, 'service_assignments'))
            return [];
        $sql = "SELECT id, start_time, end_time" . ($hasRa ? ", rate_amount" : "") . ($hasRt ? ", rate_type" : "") . "
          FROM service_assignments
          WHERE caregiver_id=? ORDER BY COALESCE(end_time,start_time) DESC LIMIT 8";
        $st = $con->prepare($sql);
        $st->bind_param("i", $cgId);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $stt = !empty($r['start_time']) ? new DateTime($r['start_time']) : null;
            $ent = !empty($r['end_time']) ? new DateTime($r['end_time']) : null;
            $hours = ($stt && $ent && $ent > $stt) ? round(($ent->getTimestamp() - $stt->getTimestamp()) / 3600, 2) : 0.0;
            $rt = isset($r['rate_type']) ? $r['rate_type'] : ($cg['expected_rate_type'] ?? 'hourly');
            $ra = isset($r['rate_amount']) ? (float) $r['rate_amount'] : (float) ($cg['expected_rate_amount'] ?? 0);
            $amt = 0.0;
            $rtl = strtolower((string) $rt);
            if ($rtl === 'hourly')
                $amt = $ra * $hours;
            elseif ($rtl === 'day' || $rtl === '24h') {
                $days = max(1, (int) ceil($hours / 24));
                $amt = $ra * $days;
            } elseif ($rtl === 'shift') {
                $shifts = max(1, (int) ceil($hours / 12));
                $amt = $ra * $shifts;
            } else
                $amt = $ra ?: 0;
            $out[] = [
                'created_at' => $ent ? $ent->format('Y-m-d H:i:s') : ($stt ? $stt->format('Y-m-d H:i:s') : null),
                'amount' => round($amt, 2),
                'status' => 'estimated',
                'method' => null,
                'reference' => 'assignment #' . $r['id'] . ' • ' . $rt
            ];
        }
        $st->close();
        return $out;
    }, []);
}

/* additional numbers for tiles */
$totalPatients = tryQuery(function () use ($con, $cgId) {
    if (!table_exists($con, 'service_assignments'))
        return 0;
    $st = $con->prepare("SELECT COUNT(DISTINCT patient_id) FROM service_assignments WHERE caregiver_id=?");
    $st->bind_param("i", $cgId);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    return (int) ($row[0] ?? 0);
}, 0);
$totalHours = tryQuery(function () use ($con, $cgId) {
    if (!table_exists($con, 'service_assignments') || !column_exists($con, 'service_assignments', 'start_time') || !column_exists($con, 'service_assignments', 'end_time'))
        return 0;
    $st = $con->prepare("SELECT SUM(TIMESTAMPDIFF(HOUR, start_time, end_time)) FROM service_assignments WHERE caregiver_id=? AND end_time IS NOT NULL");
    $st->bind_param("i", $cgId);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    return (int) ($row[0] ?? 0);
}, 0);

/* quick doc links */
$docPolice = !empty($cg['police_verification_path']) ? '../' . safe($cg['police_verification_path']) : null;
$docMedical = !empty($cg['medical_fitness_path']) ? '../' . safe($cg['medical_fitness_path']) : null;

/* MoM percentage */
$momUp = ($earn['prev'] > 0) ? ($earn['last'] - $earn['prev']) / $earn['prev'] * 100 : ($earn['last'] > 0 ? 100 : 0);
$momUpBool = $momUp >= 0;
$earnSourceLabel = ($earn['source'] === 'payouts' ? 'Payouts' : ($earn['source'] === 'payments' ? 'Payments' : 'Estimate'));
$earnNote = $earn['note'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>My Dashboard | CGMS Caregiver</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --pri: #1d4ed8;
            --acc: #06b6d4;
            --ink: #0f172a;
            --mut: #64748b;
        }

        body {
            background: radial-gradient(900px 600px at 10% -10%, #e0f2fe 0%, transparent 60%), radial-gradient(900px 600px at 110% 10%, #e0fffb 0%, transparent 60%), #f8fafc;
            color: var(--ink);
        }

        .navbar {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, .9) !important;
            border-bottom: 1px solid rgba(15, 23, 42, .06);
        }

        .brand {
            font-weight: 800;
            background: linear-gradient(90deg, var(--pri), var(--acc));
            -webkit-background-clip: text;
            color: transparent;
        }

        .card-soft {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, .08);
            border-radius: 18px;
            box-shadow: 0 16px 40px rgba(2, 6, 23, .07);
        }

        .kpi {
            font-weight: 800;
            font-size: 1.6rem;
        }

        .kpi-sub {
            color: var(--mut);
            font-size: .9rem;
        }

        .badge-delta.up {
            background: rgba(16, 185, 129, .12);
            color: #065f46;
            border: 1px solid rgba(16, 185, 129, .24);
        }

        .badge-delta.down {
            background: rgba(239, 68, 68, .12);
            color: #7f1d1d;
            border: 1px solid rgba(239, 68, 68, .24);
        }

        .progress-thin {
            height: 8px;
        }

        .fade-up {
            opacity: 0;
            transform: translateY(12px);
            animation: fadeUp .6s ease forwards;
        }

        @keyframes fadeUp {
            to {
                opacity: 1;
                transform: none;
            }
        }

        .table>:not(caption)>*>* {
            padding: .6rem .7rem;
        }

        .btn-gradient {
            background: linear-gradient(90deg, var(--pri), #3b82f6);
            border: none;
            color: #fff;
        }

        .btn-accent {
            background: linear-gradient(90deg, var(--acc), #22d3ee);
            border: none;
            color: #052e2b;
        }

        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
        }

        .section-title {
            font-weight: 700;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand brand" href="<?php echo safe(url('index.php')); ?>">CGMS</a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <a class="btn btn-sm btn-outline-secondary"
                    href="<?php echo safe(url('caregiver/logout.php')); ?>">Logout</a>
            </div>
        </div>
    </nav>


    <div class="container my-3 my-md-4">
        <!-- Greeting -->
        <div class="card card-soft p-3 p-md-4 fade-up">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <?php if (!empty($cg['photo_path'])): ?>
                        <img src="<?php echo '../' . safe($cg['photo_path']); ?>" class="avatar" alt="">
                    <?php else: ?>
                        <div class="avatar bg-light d-flex align-items-center justify-content-center"><i
                                class="bi bi-person text-muted"></i></div>
                    <?php endif; ?>
                    <div>
                        <div class="h5 mb-0">Good <span id="greet">day</span>, <?php echo safe($firstName); ?>!</div>
                        <div class="text-muted small">Here’s your snapshot for <span id="today"></span>.</div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-primary btn-sm" href="#" id="btnAvailability">
                        <i class="bi bi-toggle-on me-1"></i> Availability
                    </a>

                    <a class="btn btn-accent btn-sm" href="<?php echo safe(url('caregiver/profile.php')); ?>"><i
                            class="bi bi-pencil-square me-1"></i> Edit Profile</a>
                </div>
            </div>
        </div>

        <!-- KPI Row -->
        <div class="row g-3 mt-1">
            <div class="col-6 col-md-3">
                <div class="card card-soft p-3 fade-up">
                    <div class="kpi"><?php echo (int) $totalPatients; ?></div>
                    <div class="kpi-sub">Patients Served</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-soft p-3 fade-up">
                    <div class="kpi"><?php echo (int) $totalHours; ?></div>
                    <div class="kpi-sub">Hours Logged</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-soft p-3 fade-up">
                    <div class="d-flex align-items-baseline justify-content-between">
                        <div class="kpi"><?php echo number_format($rating['avg'], 2); ?></div>
                        <span class="badge <?php echo $rating['avg'] >= 4 ? 'badge-delta up' : 'badge-delta down'; ?>">
                            <?php echo (int) $rating['count']; ?> reviews
                        </span>
                    </div>
                    <div class="kpi-sub">Your Rating</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-soft p-3 fade-up">
                    <div class="d-flex align-items-baseline justify-content-between">
                        <div class="kpi"><?php echo $CURRENCY . ' ' . number_format($earn['last'], 0); ?></div>
                        <span
                            class="badge badge-delta <?php echo $momUpBool ? 'up' : 'down'; ?>"><?php echo ($momUpBool ? '+' : '') . number_format($momUp, 1); ?>%</span>
                    </div>
                    <div class="kpi-sub">This Month’s Earnings<?php echo $earnSource === 'estimate' ? ' (est.)' : ''; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Earnings + Profile completeness -->
        <div class="row g-3 mt-1">
            <div class="col-lg-7">
                <div class="card card-soft p-3 fade-up">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="section-title">Earnings (last 6 months) —
                                <?php echo safe($earnSourceLabel); ?><?php echo $earnSource === 'estimate' ? ' (est.)' : ''; ?>
                            </div>
                            <div class="text-muted small">
                                <?php if ($earnSource === 'payouts'): ?>Includes paid/completed payouts.
                                <?php elseif ($earnSource === 'payments'): ?>Based on successful payments.
                                <?php else: ?>Estimated from assignment durations × rates.<?php endif; ?>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="?export=earnings_csv"><i
                                class="bi bi-download me-1"></i>Export CSV</a>
                    </div>
                    <canvas id="earnChart" height="120" class="mt-2"></canvas>
                    <?php if ($earnNote): ?>
                        <div class="small text-muted mt-2"><?php echo safe($earnNote); ?></div><?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <?php
                // profile completeness (simple)
                $fields = ['dob', 'gender', 'phone_primary', 'email', 'present_address', 'present_district', 'highest_qualification', 'photo_path'];
                $filled = 0;
                foreach ($fields as $f) {
                    if (!empty($cg[$f]))
                        $filled++;
                }
                $complete = round($filled / count($fields) * 100);
                ?>
                <div class="card card-soft p-3 fade-up">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="section-title mb-0">Profile Completeness</div>
                        <span class="text-muted small"><?php echo (int) $complete; ?>%</span>
                    </div>
                    <div class="progress progress-thin my-2">
                        <div class="progress-bar" role="progressbar" style="width: <?php echo (int) $complete; ?>%">
                        </div>
                    </div>
                    <div class="small text-muted">Tip: Add your documents & details to improve matching.</div>

                    <hr>
                    <div class="row g-2">
                        <div class="col-12"><strong>My Documents</strong></div>
                        <div class="col-12 small">
                            <?php if ($docPolice): ?><a target="_blank" href="<?php echo $docPolice; ?>"><i
                                        class="bi bi-file-earmark-text"></i> Police Verification</a>
                            <?php else: ?><span class="text-muted">Police Verification: N/A</span><?php endif; ?>
                        </div>
                        <div class="col-12 small">
                            <?php if ($docMedical): ?><a target="_blank" href="<?php echo $docMedical; ?>"><i
                                        class="bi bi-file-earmark-medical"></i> Medical Fitness</a>
                            <?php else: ?><span class="text-muted">Medical Fitness: N/A</span><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assigned patients + Schedule -->
        <div class="row g-3 mt-1">
            <div class="col-lg-6">
                <div class="card card-soft p-3 fade-up">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="section-title mb-0">Assigned Patients</div>
                        <span class="text-muted small"><?php echo count($assigned); ?> total</span>
                    </div>
                    <div class="table-responsive mt-2">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Status</th>
                                    <th>Start</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$assigned): ?>
                                    <tr>
                                        <td colspan="4" class="text-muted">No active or scheduled patients.</td>
                                    </tr>
                                <?php else:
                                    foreach ($assigned as $a): ?>
                                        <tr>
                                            <td><?php echo safe($a['patient']); ?></td>
                                            <td><span
                                                    class="badge bg-<?php echo ($a['status'] === 'active' ? 'success' : ($a['status'] === 'scheduled' ? 'warning' : 'secondary')); ?>"><?php echo safe(ucfirst($a['status'])); ?></span>
                                            </td>
                                            <td><?php echo $a['start_time'] ? safe((new DateTime($a['start_time']))->format('M j, g:ia')) : '—'; ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="#" class="btn btn-sm btn-outline-secondary"
                                                    onclick="viewPatient(<?php echo (int) $a['id']; ?>);return false;"><i
                                                        class="bi bi-eye"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card card-soft p-3 fade-up">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="section-title mb-0">Upcoming Schedule</div>
                        <span class="text-muted small">Next 2 weeks</span>
                    </div>
                    <div class="table-responsive mt-2">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Patient</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$upcoming): ?>
                                    <tr>
                                        <td colspan="3" class="text-muted">No upcoming shifts yet.</td>
                                    </tr>
                                <?php else:
                                    foreach ($upcoming as $s):
                                        $st = $s['start_time'] ? new DateTime($s['start_time']) : null;
                                        $en = $s['end_time'] ? new DateTime($s['end_time']) : null; ?>
                                        <tr>
                                            <td><?php echo $st ? safe($st->format('D, M j')) : '—'; ?></td>
                                            <td><?php echo $st ? safe($st->format('g:ia')) : '—'; ?><?php echo $en ? ' – ' . safe($en->format('g:ia')) : ''; ?>
                                            </td>
                                            <td><?php echo safe($s['patient']); ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Earnings statements -->
        <div class="row g-3 mt-1">
            <div class="col-12">
                <div class="card card-soft p-3 fade-up">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="section-title mb-0">Earning Statements —
                            <?php echo safe($earnSourceLabel); ?><?php echo $earnSource === 'estimate' ? ' (est.)' : ''; ?>
                        </div>
                        <div>
                            <a class="btn btn-sm btn-outline-primary" href="?export=earnings_csv"><i
                                    class="bi bi-download me-1"></i>Export CSV</a>
                        </div>
                    </div>
                    <div class="table-responsive mt-2">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <?php if ($earnSource !== 'estimate'): ?>
                                        <th>Method</th><?php endif; ?>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$statements): ?>
                                    <tr>
                                        <td colspan="<?php echo $earnSource !== 'estimate' ? 5 : 4; ?>" class="text-muted">
                                            No
                                            records yet.</td>
                                    </tr>
                                <?php else:
                                    foreach ($statements as $p): ?>
                                        <tr>
                                            <td><?php echo !empty($p['created_at']) ? safe((new DateTime($p['created_at']))->format('M j, Y g:ia')) : '—'; ?>
                                            </td>
                                            <td><?php echo $CURRENCY . ' ' . number_format((float) $p['amount'], 2); ?></td>
                                            <td><span
                                                    class="badge bg-<?php
                                                    $ok = in_array(strtolower($p['status']), ['paid', 'completed', 'success', 'estimated']);
                                                    echo $ok ? 'success' : 'secondary'; ?>"><?php echo safe(ucfirst($p['status'])); ?></span>
                                            </td>
                                            <?php if ($earnSource !== 'estimate'): ?>
                                                <td><?php echo isset($p['method']) && $p['method'] ? safe($p['method']) : '—'; ?>
                                                </td>
                                            <?php endif; ?>
                                            <td class="small text-muted"><?php
                                            $ref = $p['reference'] ?? '';
                                            echo $ref ? safe($ref) : ($earnSource === 'estimate' ? 'Computed from assignment' : '—');
                                            ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <footer class="text-muted small mt-4">© <?php echo date('Y'); ?> CGMS • Caregiver Portal</footer>
    </div>

    <!-- Patient Modal -->
    <div class="modal fade" id="ptModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Patient Info</h5>
                    <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="ptModalBody">Loading…</div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        /* greeting time */
        (function () {
            const h = new Date().getHours();
            const g = (h < 12) ? 'morning' : (h < 17) ? 'afternoon' : 'evening';
            document.getElementById('greet').textContent = g;
            const d = new Date();
            document.getElementById('today').textContent = d.toLocaleString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
        })();

        /* Earnings chart */
        (() => {
            const ctx = document.getElementById('earnChart');
            if (!ctx) return;
            const labels = <?php echo json_encode($earn['labels']); ?>;
            const data = <?php echo json_encode($earn['series']); ?>;
            const gradient = (c) => {
                const g = c.createLinearGradient(0, 0, 0, 200);
                g.addColorStop(0, 'rgba(29,78,216,.35)');
                g.addColorStop(1, 'rgba(29,78,216,0)');
                return g;
            };
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels, datasets: [{
                        label: 'Earnings',
                        data,
                        fill: true,
                        tension: .35,
                        borderWidth: 2,
                        borderColor: 'rgba(29,78,216,1)',
                        backgroundColor: (ctx) => gradient(ctx.chart.ctx),
                        pointRadius: 3
                    }]
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        })();

        /* Patient info modal (AJAX) */
        function viewPatient(assignId) {
            const body = document.getElementById('ptModalBody');
            body.innerHTML = 'Loading…';
            const modal = new bootstrap.Modal(document.getElementById('ptModal')); modal.show();
            fetch('dashboard.php?ajax=patient_info&id=' + encodeURIComponent(assignId))
                .then(r => r.text()).then(html => body.innerHTML = html)
                .catch(() => body.innerHTML = '<div class="text-danger">Failed to load.</div>');
        }
    </script>

    <!-- Availability Modal -->
    <div class="modal fade" id="availModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Your Availability</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="swAccepting">
                            <label class="form-check-label" for="swAccepting">Accepting new shifts</label>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted">Available now for</span>
                            <select id="nowHours" class="form-select form-select-sm" style="width:90px">
                                <option value="1">1h</option>
                                <option value="2">2h</option>
                                <option value="4" selected>4h</option>
                                <option value="8">8h</option>
                            </select>
                            <button class="btn btn-sm btn-outline-primary" id="btnNow">Activate</button>
                            <span class="small text-muted" id="nowUntil" style="display:none;"></span>
                        </div>
                    </div>

                    <h6 class="mb-2">Weekly Template</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="tblWeek">
                            <thead>
                                <tr>
                                    <th style="width:90px">Day</th>
                                    <th>Start</th>
                                    <th>End</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="d-flex gap-2 mb-3">
                        <button class="btn btn-light btn-sm" id="btnCopyAll">Copy first day to all</button>
                        <button class="btn btn-light btn-sm" id="btnClearWeek">Clear week</button>
                        <button class="btn btn-primary btn-sm" id="btnSaveWeek">Save Weekly</button>
                    </div>

                    <hr>
                    <h6 class="mb-2">Overrides</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" id="ovDate">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Start</label>
                            <input type="time" class="form-control" id="ovStart">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">End</label>
                            <input type="time" class="form-control" id="ovEnd">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Type</label>
                            <select class="form-select" id="ovType">
                                <option value="1">Available only</option>
                                <option value="0">Unavailable (block)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-primary w-100" id="btnAddOv">Add</button>
                        </div>
                    </div>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm" id="tblOv">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Type</th>
                                    <th>Note</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>

                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            const weekBody = document.querySelector('#tblWeek tbody');
            const toast = (msg, ok = true) => {
                const el = document.createElement('div');
                el.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
                el.style.position = 'fixed'; el.style.right = '16px'; el.style.bottom = '16px'; el.style.zIndex = 1060;
                el.textContent = msg; document.body.appendChild(el);
                setTimeout(() => el.remove(), 1800);
            };

            // Build rows
            weekBody.innerHTML = dayNames.map((d, i) => `
    <tr data-dow="${i + 1}">
      <td>${d}</td>
      <td><input type="time" class="form-control form-control-sm start"></td>
      <td><input type="time" class="form-control form-control-sm end"></td>
    </tr>`).join('');
    

            const modal = new bootstrap.Modal('#availModal');
            document.getElementById('btnAvailability')?.addEventListener('click', (e) => { e.preventDefault(); load(); modal.show(); });

            function load() {
                fetch('availability_api.php?action=get').then(r => r.json()).then(j => {
                    // status
                    document.getElementById('swAccepting').checked = !!(+j.status?.is_accepting || 0);
                    const until = j.status?.available_until;
                    const nu = document.getElementById('nowUntil');
                    if (until) { nu.style.display = 'inline'; nu.textContent = 'until ' + until; } else nu.style.display = 'none';

                    // weekly
                    weekBody.querySelectorAll('tr').forEach(tr => {
                        tr.querySelector('.start').value = ''; tr.querySelector('.end').value = '';
                    });
                    (j.weekly || []).forEach(r => {
                        const tr = weekBody.querySelector(`tr[data-dow="${r.dow}"]`);
                        if (!tr) return;
                        tr.querySelector('.start').value = (r.start || '').slice(0, 5);
                        tr.querySelector('.end').value = (r.end || '').slice(0, 5);
                    });

                    // overrides
                    const tb = document.querySelector('#tblOv tbody');
                    tb.innerHTML = (j.overrides || []).map(o => `
        <tr data-id="${o.id}">
          <td>${o.date}</td>
          <td>${(o.start_time || '').slice(0, 5) || '—'}</td>
          <td>${(o.end_time || '').slice(0, 5) || '—'}</td>
          <td>${Number(o.is_available) === 1 ? 'Available' : 'Blocked'}</td>
          <td>${o.note || ''}</td>
          <td class="text-end"><button class="btn btn-sm btn-outline-danger btnDelOv">Delete</button></td>
        </tr>`).join('');
                    tb.querySelectorAll('.btnDelOv').forEach(b => b.onclick = (ev) => {
                        const id = ev.target.closest('tr').dataset.id;
                        fetch('availability_api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ action: 'delete_override', id })
                        }).then(r => r.json()).then(j => { if (j.ok) { ev.target.closest('tr').remove(); toast('Deleted'); } });
                    });
                });
            }

            // actions
            document.getElementById('swAccepting').addEventListener('change', (e) => {
                const accepting = e.target.checked ? 1 : 0;
                fetch('availability_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'set_online', accepting })
                }).then(r => r.json()).then(j => toast(j.ok ? 'Updated' : 'Failed', j.ok));
            });

            document.getElementById('btnNow').addEventListener('click', () => {
                const hours = document.getElementById('nowHours').value;
                fetch('availability_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'available_now', hours })
                }).then(r => r.json()).then(j => {
                    if (j.ok) {
                        const nu = document.getElementById('nowUntil');
                        nu.style.display = 'inline'; nu.textContent = 'until ' + j.until;
                        document.getElementById('swAccepting').checked = true;
                        toast('You are available now');
                    }
                });
            });

            document.getElementById('btnCopyAll').onclick = () => {
                const first = weekBody.querySelector('tr[data-dow="1"]');
                const s = first.querySelector('.start').value, e = first.querySelector('.end').value;
                weekBody.querySelectorAll('tr').forEach(tr => {
                    tr.querySelector('.start').value = s; tr.querySelector('.end').value = e;
                });
            };
            document.getElementById('btnClearWeek').onclick = () => {
                weekBody.querySelectorAll('input').forEach(i => i.value = '');
            };
            document.getElementById('btnSaveWeek').onclick = () => {
                const slots = [];
                weekBody.querySelectorAll('tr').forEach(tr => {
                    const dow = +tr.dataset.dow;
                    const s = tr.querySelector('.start').value;
                    const e = tr.querySelector('.end').value;
                    if (s || e) slots.push({ dow, start: s ? s + ':00' : null, end: e ? e + ':00' : null });
                });
                fetch('availability_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'save_weekly', slots: JSON.stringify(slots) })
                }).then(r => r.json()).then(j => toast(j.ok ? 'Saved' : 'Failed', j.ok));
            };

            document.getElementById('btnAddOv').onclick = () => {
                const d = document.getElementById('ovDate').value;
                const s = document.getElementById('ovStart').value;
                const e = document.getElementById('ovEnd').value;
                const t = (document.getElementById('ovType').value === '1') ? 1 : 0;
                if (!d) { toast('Pick a date', false); return; }
                const body = new URLSearchParams({ action: 'add_override', date: d, start: s ? s + ':00' : '', end: e ? e + ':00' : '', is_available: t });
                fetch('availability_api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json()).then(j => {
                        if (j.ok) { toast('Override added'); load(); }
                        else toast('Failed', false);
                    });
            };

        })();
    </script>

</body>

</html>
<?php
/* ================= AJAX: patient info from assignment id ================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_info' && isset($_GET['id'])) {
    $aid = (int) $_GET['id'];
    $html = tryQuery(function () use ($con, $aid, $cgId) {
        if (!table_exists($con, 'service_assignments'))
            return '<div class="text-muted">No assignment table.</div>';
        $sql = "SELECT sa.id, sa.start_time, sa.end_time, sa.status,
                   p.full_name, p.phone_primary, p.address, p.age, p.gender
            FROM service_assignments sa
            LEFT JOIN patients p ON p.id = sa.patient_id
            WHERE sa.id=? AND sa.caregiver_id=? LIMIT 1";
        $st = $con->prepare($sql);
        $st->bind_param("ii", $aid, $cgId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$r)
            return '<div class="text-danger">Not found.</div>';
        ob_start(); ?>
        <div class="row g-3">
            <div class="col-md-6">
                <h6 class="mb-1">Patient</h6>
                <div class="small"><strong>Name:</strong> <?php echo safe($r['full_name'] ?? '—'); ?></div>
                <div class="small"><strong>Phone:</strong> <?php echo safe($r['phone_primary'] ?? '—'); ?></div>
                <div class="small"><strong>Gender:</strong> <?php echo safe(ucfirst($r['gender'] ?? '')); ?></div>
                <div class="small"><strong>Age:</strong> <?php echo safe($r['age'] ?? '—'); ?></div>
                <div class="small"><strong>Address:</strong> <?php echo safe($r['address'] ?? '—'); ?></div>
            </div>
            <div class="col-md-6">
                <h6 class="mb-1">Assignment</h6>
                <div class="small"><strong>Status:</strong> <?php echo safe(ucfirst($r['status'])); ?></div>
                <div class="small"><strong>Start:</strong>
                    <?php echo $r['start_time'] ? safe((new DateTime($r['start_time']))->format('M j, g:ia')) : '—'; ?></div>
                <div class="small"><strong>End:</strong>
                    <?php echo $r['end_time'] ? safe((new DateTime($r['end_time']))->format('M j, g:ia')) : '—'; ?></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }, '<div class="text-danger">Error.</div>');
    echo $html;
    exit;
}
?>