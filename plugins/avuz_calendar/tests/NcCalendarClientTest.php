<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/nc_calendar_client.php';

class NcCalendarClientTest extends TestCase {
    public function testResolvesByDomain(): void {
        $map = ['empresa.com' => 'https://empresa.avuzconecta.app'];
        $this->assertSame('https://empresa.avuzconecta.app', avuz_nc_client::resolve_base($map, 'user@empresa.com'));
        $this->assertNull(avuz_nc_client::resolve_base($map, 'user@other.com'));
    }
    public function testSignMatchesVerifierScheme(): void {
        $ics = 'ICSDATA';
        $env = avuz_nc_client::sign($ics, 'a@b.com', 'secret', 1000);
        [$encoded, $sig] = explode('.', $env, 2);
        $this->assertSame(hash_hmac('sha256', $encoded, 'secret'), $sig);
        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
        $this->assertSame('a@b.com', $payload['email']);
        $this->assertSame(hash('sha256', $ics), $payload['ics_sha256']);
        $this->assertSame(1000, $payload['iat']);
    }
}
