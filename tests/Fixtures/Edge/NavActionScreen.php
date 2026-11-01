<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;

class NavActionScreen extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Nav Actions';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()
            ->action(NavAction::make('share')->icon('share')->a11yLabel('Share')->press('share'))
            ->action(
                NavAction::make('more')->icon('ellipsis')->a11yLabel('More')->items([
                    NavAction::make('mute')->label('Mute')->press('mute'),
                    NavAction::divider(),
                    NavAction::make('delete')->label('Delete')->press('delete')->destructive(),
                ])
            );
    }

    /** @return list<NavAction> */
    public function rowMenu(): array
    {
        return [
            NavAction::make('pin')->label('Pin')->press('pin'),
            NavAction::make('archive')->label('Archive')->press('archive'),
        ];
    }

    public function render(): View
    {
        return view('nav-action-screen');
    }
}
