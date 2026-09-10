# Email Calendar Invites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a Roundcube user RSVP to a meeting invite and add it to their AvuzConecta (Nextcloud) calendar, in-iframe and standalone.

**Architecture:** A small `avuz_calendar` Roundcube plugin (vendoring Sabre/VObject) detects `text/calendar; method=REQUEST` parts, renders an invite card, sends the iTip `REPLY` over Zoho SMTP, and POSTs the event — HMAC-signed with the shared `ROUNDCUBE_SSO_SECRET` — to the user's Nextcloud instance (resolved from an `AVUZ_NC_INSTANCES` email-domain map). A new public, HMAC-gated endpoint in the `conectamail` NC app writes the VEVENT into the user's default calendar via `OCP\Calendar`.

**Tech Stack:** PHP 8.2 (Roundcube plugin), Sabre/VObject (vendored), Roundcube plugin API + `rcmail_sendmail`, Nextcloud app framework (`OCA\Roundcube` namespace, app id `conectamail`), `OCP\Calendar\IManager`/`ICreateFromString`, PHPUnit both sides.

## Global Constraints

- Roundcube base: 1.6.14 fork, branch `avuz-customization`. Nextcloud app `conectamail`: `min-version=28 max-version=35`, php `min-version=8.0`.
- Shared secret for signing: app value `conectamail`/`sso_secret`, which already falls back to env `ROUNDCUBE_SSO_SECRET`. Reuse it — no new infra.
- The signed payload carries the user's email; the NC endpoint MUST NOT accept a target user from any unsigned field. A user can only write to their own calendar.
- Replay window: reject signed requests whose `iat` is older than 300 seconds.
- Roundcube plugin ships via the `Dockerfile` COPY overlay (edits outside the COPY list are ignored). NC app ships via the root `Dockerfile` `COPY . /var/www/html/` (no build step, no `.min` twin).
- Two repos: Roundcube = `/Users/patrickrezende/work/avuz/roundcube-webmail`; Nextcloud = `/Users/patrickrezende/work/avuz/avuz-server`.
- No secrets in git. No `.ics` content logged at info level (may contain private meeting data).

---

## File Structure

**Nextcloud (`avuz-server`):**
- Create `apps/conectamail/lib/Service/SignatureVerifier.php` — verify the HMAC envelope.
- Create `apps/conectamail/lib/Service/CalendarImportService.php` — email→user, resolve calendar, dedup-by-UID, write.
- Create `apps/conectamail/lib/Controller/CalendarApiController.php` — the public HMAC-gated route.
- Modify `apps/conectamail/appinfo/routes.php` — add the route.
- Create `apps/conectamail/tests/Unit/Service/SignatureVerifierTest.php`, `.../CalendarImportServiceTest.php`.

**Roundcube (`roundcube-webmail`):**
- Create `plugins/avuz_calendar/avuz_calendar.php` — plugin entry (hooks, action, config).
- Create `plugins/avuz_calendar/lib/ical_invite.php` — parse an `.ics` REQUEST into a value object (Sabre).
- Create `plugins/avuz_calendar/lib/itip_reply.php` — build the iTip REPLY `.ics` (Sabre).
- Create `plugins/avuz_calendar/lib/nc_calendar_client.php` — resolve instance + signed POST.
- Create `plugins/avuz_calendar/js/invite.js` — the RSVP buttons behaviour.
- Create `plugins/avuz_calendar/skins/elastic/invite.css` and `templates/` bits as needed.
- Create `plugins/avuz_calendar/composer.json` + vendored `vendor/` (Sabre/VObject).
- Create `plugins/avuz_calendar/tests/*` (PHPUnit).
- Modify `config/config.inc.php` — add `avuz_calendar` to plugins, add `AVUZ_NC_INSTANCES` parse.
- Modify `Dockerfile` — `COPY plugins/avuz_calendar`.

---

## PHASE 1 — Nextcloud calendar-import endpoint (`conectamail`)

Independently testable and shippable: once done, a signed `curl` writes an event into a user's calendar.

### Task 1: Signature verifier

**Files:**
- Create: `apps/conectamail/lib/Service/SignatureVerifier.php`
- Test: `apps/conectamail/tests/Unit/Service/SignatureVerifierTest.php`

**Interfaces:**
- Produces: `SignatureVerifier::verify(string $envelope, string $body): array` — returns the decoded payload `['email'=>string,'iat'=>int,'ics_sha256'=>string]` on success; throws `\OCA\Roundcube\Service\SignatureException` on any failure (bad format, bad signature, expired, body hash mismatch).
- Consumes: the secret via constructor `string $secret`.
- Envelope format (produced by Roundcube in Task 12): `base64url(json_encode(['email'=>..,'iat'=>..,'ics_sha256'=>hash('sha256',$ics)])) . '.' . hash_hmac('sha256', <base64url>, $secret)`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace OCA\Roundcube\Tests\Unit\Service;
use OCA\Roundcube\Service\SignatureVerifier;
use OCA\Roundcube\Service\SignatureException;
use PHPUnit\Framework\TestCase;

class SignatureVerifierTest extends TestCase {
    private string $secret = 'test-secret';

