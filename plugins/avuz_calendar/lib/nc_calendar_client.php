<?php
class avuz_nc_client {
    public static function resolve_base(array $instances, string $email): ?string {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        return $instances[$domain] ?? null;
    }
    public static function sign(string $ics, string $email, string $secret, int $now): string {
        $payload = ['email' => $email, 'iat' => $now, 'ics_sha256' => hash('sha256', $ics)];
        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $secret);
    }
    /** @return array{ok:bool,status:int,body:string} */
    public static function post(string $base, string $ics, string $uid, string $envelope): array {
        $url = rtrim($base, '/') . '/apps/conectamail/api/calendar/events?uid=' . rawurlencode($uid);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $ics,
            CURLOPT_HTTPHEADER => ['Content-Type: text/calendar', 'X-Avuz-Signature: ' . $envelope],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $body];
    }
}
