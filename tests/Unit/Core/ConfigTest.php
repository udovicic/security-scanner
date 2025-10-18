<?php

namespace Tests\Unit\Core;

use Tests\TestCase;
use SecurityScanner\Core\Config;

class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = Config::getInstance();
    }

    public function test_config_is_singleton(): void
    {
        $config1 = Config::getInstance();
        $config2 = Config::getInstance();

        $this->assertSame($config1, $config2);
    }

    public function test_can_get_configuration_value(): void
    {
        $this->config->set('test.key', 'test_value');

        $value = $this->config->get('test.key');

        $this->assertEquals('test_value', $value);
    }

    public function test_can_get_configuration_with_default(): void
    {
        $value = $this->config->get('non.existent.key', 'default_value');

        $this->assertEquals('default_value', $value);
    }

    public function test_can_set_nested_configuration(): void
    {
        $this->config->set('app.database.host', 'localhost');

        $value = $this->config->get('app.database.host');

        $this->assertEquals('localhost', $value);
    }

    public function test_can_check_if_configuration_exists(): void
    {
        $this->config->set('existing.key', 'value');

        $this->assertTrue($this->config->has('existing.key'));
        $this->assertFalse($this->config->has('non.existing.key'));
    }

    public function test_environment_is_set_to_testing(): void
    {
        $environment = $this->config->get('app.environment');

        $this->assertEquals('testing', $environment);
    }

    public function test_debug_mode_can_be_checked(): void
    {
        $this->config->set('app.debug', true);

        $this->assertTrue($this->config->isDebug());

        $this->config->set('app.debug', false);

        $this->assertFalse($this->config->isDebug());
    }

    public function test_can_get_all_configurations(): void
    {
        $this->config->set('test1', 'value1');
        $this->config->set('test2', 'value2');

        $all = $this->config->all();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('test1', $all);
        $this->assertArrayHasKey('test2', $all);
    }
}