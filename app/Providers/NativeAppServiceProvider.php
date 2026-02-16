<?php

namespace App\Providers;

use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\ChildProcess;
use Native\Laravel\Facades\Menu;
use Native\Laravel\Facades\MenuBar;
use Native\Laravel\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
        Menu::create(
            Menu::app(),
            Menu::label('File')->submenu(
                Menu::route('home', 'New Chat', 'CmdOrCtrl+N'),
                Menu::separator(),
                Menu::quit(),
            ),
            Menu::edit(),
            Menu::view(),
            Menu::label('Help')->submenu(
                Menu::link('https://github.com/AegisApp', 'Documentation'),
                Menu::separator(),
                Menu::link('https://github.com/AegisApp', 'About Aegis'),
            ),
        );

        Window::open('main')
            ->width(1200)
            ->height(800)
            ->minWidth(800)
            ->minHeight(600)
            ->title('Aegis')
            ->rememberState()
            ->route('home');

        MenuBar::create()
            ->showDockIcon()
            ->withContextMenu(
                Menu::make(
                    Menu::route('home', 'Open'),
                    Menu::route('home', 'Settings'),
                    Menu::separator(),
                    Menu::quit('Quit'),
                )
            );

        ChildProcess::artisan(
            ['schedule:work'],
            'scheduler',
            persistent: true,
        );
    }

    public function phpIni(): array
    {
        return [];
    }
}
