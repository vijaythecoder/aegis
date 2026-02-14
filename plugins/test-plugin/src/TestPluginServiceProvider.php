<?php

namespace TestPlugin;

use App\Plugins\PluginServiceProvider;

class TestPluginServiceProvider extends PluginServiceProvider
{
    public function pluginName(): string
    {
        return 'TestPlugin';
    }
}
