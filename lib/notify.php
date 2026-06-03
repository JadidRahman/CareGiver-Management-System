<?php
// lib/notify.php
function add_log(mysqli $con, array $actor, string $action, string $subjectType, int $subjectId = null, array $meta = []): void {
  $actor_type = $actor['type'] ?? 'system';
  $actor_id   = isset($actor['id']) ? (int)$actor['id'] : null;
  $actor_name = $actor['name'] ?? null;
  $m = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

  $st = $con->prepare("INSERT INTO activity_log (actor_type,actor_id,actor_name,action,subject_type,subject_id,meta_json,created_at)
                       VALUES (?,?,?,?,?,?,?, NOW())");
  $st->bind_param("sisssis", $actor_type, $actor_id, $actor_name, $action, $subjectType, $subjectId, $m);
  @$st->execute(); $st->close();
}

function notify_admin(mysqli $con, string $title, string $body = null, string $link = null): void {
  $st = $con->prepare("INSERT INTO notifications (audience,title,body,link_url,created_at) VALUES ('admin',?,?,?, NOW())");
  $st->bind_param("sss", $title, $body, $link);
  @$st->execute(); $st->close();
}

function notify_caregiver(mysqli $con, int $caregiverId, string $title, string $body = null, string $link = null): void {
  // resolve caregivers.user_id -> notifications.user_id
  $uid = null;
  $q = $con->prepare("SELECT user_id FROM caregivers WHERE id=? LIMIT 1");
  $q->bind_param("i",$caregiverId); $q->execute();
  $uid = ($q->get_result()->fetch_row()[0] ?? null); $q->close();

  $st = $con->prepare("INSERT INTO notifications (audience,user_id,title,body,link_url,created_at) VALUES ('caregiver',?,?,?,?, NOW())");
  $st->bind_param("isss", $uid, $title, $body, $link);
  @$st->execute(); $st->close();
}
