<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // 生产环境入口会执行 config:cache，缓存后的配置不会读取
        // phpunit.xml 中的 <env> 变量。这里在测试环境下强制把 MySQL
        // 指向独立的测试库，防止 migrate:fresh 清空开发数据。
        if ($app->environment('testing') && $app['config']->get('database.default') === 'mysql') {
            $app['config']->set('database.connections.mysql.database', env('DB_DATABASE', 'movies_test'));
        }

        return $app;
    }
}
