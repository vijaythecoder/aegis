<?php

namespace AegisCalculator;

use App\Plugins\PluginServiceProvider;

class CalculatorServiceProvider extends PluginServiceProvider
{
    public function pluginName(): string
    {
        return 'aegis-calculator';
    }

    public function boot(): void
    {
        $this->registerTool(CalculatorTool::class);
    }
}
