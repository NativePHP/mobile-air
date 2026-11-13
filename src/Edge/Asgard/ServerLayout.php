<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * The layout a ServerScreen wears: built from the chrome its server sent
 * (docs/ASGARD-PROTOCOL.md §12, "Chrome") rather than from a class in the
 * app. It feeds the same NavBar and TabBar builders an app layout does,
 * so everything after this, native chrome or drawn, is the code every
 * other screen goes through.
 *
 * Every button's press is a fragment callback expression, and every
 * element that carries one is pinned to the screen region's registry.
 * A chrome tap therefore resolves and dispatches exactly like a tap in
 * the screen's body: a server action, a #[NativeAction], or a checked
 * navigation. Link tabs navigate the same way, with replace, as an app's
 * tab does.
 */
final class ServerLayout extends NativeLayout
{
    /**
     * @param  array<string, mixed>  $chrome  Checked by ServerChrome::parse().
     * @param  array{title_view?: Element, bottom_bar?: Element, tab_accessory?: Element}  $content  Painted, pinned content slots.
     */
    public function __construct(
        private readonly array $chrome,
        private readonly FragmentCallbacks $callbacks,
        private readonly array $content = [],
    ) {
        $this->font = $chrome['font'] ?? null;
    }

    public function usesNativeChrome(): bool
    {
        return $this->chrome['native'] ?? false;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        $config = $this->chrome['nav_bar'] ?? null;

        if ($config === null) {
            return null;
        }

        $bar = NavBar::make()
            ->title($config['title'] ?? null)
            ->subtitle($config['subtitle'] ?? null)
            ->back($config['back'] ?? false);

        foreach (['background_color' => 'backgroundColor', 'text_color' => 'textColor', 'font' => 'font',
            'elevation' => 'elevation', 'display_mode' => 'displayMode', 'scroll_behavior' => 'scrollBehavior'] as $key => $method) {
            if (isset($config[$key])) {
                $bar->{$method}($config[$key]);
            }
        }

        if (isset($this->content['title_view'])) {
            $bar->titleView($this->content['title_view']);
        } elseif (isset($config['logo'])) {
            $bar->logo($config['logo']['src'], $config['logo']['height']);
        }

        if (isset($config['search'])) {
            $bar->searchBar(
                $config['search']['placeholder'] ?? '',
                isset($config['search']['on_query']) ? ServerScreen::SEARCH_METHOD : null,
                $config['search']['debounce_ms'] ?? 300,
            );
        }

        foreach ($config['actions'] ?? [] as $action) {
            $bar->actionElement($this->action($action)->toElement()->ownCallbacks($this->callbacks));
        }

        return $bar;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        $config = $this->chrome['tab_bar'] ?? null;

        if ($config === null) {
            return null;
        }

        $bar = TabBar::make();

        foreach (['active_color' => 'activeColor', 'background_color' => 'backgroundColor', 'text_color' => 'textColor',
            'font' => 'font', 'label_visibility' => 'labelVisibility'] as $key => $method) {
            if (isset($config[$key])) {
                $bar->{$method}($config[$key]);
            }
        }

        if ($config['dark'] ?? false) {
            $bar->dark();
        }
        if ($config['minimize_on_scroll'] ?? false) {
            $bar->minimizeOnScroll();
        }
        if (isset($config['presentation'])) {
            $bar->presentation($config['presentation']['id'], $config['presentation']['configuration']);
        }

        foreach ($config['tabs'] ?? [] as $tab) {
            $bar->tabElement($this->tab($tab)->toElement()->ownCallbacks($this->callbacks));
        }

        return $bar;
    }

    public function bottomBar(NativeComponent $screen): ?Element
    {
        return $this->content['bottom_bar'] ?? null;
    }

    public function tabBarAccessory(NativeComponent $screen): ?Element
    {
        return $this->content['tab_accessory'] ?? null;
    }

    private function action(array $config): NavAction
    {
        if ($config['divider'] ?? false) {
            return NavAction::divider();
        }

        $action = NavAction::make($config['id'])
            ->icon($config['icon'] ?? null, $config['ios_icon'] ?? null, $config['android_icon'] ?? null)
            ->image($config['image'] ?? null)
            ->destructive($config['destructive'] ?? false)
            ->disabled($config['disabled'] ?? false);

        if (isset($config['label'])) {
            $action->label($config['label']);
        }
        if (isset($config['a11y_label'])) {
            $action->a11yLabel($config['a11y_label']);
        }
        if (isset($config['on_press'])) {
            $action->press(FragmentCallbacks::expressionFor($config['on_press']));
        }
        if (isset($config['items'])) {
            $action->items(array_map(fn (array $item) => $this->action($item), $config['items']));
        }

        return $action;
    }

    private function tab(array $config): Tab
    {
        $icon = [$config['icon'] ?? null, $config['ios_icon'] ?? null, $config['android_icon'] ?? null];

        $tab = match (true) {
            isset($config['search']) => Tab::search($config['label'], $icon[0], $config['search']['placeholder'] ?? null, $config['search']['debounce_ms'] ?? 250, $icon[1], $icon[2]),
            isset($config['url']) => Tab::link($config['label'], $config['url'], ...$icon),
            default => Tab::action($config['label'], ...$icon),
        };

        $tab->id($config['id']);

        if (isset($config['badge'])) {
            $tab->badge($config['badge'], $config['badge_color'] ?? null);
        }
        if ($config['news'] ?? false) {
            $tab->news();
        }
        if ($config['active'] ?? false) {
            $tab->active();
        }

        // A link tab opens its route the way an app's tab does, with replace,
        // but through the checked navigation path rather than the screen's own
        // navigation registry, so the route must exist when it is tapped.
        $press = $config['on_press']
            ?? (isset($config['url']) ? FragmentCallbacks::parseSlot(['navigate' => $config['url'], 'replace' => true]) : null);

        if ($press !== null) {
            $tab->press(FragmentCallbacks::expressionFor($press));
        }

        return $tab;
    }
}
