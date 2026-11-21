<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;

/**
 * A Notes-style nav-bar menu: a header row, a titled section holding a
 * submenu (which has a row of its own), and an untitled section.
 */
class NavMenuScreen extends NativeComponent
{
    public string $display = 'list';

    public function navTitle(): string
    {
        return 'Notes';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->action(
            NavAction::make('more')->icon('ellipsis')->a11yLabel('More')->items([
                NavAction::row([
                    NavAction::make('scan')->label('Scan')->icon('doc.viewfinder')->press('scan'),
                    NavAction::make('pin')->label('Pin')->icon('pin')->press('pin'),
                    NavAction::make('lock')->label('Lock')->icon('lock')->press('lock'),
                ]),
                NavAction::section('View', [
                    NavAction::make('view_as')->label('View as')->subtitle('Gallery')->icon('square.grid.2x2')->items([
                        NavAction::row([
                            NavAction::make('zoom_in')->label('Bigger')->icon('plus.magnifyingglass')->press('zoomIn'),
                            NavAction::make('zoom_out')->label('Smaller')->icon('minus.magnifyingglass')->press('zoomOut'),
                        ]),
                        NavAction::make('gallery')->label('Gallery')->checked($this->display === 'gallery')->press("viewAs('gallery')"),
                        NavAction::make('list')->label('List')->checked($this->display === 'list')->press("viewAs('list')"),
                    ]),
                ]),
                NavAction::section(items: [
                    NavAction::make('delete')->label('Delete')->icon('trash')->destructive()->press('delete'),
                ]),
            ])
        );
    }

    public function viewAs(string $view): void
    {
        $this->display = $view;
    }

    public function render(): View
    {
        return view('nav-menu-screen');
    }
}