    private function envelope(array $payload): string {
        $p = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        return $p . '.' . hash_hmac('sha256', $p, $this->secret);
    }

    public function testVerifyReturnsPayloadForValidEnvelope(): void {
        $ics = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";
        $env = $this->envelope(['email'=>'a@b.com','iat'=>time(),'ics_sha256'=>hash('sha256',$ics)]);
        $payload = (new SignatureVerifier($this->secret))->verify($env, $ics);
        $this->assertSame('a@b.com', $payload['email']);
    }

    public function testRejectsTamperedSignature(): void {
        $this->expectException(SignatureException::class);
        $ics = 'x';
        $env = $this->envelope(['email'=>'a@b.com','iat'=>time(),'ics_sha256'=>hash('sha256',$ics)]);
        (new SignatureVerifier($this->secret))->verify($env . 'z', $ics);
    }

    public function testRejectsExpired(): void {
        $this->expectException(SignatureException::class);
        $ics = 'x';
        $env = $this->envelope(['email'=>'a@b.com','iat'=>time()-3600,'ics_sha256'=>hash('sha256',$ics)]);
        (new SignatureVerifier($this->secret))->verify($env, $ics);
    }

    public function testRejectsBodyHashMismatch(): void {
        $this->expectException(SignatureException::class);
        $env = $this->envelope(['email'=>'a@b.com','iat'=>time(),'ics_sha256'=>hash('sha256','original')]);
        (new SignatureVerifier($this->secret))->verify($env, 'tampered-body');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/patrickrezende/work/avuz/avuz-server && php -d memory_limit=512M vendor/bin/phpunit apps/conectamail/tests/Unit/Service/SignatureVerifierTest.php`
Expected: FAIL — class `SignatureVerifier` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);
namespace OCA\Roundcube\Service;

class SignatureException extends \RuntimeException {}

class SignatureVerifier {
    private const MAX_AGE = 300;

    public function __construct(private string $secret) {}

    /** @return array{email:string,iat:int,ics_sha256:string} */
    public function verify(string $envelope, string $body): array {
        if ($this->secret === '') {
            throw new SignatureException('missing secret');
        }
        $dot = strrpos($envelope, '.');
        if ($dot === false) {
            throw new SignatureException('malformed envelope');
        }
        $encoded = substr($envelope, 0, $dot);
        $sig = substr($envelope, $dot + 1);
        $expected = hash_hmac('sha256', $encoded, $this->secret);
        if (!hash_equals($expected, $sig)) {
            throw new SignatureException('bad signature');
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);
        if (!is_array($payload) || !isset($payload['email'], $payload['iat'], $payload['ics_sha256'])) {
            throw new SignatureException('bad payload');
        }
        if (abs(time() - (int) $payload['iat']) > self::MAX_AGE) {
            throw new SignatureException('expired');
        }
        if (!hash_equals((string) $payload['ics_sha256'], hash('sha256', $body))) {
            throw new SignatureException('body mismatch');
        }
        return ['email'=>(string)$payload['email'],'iat'=>(int)$payload['iat'],'ics_sha256'=>(string)$payload['ics_sha256']];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/patrickrezende/work/avuz/avuz-server && php vendor/bin/phpunit apps/conectamail/tests/Unit/Service/SignatureVerifierTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
cd /Users/patrickrezende/work/avuz/avuz-server
git add apps/conectamail/lib/Service/SignatureVerifier.php apps/conectamail/tests/Unit/Service/SignatureVerifierTest.php
git commit -m "feat(conectamail): HMAC envelope verifier for Roundcube calendar import"
```

### Task 2: Calendar import service

**Files:**
- Create: `apps/conectamail/lib/Service/CalendarImportService.php`
- Test: `apps/conectamail/tests/Unit/Service/CalendarImportServiceTest.php`

**Interfaces:**
- Consumes: `OCP\IUserManager`, `OCP\Calendar\IManager`.
- Produces: `CalendarImportService::import(string $email, string $ics, string $uid): string` — returns one of `'created'`, `'already_present'`; throws `\OCA\Roundcube\Service\ImportException` when the user or a writable calendar can't be resolved.
- Behaviour: resolve user by email; principal `'principals/users/'.$uid_of_user`; pick the first writable `ICreateFromString` calendar; if a calendar object with `$uid` already exists (via `ICalendar::search`), return `'already_present'` (v1 = no duplicates; true content-update on re-send is deferred to v2); else `createFromStringMinimal` (guarded by `method_exists`, falls back to `createFromString`) with filename `md5($uid).'.ics'`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace OCA\Roundcube\Tests\Unit\Service;
use OCA\Roundcube\Service\CalendarImportService;
use OCA\Roundcube\Service\ImportException;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\IManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class CalendarImportServiceTest extends TestCase {
    private function user(string $uid): IUser {
        $u = $this->createMock(IUser::class);
        $u->method('getUID')->willReturn($uid);
        return $u;
    }
    private function writableCalendar(array $searchResult): object {
        return new class($searchResult) implements ICreateFromString, ICalendarIsWritable {
            public array $created = [];
            public function __construct(private array $searchResult) {}
            public function getKey(): string { return 'personal'; }
            public function getUri(): string { return 'personal'; }
            public function getDisplayName(): ?string { return 'Personal'; }
            public function getDisplayColor(): ?string { return null; }
            public function getPermissions(): int { return 31; }
            public function isWritable(): bool { return true; }
            public function isDeleted(): bool { return false; }
            public function search(string $pattern, array $searchProperties=[], array $options=[], ?int $limit=null, ?int $offset=null): array { return $this->searchResult; }
            public function createFromString(string $name, string $calendarData): void { $this->created[] = [$name,$calendarData]; }
        };
    }

    public function testCreatesWhenAbsent(): void {
        $cal = $this->writableCalendar([]);
        $manager = $this->createMock(IManager::class);
        $manager->method('getCalendarsForPrincipal')->willReturn([$cal]);
        $users = $this->createMock(IUserManager::class);
        $users->method('getByEmail')->willReturn([$this->user('alice')]);
        $svc = new CalendarImportService($users, $manager);
        $this->assertSame('created', $svc->import('a@b.com', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'uid-1'));
        $this->assertCount(1, $cal->created);
        $this->assertSame(md5('uid-1').'.ics', $cal->created[0][0]);
    }

    public function testSkipsWhenUidPresent(): void {
        $cal = $this->writableCalendar([['uri'=>'x.ics']]);
        $manager = $this->createMock(IManager::class);
        $manager->method('getCalendarsForPrincipal')->willReturn([$cal]);
        $users = $this->createMock(IUserManager::class);
        $users->method('getByEmail')->willReturn([$this->user('alice')]);
        $svc = new CalendarImportService($users, $manager);
        $this->assertSame('already_present', $svc->import('a@b.com', 'x', 'uid-1'));
        $this->assertCount(0, $cal->created);
    }

    public function testThrowsWhenNoUser(): void {
        $this->expectException(ImportException::class);
        $manager = $this->createMock(IManager::class);
        $users = $this->createMock(IUserManager::class);
        $users->method('getByEmail')->willReturn([]);
        (new CalendarImportService($users, $manager))->import('a@b.com', 'x', 'uid-1');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/patrickrezende/work/avuz/avuz-server && php vendor/bin/phpunit apps/conectamail/tests/Unit/Service/CalendarImportServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);
namespace OCA\Roundcube\Service;

use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IUserManager;

class ImportException extends \RuntimeException {}

class CalendarImportService {
    public function __construct(
        private IUserManager $userManager,
        private IManager $calendarManager,
    ) {}

    public function import(string $email, string $ics, string $uid): string {
        $users = $this->userManager->getByEmail($email);
        if (count($users) === 0) {
            throw new ImportException('no user for email');
        }
        $uidNc = $users[0]->getUID();
        $calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $uidNc);

        $target = null;
        foreach ($calendars as $calendar) {
            if ($calendar instanceof ICreateFromString
                && $calendar instanceof ICalendarIsWritable
                && $calendar->isWritable() && !$calendar->isDeleted()) {
                $target = $calendar;
                break;
            }
        }
        if ($target === null) {
            throw new ImportException('no writable calendar');
        }

        $existing = $target->search('', [], ['uid' => $uid], 1);
        if (!empty($existing)) {
            return 'already_present';
        }

        $name = md5($uid) . '.ics';
        if (method_exists($target, 'createFromStringMinimal')) {
            $target->createFromStringMinimal($name, $ics);
        } else {
            $target->createFromString($name, $ics);
        }
        return 'created';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/patrickrezende/work/avuz/avuz-server && php vendor/bin/phpunit apps/conectamail/tests/Unit/Service/CalendarImportServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
cd /Users/patrickrezende/work/avuz/avuz-server
git add apps/conectamail/lib/Service/CalendarImportService.php apps/conectamail/tests/Unit/Service/CalendarImportServiceTest.php
git commit -m "feat(conectamail): calendar import service (email->user, dedup by UID, default calendar)"
```

### Task 3: Public HMAC-gated controller + route

**Files:**
- Create: `apps/conectamail/lib/Controller/CalendarApiController.php`
- Modify: `apps/conectamail/appinfo/routes.php`

**Interfaces:**
- Consumes: `SignatureVerifier` (Task 1), `CalendarImportService` (Task 2), `OCP\IConfig`, `OCP\IRequest`.
- Endpoint: `POST /apps/conectamail/api/calendar/events`. Header `X-Avuz-Signature: <envelope>`. Body: raw `.ics`. Query/body `uid`: the VEVENT UID (also present in the signed payload's scope via the ics hash). Response JSON `{status: created|already_present}` (200) or `{error}` (401/404/422).
- Secret resolution mirrors `CredentialService`: `IConfig::getAppValue('conectamail','sso_secret', (string) getenv('ROUNDCUBE_SSO_SECRET'))`.

- [ ] **Step 1: Write the controller**

```php
<?php
declare(strict_types=1);
namespace OCA\Roundcube\Controller;

use OCA\Roundcube\Service\CalendarImportService;
use OCA\Roundcube\Service\ImportException;
use OCA\Roundcube\Service\SignatureException;
use OCA\Roundcube\Service\SignatureVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class CalendarApiController extends Controller {
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private CalendarImportService $importService,
    ) {
        parent::__construct('conectamail', $request);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function import(): JSONResponse {
        $body = file_get_contents('php://input') ?: '';
        $envelope = $this->request->getHeader('X-Avuz-Signature');
        $uid = (string) $this->request->getParam('uid', '');
        if ($uid === '') {
            return new JSONResponse(['error' => 'missing uid'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }
        $secret = $this->config->getAppValue('conectamail', 'sso_secret', (string) getenv('ROUNDCUBE_SSO_SECRET'));
        try {
            $payload = (new SignatureVerifier($secret))->verify($envelope, $body);
        } catch (SignatureException $e) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $status = $this->importService->import($payload['email'], $body, $uid);
        } catch (ImportException $e) {
            return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse(['status' => $status]);
    }
}
```

- [ ] **Step 2: Add the route**

Modify `apps/conectamail/appinfo/routes.php` — add to the `routes` array:

```php
['name' => 'calendar_api#import', 'url' => '/api/calendar/events', 'verb' => 'POST'],
```

- [ ] **Step 3: Manual smoke test (documented; run after Phase 3 deploy)**

Build a signed request against a staging NC instance for a known user email and a minimal `.ics`, expect `{"status":"created"}` then `{"status":"already_present"}` on repeat. (Full script in Task 13.) No unit test here — the controller is a thin adapter over Tasks 1–2, which are unit-tested.

- [ ] **Step 4: Commit**

```bash
cd /Users/patrickrezende/work/avuz/avuz-server
git add apps/conectamail/lib/Controller/CalendarApiController.php apps/conectamail/appinfo/routes.php
git commit -m "feat(conectamail): public HMAC-gated POST /api/calendar/events"
```

---

## PHASE 2 — Roundcube `avuz_calendar` plugin

### Task 4: Plugin scaffold + vendored Sabre/VObject + enablement

**Files:**
- Create: `plugins/avuz_calendar/avuz_calendar.php`, `plugins/avuz_calendar/composer.json`, vendored `plugins/avuz_calendar/vendor/**` (sabre/vobject + its deps).
- Modify: `config/config.inc.php` (plugins array), `Dockerfile` (COPY).

**Interfaces:**
- Produces: class `avuz_calendar extends rcube_plugin` with `init()` registering hooks (filled in later tasks). Autoload of `Sabre\VObject` via the plugin's `vendor/autoload.php`.

- [ ] **Step 1: Vendor Sabre/VObject into the plugin**

```bash
cd /Users/patrickrezende/work/avuz/roundcube-webmail/plugins/avuz_calendar
cat > composer.json <<'JSON'
{ "name": "avuz/avuz_calendar", "require": { "sabre/vobject": "^4.5" }, "config": { "platform": { "php": "8.2" } } }
JSON
composer install --no-dev --optimize-autoloader
```
Expected: `vendor/` created containing `sabre/vobject`. Commit `vendor/` (self-contained; ships via Dockerfile COPY).

- [ ] **Step 2: Write the plugin entry (hooks added in later tasks)**

```php
<?php
class avuz_calendar extends rcube_plugin
{
    public $task = 'mail';

    function init()
    {
        require_once __DIR__ . '/vendor/autoload.php';
        require_once __DIR__ . '/lib/ical_invite.php';
        require_once __DIR__ . '/lib/itip_reply.php';
        require_once __DIR__ . '/lib/nc_calendar_client.php';

        $this->add_hook('message_part_structure', [$this, 'on_part_structure']);
        $this->add_hook('template_object_messagebody', [$this, 'on_message_body']);
        $this->register_action('plugin.avuz_calendar_rsvp', [$this, 'on_rsvp']);
        $this->include_stylesheet('skins/elastic/invite.css');
        $this->include_script('js/invite.js');
    }

    // Implemented in Task 8:
    function on_part_structure($p) { return $p; }
    function on_message_body($p) { return $p; }
    // Implemented in Task 12:
    function on_rsvp() {}
}
```

- [ ] **Step 3: Enable + ship**

Modify `config/config.inc.php` plugins array — add `'avuz_calendar',`.
Modify `Dockerfile` — after the other plugin COPY lines add:
```dockerfile
COPY plugins/avuz_calendar /var/www/roundcube/plugins/avuz_calendar
```

- [ ] **Step 4: Verify it loads (no fatal)**

Run: `cd /Users/patrickrezende/work/avuz/roundcube-webmail && php -r 'require "vendor/autoload.php";' && php -l plugins/avuz_calendar/avuz_calendar.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
cd /Users/patrickrezende/work/avuz/roundcube-webmail
git add plugins/avuz_calendar config/config.inc.php Dockerfile
git commit -m "feat(avuz_calendar): plugin scaffold, vendored Sabre/VObject, enablement"
```

### Task 5: Parse an invite `.ics` into a value object

**Files:**
- Create: `plugins/avuz_calendar/lib/ical_invite.php`
- Test: `plugins/avuz_calendar/tests/IcalInviteTest.php`, fixture `plugins/avuz_calendar/tests/fixtures/google-meet.ics`

**Interfaces:**
- Produces: `avuz_ical_invite::from_ics(string $ics): ?array` — returns `null` if not a `METHOD:REQUEST` VEVENT; else `['uid'=>string,'summary'=>string,'organizer'=>string,'start'=>string,'end'=>string,'location'=>string,'meet_url'=>?string,'is_recurring'=>bool]`. Times are ISO-8601 strings in the event's tz.

- [ ] **Step 1: Add a real Google-Meet fixture**

Create `plugins/avuz_calendar/tests/fixtures/google-meet.ics`:
```
BEGIN:VCALENDAR
PRODID:-//Google Inc//Google Calendar 70.9054//EN
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
DTSTART:20260910T140000Z
DTEND:20260910T143000Z
UID:abc123@google.com
ORGANIZER;CN=Ana:mailto:ana@empresa.com
ATTENDEE;CN=User;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:user@empresa.com
SUMMARY:Reuniao de Vendas
LOCATION:https://meet.google.com/abc-defg-hij
DESCRIPTION:Join: https://meet.google.com/abc-defg-hij
END:VEVENT
END:VCALENDAR
```

- [ ] **Step 2: Write the failing test**

```php
<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/ical_invite.php';

class IcalInviteTest extends TestCase {
    public function testParsesGoogleMeetRequest(): void {
        $ics = file_get_contents(__DIR__ . '/fixtures/google-meet.ics');
        $inv = avuz_ical_invite::from_ics($ics);
        $this->assertSame('abc123@google.com', $inv['uid']);
        $this->assertSame('Reuniao de Vendas', $inv['summary']);
        $this->assertSame('ana@empresa.com', $inv['organizer']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $inv['meet_url']);
        $this->assertFalse($inv['is_recurring']);
    }
    public function testReturnsNullForNonRequest(): void {
        $this->assertNull(avuz_ical_invite::from_ics("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd /Users/patrickrezende/work/avuz/roundcube-webmail && vendor/bin/phpunit plugins/avuz_calendar/tests/IcalInviteTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Write minimal implementation**

```php
<?php
use Sabre\VObject\Reader;

class avuz_ical_invite {
    public static function from_ics(string $ics): ?array {
        try { $vcal = Reader::read($ics); } catch (\Throwable $e) { return null; }
        if (strtoupper((string)($vcal->METHOD ?? '')) !== 'REQUEST' || !isset($vcal->VEVENT)) {
            return null;
        }
        $e = $vcal->VEVENT;
        $org = (string) ($e->ORGANIZER ?? '');
        $loc = (string) ($e->LOCATION ?? '');
        $desc = (string) ($e->DESCRIPTION ?? '');
        return [
            'uid'          => (string) ($e->UID ?? ''),
            'summary'      => (string) ($e->SUMMARY ?? ''),
            'organizer'    => self::strip_mailto($org),
            'start'        => isset($e->DTSTART) ? $e->DTSTART->getDateTime()->format(DATE_ATOM) : '',
            'end'          => isset($e->DTEND) ? $e->DTEND->getDateTime()->format(DATE_ATOM) : '',
            'location'     => $loc,
            'meet_url'     => self::meet_url($loc . ' ' . $desc),
            'is_recurring' => isset($e->RRULE),
        ];
    }
    private static function strip_mailto(string $v): string {
        return preg_replace('/^mailto:/i', '', trim($v));
    }
    private static function meet_url(string $text): ?string {
        return preg_match('~https://meet\.google\.com/[a-z0-9-]+~i', $text, $m) ? $m[0] : null;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd /Users/patrickrezende/work/avuz/roundcube-webmail && vendor/bin/phpunit plugins/avuz_calendar/tests/IcalInviteTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add plugins/avuz_calendar/lib/ical_invite.php plugins/avuz_calendar/tests/
git commit -m "feat(avuz_calendar): parse text/calendar REQUEST into an invite value object"
```

### Task 6: Build the iTip REPLY `.ics`

**Files:**
- Create: `plugins/avuz_calendar/lib/itip_reply.php`
- Test: `plugins/avuz_calendar/tests/ItipReplyTest.php`

**Interfaces:**
- Produces: `avuz_itip_reply::build(string $request_ics, string $attendee_email, string $partstat): string` — returns a `METHOD:REPLY` VCALENDAR string with a single VEVENT carrying the original UID/DTSTART/SEQUENCE and one ATTENDEE line `PARTSTAT=$partstat`. `$partstat` ∈ `ACCEPTED|TENTATIVE|DECLINED`.

- [ ] **Step 1: Write the failing test**

```php
<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/itip_reply.php';

class ItipReplyTest extends TestCase {
    public function testBuildsAcceptedReply(): void {
        $req = file_get_contents(__DIR__ . '/fixtures/google-meet.ics');
        $reply = avuz_itip_reply::build($req, 'user@empresa.com', 'ACCEPTED');
        $this->assertStringContainsString('METHOD:REPLY', $reply);
        $this->assertStringContainsString('UID:abc123@google.com', $reply);
        $this->assertMatchesRegularExpression('/ATTENDEE[^\n]*PARTSTAT=ACCEPTED[^\n]*mailto:user@empresa.com/i', $reply);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/patrickrezende/work/avuz/roundcube-webmail && vendor/bin/phpunit plugins/avuz_calendar/tests/ItipReplyTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

class avuz_itip_reply {
    public static function build(string $request_ics, string $attendee_email, string $partstat): string {
        $src = Reader::read($request_ics);
        $srcEvent = $src->VEVENT;
        $reply = new VCalendar();
        $reply->METHOD = 'REPLY';
        $reply->PRODID = '-//Avuz Conecta//avuz_calendar//EN';
        $ev = $reply->add('VEVENT', [
            'UID'      => (string) $srcEvent->UID,
            'SEQUENCE' => (string) ($srcEvent->SEQUENCE ?? '0'),
        ]);
        if (isset($srcEvent->DTSTART)) { $ev->add('DTSTART', $srcEvent->DTSTART->getValue()); }
        if (isset($srcEvent->ORGANIZER)) { $ev->add('ORGANIZER', (string) $srcEvent->ORGANIZER); }
        $ev->add('ATTENDEE', 'mailto:' . $attendee_email, ['PARTSTAT' => $partstat]);
        return $reply->serialize();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/patrickrezende/work/avuz/roundcube-webmail && vendor/bin/phpunit plugins/avuz_calendar/tests/ItipReplyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugins/avuz_calendar/lib/itip_reply.php plugins/avuz_calendar/tests/ItipReplyTest.php
git commit -m "feat(avuz_calendar): build iTip REPLY .ics with attendee PARTSTAT"
```

### Task 7: NC instance resolution + signed client

**Files:**
- Create: `plugins/avuz_calendar/lib/nc_calendar_client.php`
- Test: `plugins/avuz_calendar/tests/NcCalendarClientTest.php`
- Modify: `config/config.inc.php` (parse `AVUZ_NC_INSTANCES`)

**Interfaces:**
- Produces:
  - `avuz_nc_client::resolve_base(array $instances, string $email): ?string` — domain lookup, `null` if unknown.
  - `avuz_nc_client::sign(string $ics, string $email, string $secret, int $now): string` — the envelope `base64url(json).sig` (matches Task 1 verifier).
- The actual HTTP POST is a thin method `avuz_nc_client::post(string $base, string $ics, string $uid, string $envelope): array` (tested via a fake in Task 12; not unit-tested for network here).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/patrickrezende/work/avuz/roundcube-webmail && vendor/bin/phpunit plugins/avuz_calendar/tests/NcCalendarClientTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/patrickrezende/work/avuz/roundcube-webmail && vendor/bin/phpunit plugins/avuz_calendar/tests/NcCalendarClientTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Add config parse**

Modify `config/config.inc.php` — near the other AVUZ blocks:
```php
// email-domain -> AvuzConecta (Nextcloud) base URL, for calendar invite import
$avuz_nc = getenv('AVUZ_NC_INSTANCES');
$config['avuz_nc_instances'] = $avuz_nc ? (array) json_decode($avuz_nc, true) : [];
```

- [ ] **Step 6: Commit**

```bash
git add plugins/avuz_calendar/lib/nc_calendar_client.php plugins/avuz_calendar/tests/NcCalendarClientTest.php config/config.inc.php
git commit -m "feat(avuz_calendar): NC instance resolution + signed calendar-import client"
```

### Task 8: Detect the invite + render the card

**Files:**
- Modify: `plugins/avuz_calendar/avuz_calendar.php` (`on_part_structure`, `on_message_body`)
- Create: `plugins/avuz_calendar/skins/elastic/invite.css`, `plugins/avuz_calendar/js/invite.js`
- Create: `plugins/avuz_calendar/localization/en_US.inc`, `.../pt_BR.inc`

**Interfaces:**
- Consumes: `avuz_ical_invite::from_ics` (Task 5); `on_rsvp` action (Task 12).
- Produces: when the opened message has a `text/calendar` part with `method=REQUEST`, an invite card is injected above the body with buttons wired to `rcmail.command('plugin.avuz_calendar_rsvp', {uid, mbox, part, partstat})` and the invite fields rendered.

- [ ] **Step 1: Implement detection + card injection**

In `avuz_calendar.php` replace the stubs:
```php
    function on_part_structure($p) {
        if (stripos((string)($p['structure']->mimetype ?? ''), 'text/calendar') !== false) {
            $this->has_calendar_part = true;
        }
        return $p;
    }

    function on_message_body($p) {
        if (empty($this->has_calendar_part)) { return $p; }
        $rcmail = rcmail::get_instance();
        $uid  = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_GET);
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_GET);
        $message = new rcube_message($uid, $mbox);
        foreach ($message->attachments as $part) {
            if (stripos((string) $part->mimetype, 'text/calendar') === false) { continue; }
            $ics = $message->get_part_body($part->mime_id, true);
            $inv = avuz_ical_invite::from_ics($ics);
            if (!$inv) { continue; }
            $this->add_texts('localization/');
            $rcmail->output->set_env('avuz_calendar_invite', [
                'uid' => $inv['uid'], 'mbox' => $mbox, 'msg_uid' => $uid, 'part' => $part->mime_id,
            ]);
            $p['content'] = $this->render_card($inv) . $p['content'];
            break;
        }
        return $p;
    }

    private function render_card(array $inv): string {
        $rc = rcmail::get_instance();
        $esc = fn($s) => rcube::Q((string) $s);
        $meet = $inv['meet_url'] ? '<a href="' . $esc($inv['meet_url']) . '" target="_blank">' . $esc($inv['meet_url']) . '</a>' : '';
        $btn = fn($k,$l) => '<button class="btn-avuz-rsvp" data-partstat="' . $k . '">' . $esc($rc->gettext($l)) . '</button>';
        return '<div id="avuz-invite-card" class="avuz-invite">'
            . '<div class="avuz-invite-title">' . $esc($inv['summary']) . '</div>'
            . '<div class="avuz-invite-meta">' . $esc($inv['start']) . ' — ' . $esc($inv['organizer']) . '</div>'
            . ($meet ? '<div class="avuz-invite-meet">' . $meet . '</div>' : '')
            . '<div class="avuz-invite-actions">' . $btn('ACCEPTED','accept') . $btn('TENTATIVE','tentative') . $btn('DECLINED','decline') . '</div>'
            . '<div class="avuz-invite-status" hidden></div></div>';
    }
```
Add property `public $has_calendar_part = false;` to the class.

- [ ] **Step 2: JS — wire the buttons**

`plugins/avuz_calendar/js/invite.js`:
```js
window.rcmail && rcmail.addEventListener('init', function () {
  var inv = rcmail.env.avuz_calendar_invite;
  if (!inv) return;
  document.querySelectorAll('#avuz-invite-card .btn-avuz-rsvp').forEach(function (b) {
    b.addEventListener('click', function () {
      var status = document.querySelector('#avuz-invite-card .avuz-invite-status');
      status.hidden = false; status.textContent = rcmail.get_label('avuz_calendar.sending');
      rcmail.http_post('plugin.avuz_calendar_rsvp', {
        _uid: inv.msg_uid, _mbox: inv.mbox, _part: inv.part, _partstat: b.dataset.partstat
      }, rcmail.set_busy(true, 'loading'));
    });
  });
  rcmail.addEventListener('plugin.avuz_calendar_rsvp_done', function (r) {
    var status = document.querySelector('#avuz-invite-card .avuz-invite-status');
    if (status) status.textContent = r.message;
  });
});
```

- [ ] **Step 3: CSS + localization**

`skins/elastic/invite.css`: a bordered card, buttons spaced (keep minimal — brand cyan `#2bb5e3` on the accept button). `localization/en_US.inc` and `pt_BR.inc` with keys `accept/tentative/decline/sending/added/reply_sent/add_failed`. (pt_BR: `Aceitar/Talvez/Recusar/Enviando…/Adicionado ao calendário/Resposta enviada/Não foi possível adicionar ao calendário`.)

- [ ] **Step 4: Manual verify on staging (after Task 12 + deploy).** No unit test — this is view wiring, covered end-to-end in Task 13.

- [ ] **Step 5: Commit**

```bash
git add plugins/avuz_calendar
git commit -m "feat(avuz_calendar): detect invite part and render RSVP card"
```

### Task 9 (was 12): RSVP action — send reply + add to calendar

**Files:**
- Modify: `plugins/avuz_calendar/avuz_calendar.php` (`on_rsvp`)

**Interfaces:**
- Consumes: `avuz_itip_reply::build` (Task 6), `avuz_nc_client` (Task 7), `rcmail_sendmail`, `avuz_ical_invite::from_ics` (for UID + organizer).
- Behaviour: load the message part `.ics`; build the REPLY; send it to the organizer over SMTP; on ACCEPTED/TENTATIVE resolve the NC instance and POST the signed original `.ics`; return a combined status message via `plugin.avuz_calendar_rsvp_done`.

- [ ] **Step 1: Implement the action**

```php
    function on_rsvp() {
        $rcmail = rcmail::get_instance();
        $this->add_texts('localization/');
        $uid  = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_POST);
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST);
        $part = rcube_utils::get_input_string('_part', rcube_utils::INPUT_POST);
        $partstat = rcube_utils::get_input_string('_partstat', rcube_utils::INPUT_POST);
        $allowed = ['ACCEPTED','TENTATIVE','DECLINED'];
        if (!in_array($partstat, $allowed, true)) { return; }

        $message = new rcube_message($uid, $mbox);
        $ics = $message->get_part_body($part, true);
        $inv = avuz_ical_invite::from_ics($ics);
        if (!$inv) { $this->reply_done($rcmail, $this->gettext('add_failed')); return; }

        $me = $rcmail->get_user_email();
        $messages = [];

        // 1) iTip REPLY over SMTP
        $reply_ok = $this->send_reply($rcmail, $inv['organizer'], $me, $ics, $partstat);
        $messages[] = $reply_ok ? $this->gettext('reply_sent') : $this->gettext('add_failed');

        // 2) calendar add (accept/tentative only)
        if ($partstat !== 'DECLINED') {
            $instances = (array) $rcmail->config->get('avuz_nc_instances', []);
            $base = avuz_nc_client::resolve_base($instances, $me);
            if ($base) {
                $secret = (string) getenv('ROUNDCUBE_SSO_SECRET');
                $env = avuz_nc_client::sign($ics, $me, $secret, time());
                $res = avuz_nc_client::post($base, $ics, $inv['uid'], $env);
                $messages[] = $res['ok'] ? $this->gettext('added') : $this->gettext('add_failed');
            }
        }
        $this->reply_done($rcmail, implode(' · ', $messages));
    }

    private function send_reply($rcmail, string $organizer, string $me, string $request_ics, string $partstat): bool {
        try {
            $reply_ics = avuz_itip_reply::build($request_ics, $me, $partstat);
            $headers = [
                'From' => $me, 'To' => $organizer,
                'Subject' => $this->gettext('reply_subject'),
                'Content-Type' => 'text/calendar; method=REPLY; charset=UTF-8',
            ];
            $mime = new Mail_mime(["eol" => "\r\n"]);
            $mime->headers($headers);
            $mime->setTXTBody($reply_ics);
            $send = new rcmail_sendmail(['sendmail' => false]);
            return (bool) $rcmail->deliver_message($mime, $me, $organizer, $err, $body, null, false);
        } catch (\Throwable $e) {
            rcube::write_log('errors', 'avuz_calendar reply failed: ' . $e->getMessage());
            return false;
        }
    }

    private function reply_done($rcmail, string $message): void {
        $rcmail->output->command('plugin.avuz_calendar_rsvp_done', ['message' => $message]);
        $rcmail->output->send();
    }
```
Note for the implementer: confirm the exact `rcube_imap::deliver_message`/`rcmail::deliver_message` signature in this fork (`grep -n "function deliver_message" program/include/rcmail.php`) and adapt the send call; the REPLY is a `text/calendar; method=REPLY` body to the organizer.

- [ ] **Step 2: Manual verify (staging, Task 13).** The pure units (parse, reply-build, sign, resolve) are already tested; this action is their composition + SMTP, verified end-to-end.

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_calendar/avuz_calendar.php
git commit -m "feat(avuz_calendar): RSVP action — iTip reply over SMTP + signed calendar add"
```

---

## PHASE 3 — Integration & rollout

### Task 10: End-to-end on staging

**Files:** none (verification + config).

- [ ] **Step 1: Configure staging**
  - Set `AVUZ_NC_INSTANCES` on the staging Roundcube stack: `{"<staging-test-domain>":"https://<staging-nc-subdomain>"}`.
  - Confirm the staging NC instance has `conectamail` `sso_secret` == the Roundcube `ROUNDCUBE_SSO_SECRET`.
  - Build+deploy both images to staging (Roundcube: `build-push.sh latest staging` + `deploy.sh -y avuz-mail-roundcube-2`; NC: its own image build+deploy).

- [ ] **Step 2: Endpoint smoke test (signed curl)**
```bash
ICS=$'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nUID:smoke-1\r\nDTSTART:20260910T140000Z\r\nSUMMARY:Smoke\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
SECRET='<sso_secret>'; EMAIL='<staging-user-email>'
PL=$(printf '{"email":"%s","iat":%d,"ics_sha256":"%s"}' "$EMAIL" "$(date +%s)" "$(printf '%s' "$ICS" | shasum -a256 | cut -d' ' -f1)" | openssl base64 -A | tr '+/' '-_' | tr -d '=')
SIG=$(printf '%s' "$PL" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')
curl -s -X POST "https://<staging-nc>/apps/conectamail/api/calendar/events?uid=smoke-1" -H "X-Avuz-Signature: $PL.$SIG" --data-binary "$ICS"
```
Expected: `{"status":"created"}`, then `{"status":"already_present"}` on repeat; the event visible in that user's NC Calendar.

- [ ] **Step 3: Real invite test (in-iframe AND standalone)**
  - Send a Google Meet invite to the staging test account; open it in Roundcube; the card shows; click **Aceitar** → organizer receives the RSVP, event lands in the AvuzConecta calendar.
  - Repeat with Roundcube opened directly (not inside the NC iframe) → same result (proves instance resolution without the parent window).
  - Send an updated invite (same UID) → no duplicate in the calendar.

- [ ] **Step 4: Ship to prod** (after staging sign-off): build+deploy both images, set `AVUZ_NC_INSTANCES` for the real customer domains on prod.

---

## Self-review notes
- Spec coverage: detection (T5/T8), RSVP reply over SMTP (T6/T9), signed calendar add (T7/T9), instance map (T7), HMAC + session-email-only + replay window + write-own-calendar (T1/T2/T3), no-duplicate UID (T2), recurrence pass-through (T5 keeps raw VEVENT; NC stores it; T2 writes the raw ics). All covered.
- Deviation from spec: spec said "upsert (update on re-send)"; T2 delivers **no-duplicate** (skip if UID present) using public API only — true content-update on re-send is deferred to v2 (documented in T2). Confirm this is acceptable at review; if update-in-place is required, add a v2 task using the DAV backend delete+create.
- Open confirmations folded into steps: `deliver_message` signature (T9 step 1 note), `createFromStringMinimal` availability (guarded in T2), route URL base (`/apps/conectamail/...`, T3/T10).
