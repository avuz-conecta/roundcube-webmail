<?php

class body_cache_test extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        include_once __DIR__ . '/../lib/body_cache.php';
    }

    public function test_user_tag_is_stable_16_hex()
    {
        $a = avuz_body_cache_lib::user_tag('user@zoho.com', 'k' . str_repeat('x', 23));
        $b = avuz_body_cache_lib::user_tag('user@zoho.com', 'k' . str_repeat('x', 23));
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a);
    }

    public function test_user_tag_differs_per_user_and_key()
    {
        $key = 'k' . str_repeat('x', 23);
        $this->assertNotSame(
            avuz_body_cache_lib::user_tag('a@zoho.com', $key),
            avuz_body_cache_lib::user_tag('b@zoho.com', $key)
        );
        $this->assertNotSame(
            avuz_body_cache_lib::user_tag('a@zoho.com', $key),
            avuz_body_cache_lib::user_tag('a@zoho.com', 'other-key-000000000000000')
        );
    }
}
