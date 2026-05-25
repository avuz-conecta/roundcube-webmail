<?php
// Test configuration — overrides production config for local phpunit runs
$config['db_dsnw'] = 'sqlite:///' . realpath(__DIR__ . '/../../temp') . '/test.db';
