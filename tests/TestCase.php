<?php

namespace ZohoMail\LaravelZeptoMail\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ZohoMail\LaravelZeptoMail\LaravelDriverServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [LaravelDriverServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('mail.default', 'zeptomail');
        $app['config']->set('mail.mailers.zeptomail', ['transport' => 'zeptomail']);
        $app['config']->set('zeptomail-driver.api_key', 'Zoho-enczapikey test-token');
        $app['config']->set('zeptomail-driver.host', 'zoho.com');
    }
}
