<?php
function otp_generate(): string {
  return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function _otp_hash(string $code, array $cfg): string {
  return hash_hmac('sha256', $code, $cfg['otp_secret']);
}

function otp_can_send(array $sess, int $now, array $cfg): bool {
  if (empty($sess['last_send'])) return true;
  return ($now - $sess['last_send']) >= $cfg['otp_resend'];
}

function otp_set_challenge(array &$sess, string $role, ?string $memberId, string $phone9, string $code, int $now, array $cfg): void {
  $sess = [
    'hash'      => _otp_hash($code, $cfg),
    'role'      => $role,
    'member_id' => $memberId,
    'phone'     => $phone9,
    'expire'    => $now + $cfg['otp_ttl'],
    'attempts'  => 0,
    'last_send' => $now,
  ];
}

function otp_verify(array &$sess, string $input, int $now, array $cfg): array {
  if (empty($sess['hash'])) return ['ok'=>false,'reason'=>'none','role'=>null,'member_id'=>null];
  if ($now > $sess['expire']) { $sess = []; return ['ok'=>false,'reason'=>'expired','role'=>null,'member_id'=>null]; }
  if ($sess['attempts'] >= $cfg['otp_max_try']) { $sess = []; return ['ok'=>false,'reason'=>'locked','role'=>null,'member_id'=>null]; }
  $sess['attempts']++;
  if (hash_equals($sess['hash'], _otp_hash($input, $cfg))) {
    $role = $sess['role']; $mid = $sess['member_id'];
    $sess = [];
    return ['ok'=>true,'reason'=>'ok','role'=>$role,'member_id'=>$mid];
  }
  if ($sess['attempts'] >= $cfg['otp_max_try']) { $sess = []; return ['ok'=>false,'reason'=>'locked','role'=>null,'member_id'=>null]; }
  return ['ok'=>false,'reason'=>'bad','role'=>null,'member_id'=>null];
}
