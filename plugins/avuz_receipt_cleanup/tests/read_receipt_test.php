<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/read_receipt.php';

class read_receipt_test extends TestCase
{
    private function text_part(): array
    {
        return ['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, 'QUOTED-PRINTABLE', '312', '9', null, null, null];
    }

    private function mdn_part(): array
    {
        return ['MESSAGE', 'DISPOSITION-NOTIFICATION', ['NAME', 'MDNPart2.txt'], null, null, '7BIT', '180', null, null, null];
    }

    private function multipart(string $subtype, ?array $params): array
    {
        return [$this->text_part(), $this->mdn_part(), $subtype, $params, null, null, null];
    }

    function testRecognizesRoundcubeReadReceipt()
    {
        $structure = $this->multipart('REPORT', ['REPORT-TYPE', 'disposition-notification', 'BOUNDARY', '=_abc']);
        $this->assertTrue(avuz_read_receipt::is_read_receipt($structure));
    }

    function testIgnoresParameterCase()
    {
        $structure = $this->multipart('report', ['report-type', 'Disposition-Notification']);
        $this->assertTrue(avuz_read_receipt::is_read_receipt($structure));
    }

    function testFindsReportTypeAfterOtherParameters()
    {
        $structure = $this->multipart('REPORT', ['BOUNDARY', '=_abc', 'REPORT-TYPE', 'disposition-notification']);
        $this->assertTrue(avuz_read_receipt::is_read_receipt($structure));
    }

    function testRejectsDeliveryStatusReport()
    {
        $structure = $this->multipart('REPORT', ['REPORT-TYPE', 'delivery-status', 'BOUNDARY', '=_abc']);
        $this->assertFalse(avuz_read_receipt::is_read_receipt($structure));
    }

    function testRejectsReportWithoutReportType()
    {
        $this->assertFalse(avuz_read_receipt::is_read_receipt($this->multipart('REPORT', ['BOUNDARY', '=_abc'])));
        $this->assertFalse(avuz_read_receipt::is_read_receipt($this->multipart('REPORT', null)));
    }

    function testRejectsOrdinaryMultipartMail()
    {
        $structure = $this->multipart('MIXED', ['BOUNDARY', '=_abc']);
        $this->assertFalse(avuz_read_receipt::is_read_receipt($structure));
    }

    function testRejectsSinglePartMail()
    {
        $this->assertFalse(avuz_read_receipt::is_read_receipt($this->text_part()));
    }

    function testRejectsMissingStructure()
    {
        $this->assertFalse(avuz_read_receipt::is_read_receipt(null));
        $this->assertFalse(avuz_read_receipt::is_read_receipt([]));
    }

    function testFlagsOnlyMultipartReportAsCandidate()
    {
        $this->assertTrue(avuz_read_receipt::is_candidate('multipart/report'));
        $this->assertTrue(avuz_read_receipt::is_candidate('Multipart/Report'));
        $this->assertFalse(avuz_read_receipt::is_candidate('multipart/mixed'));
        $this->assertFalse(avuz_read_receipt::is_candidate('text/plain'));
        $this->assertFalse(avuz_read_receipt::is_candidate(''));
    }
}
