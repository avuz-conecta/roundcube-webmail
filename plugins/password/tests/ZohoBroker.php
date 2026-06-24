<?php

class ZohoBroker_Plugin extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('PASSWORD_SUCCESS')) {
            define('PASSWORD_SUCCESS', 0);
            define('PASSWORD_ERROR', 1);
            define('PASSWORD_CONNECT_ERROR', 2);
        }
        include_once __DIR__ . '/../drivers/zoho_broker.php';
    }

    public function test_maps_200_to_success()
    {
        $this->assertSame(PASSWORD_SUCCESS, rcube_zoho_broker_password::map_status(200));
    }

    public function test_maps_403_to_error()
    {
        $this->assertSame(PASSWORD_ERROR, rcube_zoho_broker_password::map_status(403));
    }

    public function test_maps_502_to_connect_error()
    {
        $this->assertSame(PASSWORD_CONNECT_ERROR, rcube_zoho_broker_password::map_status(502));
    }

    public function test_maps_unknown_to_error()
    {
        $this->assertSame(PASSWORD_ERROR, rcube_zoho_broker_password::map_status(418));
    }
}
