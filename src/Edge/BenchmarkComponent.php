<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Divider;
use Native\Mobile\Edge\Elements\Icon;
use Native\Mobile\Edge\Elements\ListItem;
use Native\Mobile\Edge\Elements\Row;
use Native\Mobile\Edge\Elements\ScrollView;
use Native\Mobile\Edge\Elements\Spacer;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\Elements\Toggle;

class BenchmarkComponent extends NativeComponent
{
    protected array $results = [];

    protected bool $benchmarkDone = false;

    protected string $pipeline = 'ELEMENT';

    /** Counter for interactive benchmarks */
    protected int $counter = 0;

    /** Number of interactions completed in current scenario */
    protected int $interactionCount = 0;

    /** Text input value for text input benchmark */
    protected string $textValue = '';

    /** Toggle state for toggle tree benchmark */
    protected bool $toggleState = false;

    /** Current phase: menu, running, scenario-specific phases, results */
    protected string $phase = 'menu';

    /** Which scenario is currently running (for display) */
    protected string $currentScenario = '';

    /** Queue of scenarios to run (for "Run All") */
    protected array $scenarioQueue = [];

    const SIZES = [10, 50, 100, 500];
    const ITERATIONS = 100;
    const WARMUP = 10;
    const TAP_ITERATIONS = 100;
    const LARGE_TREE_TAP_ITERATIONS = 100;
    const TEXT_INPUT_ITERATIONS = 50;
    const RAPID_FIRE_ITERATIONS = 500;
    const NAVIGATION_ITERATIONS = 20;
    const TOGGLE_TREE_ITERATIONS = 50;
    const LARGE_TREE_NODE_COUNT = 200;
    const LIST_SCROLL_ITEM_COUNT = 1000;

    const SCENARIOS = [
        'counter_tap'   => 'Counter Tap',
        'large_tree_tap' => 'Large Tree Tap',
        'text_input'    => 'Text Input',
        'list_scroll'   => 'Large List Render',
        'rapid_fire'    => 'Rapid-Fire',
        'navigation'    => 'Navigation',
        'toggle_tree'   => 'Toggle Tree',
        'render'        => 'PHP Render',
    ];

    public function render(): Element
    {
        return match ($this->phase) {
            'menu' => $this->renderMenu(),
            'running' => $this->renderRunningScreen(),
            'counter_tap' => $this->renderCounterScreen(),
            'large_tree_tap' => $this->renderLargeTreeTapScreen(),
            'text_input' => $this->renderTextInputScreen(),
            'toggle_tree' => $this->renderToggleTreeScreen(),
            'results' => $this->renderResults(),
            default => $this->renderMenu(),
        };
    }

    // ── Menu Screen ─────────────────────────────────

    protected function renderMenu(): Element
    {
        $scroll = ScrollView::make()->fill()->safeArea()->bg('#0F172A');
        $content = Column::make()->fillWidth()->padding(20, 16, 40, 16)->gap(10);

        $content->addChild(
            Row::make(
                Text::make('BENCHMARK')->fontSize(13)->fontWeight(7)->color('#38BDF8'),
                Spacer::make()->flexGrow(1),
                Text::make('EDGE v1.0')->fontSize(13)->fontWeight(5)->color('#475569'),
            )->fillWidth()
        );
        $content->addChild(
            Text::make('NativePHP Performance Suite')->fontSize(26)->fontWeight(7)->color('#F1F5F9')
        );
        $content->addChild(
            Text::make(count(self::SCENARIOS) . ' scenarios available')->fontSize(15)->color('#94A3B8')
        );
        $content->addChild(Spacer::make()->height(4));

        foreach (self::SCENARIOS as $key => $label) {

            $content->addChild(
                Button::make($label)->onPress("startScenario('{$key}')")->fillWidth()
                    ->color('#1E293B')->labelColor('#E2E8F0')
            );
        }

        $content->addChild(Spacer::make()->height(4));
        $content->addChild(
            Button::make('Run All Scenarios')->onPress('startAll')->fillWidth()
                ->color('#059669')->labelColor('#FFFFFF')
        );

        $scroll->addChild($content);

        return $scroll;
    }

    // ── Scenario Dispatching ────────────────────────

    public function startScenario(string $key): void
    {
        $this->scenarioQueue = [$key];
    }

    public function startAll(): void
    {
        $this->scenarioQueue = array_keys(self::SCENARIOS);
    }

    public function runLoop(): void
    {
        $this->callbacks = new CallbackRegistry;
        $this->running = true;
        $this->navigationIntent = null;
        $this->hasError = false;

        // Set window background to match dark theme behind system bars
        nativephp_call('UI.SetBackground', json_encode(['color' => '#0F172A']));

        while ($this->running) {
            switch ($this->phase) {
                case 'menu':
                    $this->runMenuLoop();
                    break;

                case 'results':
                    $this->runResultsLoop();
                    break;

                default:
                    // A scenario is queued — run it
                    $this->runNextScenario();
                    break;
            }
        }
    }

    protected function runMenuLoop(): void
    {
        while ($this->running && $this->phase === 'menu') {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->callbacks);
            nativephp_element_publish($tree);

            $event = nativephp_element_wait_event(-1);
            if ($event === null) {
                continue;
            }
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->running = false;
                break;
            }
            $this->dispatch($event);

            // If dispatch set up a scenario queue, break out to run it
            if (! empty($this->scenarioQueue)) {
                $this->phase = 'dispatch';
                break;
            }
        }
    }

    protected function runResultsLoop(): void
    {
        while ($this->running && $this->phase === 'results') {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->callbacks);
            nativephp_element_publish($tree);

            $event = nativephp_element_wait_event(-1);
            if ($event === null) {
                continue;
            }
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->running = false;
                break;
            }
            $this->dispatch($event);
        }
    }

    protected function runNextScenario(): void
    {
        while (! empty($this->scenarioQueue) && $this->running) {
            $scenario = array_shift($this->scenarioQueue);
            $this->currentScenario = self::SCENARIOS[$scenario] ?? $scenario;
            $this->scenarioSkipped = false;

            NativeRouter::debugLog("BENCH starting scenario: {$scenario}");

            match ($scenario) {
                'counter_tap'    => $this->runCounterTap(),
                'large_tree_tap' => $this->runLargeTreeTap(),
                'text_input'     => $this->runTextInput(),
                'list_scroll'    => $this->runListScroll(),
                'rapid_fire'     => $this->runRapidFire(),
                'navigation'     => $this->runNavigation(),
                'toggle_tree'    => $this->runToggleTree(),
                'render'         => $this->runRenderBenchmark(),
                default          => null,
            };

            if ($this->scenarioSkipped) {
                // Discard partial results for this scenario
                unset($this->results[$scenario]);
                NativeRouter::debugLog("BENCH skipped scenario: {$scenario}");
                // scenarioQueue was already cleared by skipScenario()
                $this->phase = 'menu';
                return;
            }

            NativeRouter::debugLog("BENCH completed scenario: {$scenario}");
        }

        // All scenarios done — show results if we have any
        if (! empty($this->results)) {
            $this->phase = 'results';
            $this->benchmarkDone = true;
            $this->saveResults();
            NativeRouter::debugLog("BENCH ALL COMPLETE pipeline={$this->pipeline}");
        } else {
            $this->phase = 'menu';
        }
    }

    // ── Simulation Helpers ────────────────────────────

    /** Simulate a press event from Kotlin side and wait for it to arrive in PHP. */
    protected function simulatePress(int $callbackId): ?array
    {
        nativephp_call('Perf.SimulatePress', json_encode(['callback_id' => $callbackId, 'node_id' => 0]));
        return nativephp_element_wait_event(500);
    }

    /** Simulate a text change event from Kotlin side. */
    protected function simulateTextChange(int $callbackId, string $text): ?array
    {
        nativephp_call('Perf.SimulateTextChange', json_encode([
            'callback_id' => $callbackId,
            'node_id' => 0,
            'text' => $text,
        ]));
        return nativephp_element_wait_event(500);
    }

    /** Simulate a toggle event from Kotlin side. */
    protected function simulateToggle(int $callbackId, bool $value): ?array
    {
        nativephp_call('Perf.SimulateToggle', json_encode([
            'callback_id' => $callbackId,
            'node_id' => 0,
            'value' => $value,
        ]));
        return nativephp_element_wait_event(500);
    }

    // ── Running Screen ──────────────────────────────

    protected function renderRunningScreen(): Element
    {
        return Column::make(
            Text::make('RUNNING')->fontSize(11)->fontWeight(7)->color('#38BDF8'),
            Spacer::make()->height(8),
            Text::make($this->currentScenario)->fontSize(22)->fontWeight(7)->color('#F1F5F9'),
            Spacer::make()->height(4),
            Text::make("Pipeline: {$this->pipeline}")->fontSize(13)->color('#64748B'),
        )->fill()->center()->safeArea()->bg('#0F172A');
    }

    // ── Scenario 1: Counter Tap (existing) ──────────

    /** Set by skipScenario() — checked after scenario run to discard results */
    protected bool $scenarioSkipped = false;

    /** Skip current interactive scenario and return to menu */
    public function skipScenario(): void
    {
        $this->interactionCount = PHP_INT_MAX;
        $this->scenarioSkipped = true;
        $this->scenarioQueue = []; // Cancel "Run All" queue too
    }

    public function onTap(): void
    {
        $this->counter++;
        $this->interactionCount++;
    }

    protected function renderCounterScreen(): Element
    {
        $pct = self::TAP_ITERATIONS > 0 ? round($this->interactionCount / self::TAP_ITERATIONS * 100) : 0;

        return Column::make(
            Row::make(
                Button::make('Back')->onPress('skipScenario')->color('#334155')->labelColor('#94A3B8'),
                Spacer::make()->flexGrow(1),
                Text::make("{$this->interactionCount}/" . self::TAP_ITERATIONS)->fontSize(12)->fontWeight(6)->color('#38BDF8'),
            )->fillWidth()->padding(12)->gap(8),
            Spacer::make()->height(1)->flexGrow(1),
            Text::make('COUNTER TAP')->fontSize(11)->fontWeight(7)->color('#38BDF8'),
            Spacer::make()->height(8),
            Text::make((string) $this->counter)->fontSize(64)->fontWeight(7)->color('#F1F5F9'),
            Spacer::make()->height(8),
            Text::make("{$pct}%")->fontSize(14)->color('#64748B'),
            Spacer::make()->height(24),
            Button::make('+1')->onPress('onTap')->color('#1E293B')->labelColor('#38BDF8'),
            Spacer::make()->height(1)->flexGrow(1),
        )->fill()->center()->safeArea()->bg('#0F172A');
    }

    protected function runCounterTap(): void
    {
        $this->phase = 'counter_tap';
        $this->counter = 0;
        $this->interactionCount = 0;

        nativephp_call('Perf.Enable', '{}');

        while ($this->running && $this->interactionCount < self::TAP_ITERATIONS) {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->callbacks);
            nativephp_element_publish($tree);

            usleep(20_000); // Let Compose render the frame

            $cbId = $this->callbacks->lookup('onTap');
            if ($cbId === null) break;

            $event = $this->simulatePress($cbId);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->running = false;
                break;
            }
            $this->dispatch($event);
        }

        $exportResult = nativephp_call('Perf.Export', '{}');
        $this->results['counter_tap'] = json_decode($exportResult, true)['data'] ?? '{}';
        nativephp_call('Perf.Disable', '{}');
    }

    // ── Scenario 2: Large Tree Tap ──────────────────

    protected function renderLargeTreeTapScreen(): Element
    {
        $root = Column::make()->fill()->safeArea()->bg('#0F172A');

        $root->addChild(
            Row::make(
                Button::make('Back')->onPress('skipScenario')->color('#334155')->labelColor('#94A3B8'),
                Text::make('LARGE TREE TAP')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                Spacer::make()->flexGrow(1),
                Text::make("{$this->interactionCount}/" . self::LARGE_TREE_TAP_ITERATIONS)->fontSize(12)->fontWeight(6)->color('#38BDF8'),
            )->fillWidth()->padding(12)->gap(8)
        );

        // Build a ~200-node tree with the tap button embedded
        $scroll = ScrollView::make()->fillWidth()->flexGrow(1);
        $treeContent = Column::make()->fillWidth()->padding(8)->gap(4);

        $nodeCount = 0;
        $this->buildLargeTreeContent($treeContent, $nodeCount);

        $treeContent->addChild(
            Row::make(
                Text::make((string) $this->counter)->fontSize(32)->fontWeight(7)->color('#38BDF8'),
                Spacer::make()->width(16),
                Button::make('+1')->onPress('onTap')->color('#1E293B')->labelColor('#38BDF8'),
            )->fillWidth()->center()->padding(12)->bg('#0F172A')->borderRadius(8)
        );

        $scroll->addChild($treeContent);
        $root->addChild($scroll);

        return $root;
    }

    protected function buildLargeTreeContent(Element $container, int &$nodeCount): void
    {
        $colors = ['#1F2937', '#DC2626', '#059669', '#2563EB', '#7C3AED', '#D97706'];
        $icons = ['home', 'star', 'settings', 'search', 'person', 'favorite'];

        // Generate ~200 nodes as a mix of rows, text, icons
        while ($nodeCount < self::LARGE_TREE_NODE_COUNT) {
            $row = Row::make()->fillWidth()->gap(8)->padding(4);
            $row->addChild(Icon::make($icons[$nodeCount % count($icons)])->size(20)->color($colors[$nodeCount % count($colors)]));
            $row->addChild(Text::make("Node #{$nodeCount}")->fontSize(13)->color($colors[($nodeCount + 1) % count($colors)]));
            $row->addChild(Spacer::make()->flexGrow(1));
            $row->addChild(Text::make(sprintf('%.1fms', $nodeCount * 0.1))->fontSize(11)->color('#9CA3AF'));
            $container->addChild($row);
            $nodeCount += 4; // row + 3 children
        }
    }

    protected function runLargeTreeTap(): void
    {
        $this->phase = 'large_tree_tap';
        $this->counter = 0;
        $this->interactionCount = 0;

        nativephp_call('Perf.Enable', '{}');

        while ($this->running && $this->interactionCount < self::LARGE_TREE_TAP_ITERATIONS) {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->callbacks);
            nativephp_element_publish($tree);

            usleep(20_000);

            $cbId = $this->callbacks->lookup('onTap');
            if ($cbId === null) break;

            $event = $this->simulatePress($cbId);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->running = false;
                break;
            }
            $this->dispatch($event);
        }

        $exportResult = nativephp_call('Perf.Export', '{}');
        $this->results['large_tree_tap'] = json_decode($exportResult, true)['data'] ?? '{}';
        nativephp_call('Perf.Disable', '{}');
    }

    // ── Scenario 3: Text Input ──────────────────────

    public function onTextChange(string $text): void
    {
        $this->textValue = $text;
        $this->interactionCount++;
    }

    protected function renderTextInputScreen(): Element
    {
        return Column::make(
            Row::make(
                Button::make('Back')->onPress('skipScenario')->color('#334155')->labelColor('#94A3B8'),
                Spacer::make()->flexGrow(1),
                Text::make("{$this->interactionCount}/" . self::TEXT_INPUT_ITERATIONS)->fontSize(12)->fontWeight(6)->color('#38BDF8'),
            )->fillWidth()->padding(12)->gap(8),
            Spacer::make()->height(1)->flexGrow(1),
            Text::make('TEXT INPUT')->fontSize(11)->fontWeight(7)->color('#38BDF8'),
            Spacer::make()->height(12),
            TextInput::make()
                ->placeholder('Type here...')
                ->value($this->textValue)
                ->onChange('onTextChange')
                ->fillWidth(),
            Spacer::make()->height(12),
            Text::make($this->textValue ?: 'waiting for input...')->fontSize(14)->color('#64748B'),
            Spacer::make()->height(24),
            Button::make('Skip')->onPress('skipScenario')->color('#334155')->labelColor('#94A3B8'),
            Spacer::make()->height(1)->flexGrow(1),
        )->fill()->center()->padding(24)->safeArea()->bg('#0F172A');
    }

    protected function runTextInput(): void
    {
        $this->phase = 'text_input';
        $this->textValue = '';
        $this->interactionCount = 0;

        $sampleText = 'The quick brown fox jumps over the lazy dog testing';

        nativephp_call('Perf.Enable', '{}');

        while ($this->running && $this->interactionCount < self::TEXT_INPUT_ITERATIONS) {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->callbacks);
            nativephp_element_publish($tree);

            usleep(20_000);

            $cbId = $this->callbacks->lookup('onTextChange');
            if ($cbId === null) break;

            // Simulate typing one character at a time
            $nextText = substr($sampleText, 0, $this->interactionCount + 1);
            $event = $this->simulateTextChange($cbId, $nextText);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->running = false;
                break;
            }
            $this->dispatch($event);
        }

        $exportResult = nativephp_call('Perf.Export', '{}');
        $this->results['text_input'] = json_decode($exportResult, true)['data'] ?? '{}';
        nativephp_call('Perf.Disable', '{}');
    }

    // ── Scenario 4: Large List Render ─────────────────

    public function onScrollDone(): void
    {
        $this->interactionCount = 1; // Signal done
    }

    protected function renderListScrollScreen(): Element
    {
        $root = Column::make()->fill()->safeArea()->bg('#0F172A');

        $root->addChild(
            Column::make(
                Row::make(
                    Button::make('Back')->onPress('skipScenario')->color('#334155')->labelColor('#94A3B8'),
                    Spacer::make()->flexGrow(1),
                    Button::make('Done')->onPress('onScrollDone')->color('#059669')->labelColor('#FFFFFF'),
                )->fillWidth()->gap(8),
                Text::make('LARGE LIST RENDER')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                Text::make('Scroll through the list, then tap Done')->fontSize(12)->color('#64748B'),
            )->fillWidth()->padding(12)->gap(6)
        );

        $scroll = ScrollView::make()->fillWidth()->flexGrow(1);
        $content = Column::make()->fillWidth()->gap(0);

        for ($i = 0; $i < self::LIST_SCROLL_ITEM_COUNT; $i++) {
            $content->addChild(
                ListItem::make("Item #{$i}")
                    ->supporting("Description for list item {$i}")
                    ->leadingIcon(['home', 'star', 'settings', 'search', 'person', 'favorite'][$i % 6])
            );
        }

        $scroll->addChild($content);
        $root->addChild($scroll);

        return $root;
    }

    const LIST_RERENDER_ITERATIONS = 100;

    protected function runListScroll(): void
    {
        $this->phase = 'list_scroll';
        $icons = ['home', 'star', 'settings', 'search', 'person', 'favorite'];

        nativephp_call('Perf.Enable', '{}');
        nativephp_call('Perf.StartCaptureWindow', '{}');

        // Rapidly re-render the 1000-item list with changing content
        // to force Compose to diff and re-render each frame
        for ($frame = 0; $frame < self::LIST_RERENDER_ITERATIONS; $frame++) {
            $this->callbacks = new CallbackRegistry;

            $root = Column::make()->fill()->safeArea();
            $root->addChild(
                Text::make("Large List Re-render {$frame}/" . self::LIST_RERENDER_ITERATIONS)
                    ->fontSize(16)->fontWeight(6)->color('#1F2937')->padding(12)
            );

            $scroll = ScrollView::make()->fillWidth()->flexGrow(1);
            $content = Column::make()->fillWidth();

            for ($i = 0; $i < self::LIST_SCROLL_ITEM_COUNT; $i++) {
                $content->addChild(
                    ListItem::make("Item #" . (($i + $frame) % self::LIST_SCROLL_ITEM_COUNT))
                        ->supporting("Frame {$frame}")
                        ->leadingIcon($icons[$i % 6])
                );
            }

            $scroll->addChild($content);
            $root->addChild($scroll);

            $tree = $root->toArray($this->callbacks);
            nativephp_element_publish($tree);
            usleep(16_000);
        }

        $captureResult = nativephp_call('Perf.StopCaptureWindow', '{}');
        $this->results['list_scroll'] = json_decode($captureResult, true)['data'] ?? '{}';
        nativephp_call('Perf.Disable', '{}');
    }

    // ── Scenario 5: Rapid-Fire ──────────────────────

    protected function runRapidFire(): void
    {
        $this->publishProgressScreen('Rapid-Fire', self::RAPID_FIRE_ITERATIONS . ' publishes');

        nativephp_call('Perf.Enable', '{}');

        $timings = [];
        for ($i = 0; $i < self::RAPID_FIRE_ITERATIONS; $i++) {
            $this->callbacks = new CallbackRegistry;

            $t0 = microtime(true);
            $element = $this->generateRapidFireTree($i);
            $t1 = microtime(true);

            $tree = $element->toArray($this->callbacks);
            $t2 = microtime(true);

            nativephp_element_publish($tree);
            $t3 = microtime(true);

            $timings[] = [
                'render' => ($t1 - $t0) * 1000,
                'toArray' => ($t2 - $t1) * 1000,
                'publish' => ($t3 - $t2) * 1000,
                'total' => ($t3 - $t0) * 1000,
            ];
        }

        $exportResult = nativephp_call('Perf.Export', '{}');
        nativephp_call('Perf.Disable', '{}');

        // Discard first 10 as warmup
        $measured = array_slice($timings, 10);
        $stats = $this->computeStats($measured);

        $totalTimeMs = array_sum(array_column($timings, 'total'));
        $stats['events_per_sec'] = self::RAPID_FIRE_ITERATIONS / ($totalTimeMs / 1000);
        $stats['frame_data'] = json_decode($exportResult, true)['data'] ?? '{}';

        $this->results['rapid_fire'] = $stats;
    }

    protected function generateRapidFireTree(int $seed): Element
    {
        $colors = ['#1F2937', '#DC2626', '#059669', '#2563EB', '#7C3AED'];

        return Column::make(
            Text::make("Frame #{$seed}")->fontSize(18)->fontWeight(6)->color($colors[$seed % count($colors)]),
            Text::make("Value: " . ($seed * 7 % 1000))->fontSize(14)->color('#6B7280'),
            Row::make(
                Text::make('A')->fontSize(12)->color('#374151'),
                Text::make('B')->fontSize(12)->color('#374151'),
                Text::make('C')->fontSize(12)->color('#374151'),
            )->gap(8),
        )->fill()->center()->safeArea();
    }

    // ── Scenario 6: Navigation ──────────────────────

    protected function runNavigation(): void
    {
        $this->publishProgressScreen('Navigation', self::NAVIGATION_ITERATIONS . ' push/pop cycles');

        nativephp_call('Perf.Enable', '{}');

        $timings = [];
        for ($i = 0; $i < self::NAVIGATION_ITERATIONS; $i++) {
            $this->callbacks = new CallbackRegistry;

            $t0 = microtime(true);

            // Simulate push: set transition, reset buffers, publish new tree
            nativephp_call('UI.SetTransition', json_encode(['type' => 'slide_forward']));
            nativephp_element_reset();

            $element = Column::make(
                Text::make("Screen #{$i} - Forward")->fontSize(20)->fontWeight(6)->color('#2563EB'),
                Text::make("Navigation benchmark iteration {$i}")->fontSize(14)->color('#6B7280'),
            )->fill()->center()->safeArea();

            $tree = $element->toArray($this->callbacks);
            nativephp_element_publish($tree);
            $t1 = microtime(true);

            usleep(350_000); // 350ms for transition animation

            // Simulate pop: set transition, reset buffers, publish original tree
            $this->callbacks = new CallbackRegistry;
            nativephp_call('UI.SetTransition', json_encode(['type' => 'slide_back']));
            nativephp_element_reset();

            $element = Column::make(
                Text::make("Screen #{$i} - Back")->fontSize(20)->fontWeight(6)->color('#059669'),
                Text::make("Returning from iteration {$i}")->fontSize(14)->color('#6B7280'),
            )->fill()->center()->safeArea();

            $tree = $element->toArray($this->callbacks);
            nativephp_element_publish($tree);
            $t2 = microtime(true);

            usleep(350_000); // 350ms for return transition

            $timings[] = [
                'push_ms' => ($t1 - $t0) * 1000,
                'pop_ms' => ($t2 - $t1) * 1000,
                'cycle_ms' => ($t2 - $t0) * 1000,
            ];
        }

        $exportResult = nativephp_call('Perf.Export', '{}');
        nativephp_call('Perf.Disable', '{}');

        $frameData = json_decode($exportResult, true)['data'] ?? '{}';

        $pushTimes = array_column($timings, 'push_ms');
        $popTimes = array_column($timings, 'pop_ms');
        sort($pushTimes);
        sort($popTimes);

        $this->results['navigation'] = [
            'iterations' => self::NAVIGATION_ITERATIONS,
            'avg_push_ms' => array_sum($pushTimes) / count($pushTimes),
            'avg_pop_ms' => array_sum($popTimes) / count($popTimes),
            'p95_push_ms' => $pushTimes[(int) floor(count($pushTimes) * 0.95)] ?? 0,
            'p95_pop_ms' => $popTimes[(int) floor(count($popTimes) * 0.95)] ?? 0,
            'frame_data' => $frameData,
        ];
    }

    // ── Scenario 7: Toggle Tree ─────────────────────

    public function onToggle(bool $value): void
    {
        $this->toggleState = $value;
        $this->interactionCount++;
    }

    protected function renderToggleTreeScreen(): Element
    {
        $root = Column::make()->fill()->safeArea()->bg('#0F172A');

        $root->addChild(
            Column::make(
                Row::make(
                    Button::make('Back')->onPress('skipScenario')->color('#334155')->labelColor('#94A3B8'),
                    Spacer::make()->flexGrow(1),
                    Text::make("{$this->interactionCount}/" . self::TOGGLE_TREE_ITERATIONS)->fontSize(12)->fontWeight(6)->color('#38BDF8'),
                )->fillWidth()->gap(8),
                Text::make('TOGGLE TREE')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                Spacer::make()->height(8),
                Row::make(
                    Text::make('Show 200-node subtree')->fontSize(14)->color('#CBD5E1'),
                    Spacer::make()->flexGrow(1),
                    Toggle::make()->value($this->toggleState)->onChange('onToggle'),
                )->fillWidth()->gap(8),
            )->fillWidth()->padding(16)->gap(4)
        );

        if ($this->toggleState) {
            $scroll = ScrollView::make()->fillWidth()->flexGrow(1);
            $content = Column::make()->fillWidth()->padding(8)->gap(4);

            $nodeCount = 0;
            $this->buildLargeTreeContent($content, $nodeCount);

            $scroll->addChild($content);
            $root->addChild($scroll);
        } else {
            $root->addChild(
                Column::make(
                    Spacer::make()->flexGrow(1),
                    Text::make('subtree hidden')->fontSize(14)->color('#475569'),
                    Spacer::make()->flexGrow(1),
                )->fillWidth()->flexGrow(1)->center()
            );
        }

        return $root;
    }

    protected function runToggleTree(): void
    {
        $this->phase = 'toggle_tree';
        $this->toggleState = false;
        $this->interactionCount = 0;

        nativephp_call('Perf.Enable', '{}');

        while ($this->running && $this->interactionCount < self::TOGGLE_TREE_ITERATIONS) {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->callbacks);
            nativephp_element_publish($tree);

            usleep(20_000);

            $cbId = $this->callbacks->lookup('onToggle');
            if ($cbId === null) break;

            // Alternate toggle state each iteration
            $nextValue = !$this->toggleState;
            $event = $this->simulateToggle($cbId, $nextValue);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->running = false;
                break;
            }
            $this->dispatch($event);
        }

        $exportResult = nativephp_call('Perf.Export', '{}');
        $this->results['toggle_tree'] = json_decode($exportResult, true)['data'] ?? '{}';
        nativephp_call('Perf.Disable', '{}');
    }

    // ── Scenario 8: PHP Render Benchmark (existing) ─

    protected function publishProgressScreen(string $label, string $detail): void
    {
        $this->phase = 'running';
        $this->callbacks = new CallbackRegistry;
        $tree = Column::make(
            Text::make('RUNNING')->fontSize(13)->fontWeight(7)->color('#38BDF8'),
            Spacer::make()->height(8),
            Text::make($label)->fontSize(24)->fontWeight(7)->color('#F1F5F9'),
            Spacer::make()->height(6),
            Text::make($detail)->fontSize(15)->color('#94A3B8'),
        )->fill()->center()->safeArea()->bg('#0F172A')->toArray($this->callbacks);
        nativephp_element_publish($tree);
    }

    protected function runRenderBenchmark(): void
    {
        $total = count(self::SIZES);
        foreach (self::SIZES as $idx => $size) {
            $step = $idx + 1;
            $this->publishProgressScreen('PHP Render', "{$size} nodes ({$step}/{$total})");
            usleep(50_000); // Let the progress screen render
            $this->results["render_{$size}"] = $this->benchmarkSize($size);
        }
    }

    protected function benchmarkSize(int $targetNodes): array
    {
        $timings = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $this->callbacks = new CallbackRegistry;

            $t0 = microtime(true);
            $element = $this->generateTree($targetNodes, $i);
            $t1 = microtime(true);

            $tree = $element->toArray($this->callbacks);
            $t2 = microtime(true);

            nativephp_element_publish($tree);
            $t3 = microtime(true);

            $timings[] = [
                'render' => ($t1 - $t0) * 1000,
                'toArray' => ($t2 - $t1) * 1000,
                'publish' => ($t3 - $t2) * 1000,
                'total' => ($t3 - $t0) * 1000,
            ];

            // Give the bridge breathing room between iterations
            usleep(1_000);
        }

        $measured = array_slice($timings, self::WARMUP);
        $stats = $this->computeStats($measured);

        NativeRouter::debugLog(sprintf(
            'BENCH %s nodes=%d iter=%d avg_render=%.2fms avg_toArray=%.2fms avg_publish=%.2fms avg_total=%.2fms p50=%.2fms p95=%.2fms',
            $this->pipeline,
            $targetNodes,
            count($measured),
            $stats['avg_render'],
            $stats['avg_toArray'],
            $stats['avg_publish'],
            $stats['avg_total'],
            $stats['p50_total'],
            $stats['p95_total'],
        ));

        return $stats;
    }

    protected function computeStats(array $timings): array
    {
        $count = count($timings);
        if ($count === 0) {
            return array_fill_keys([
                'avg_render', 'avg_toArray', 'avg_publish', 'avg_total',
                'min_total', 'max_total', 'p50_total', 'p95_total',
                'min_render', 'max_render', 'min_toArray', 'max_toArray',
                'min_publish', 'max_publish',
            ], 0.0);
        }

        $stats = [];

        foreach (['render', 'toArray', 'publish', 'total'] as $key) {
            $values = array_column($timings, $key);
            sort($values);

            $stats["avg_{$key}"] = array_sum($values) / $count;
            $stats["min_{$key}"] = $values[0];
            $stats["max_{$key}"] = $values[$count - 1];

            if ($key === 'total') {
                $stats['p50_total'] = $values[(int) floor($count * 0.50)];
                $stats['p95_total'] = $values[(int) floor($count * 0.95)];
            }
        }

        return $stats;
    }

    protected function saveResults(): void
    {
        $timestamp = date('Ymd-His');
        $payload = [
            'pipeline' => $this->pipeline,
            'timestamp' => date('c'),
            'results' => [],
        ];

        foreach ($this->results as $key => $stats) {
            if (is_string($stats)) {
                $payload['results'][$key] = json_decode($stats, true);
            } else {
                $payload['results'][$key] = $stats;
            }
        }

        $filename = "bench-{$this->pipeline}-{$timestamp}.json";
        $path = storage_path("logs/{$filename}");
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));

        NativeRouter::debugLog("BENCH results saved to {$path}");
    }

    // ── Tree Generators ─────────────────────────────

    protected function generateTree(int $targetNodes, int $seed): Element
    {
        $nodeCount = 0;

        return $this->buildSubtree($targetNodes, $seed, 0, $nodeCount);
    }

    protected function buildSubtree(int $targetNodes, int $seed, int $depth, int &$nodeCount): Element
    {
        $nodeCount++;

        if ($depth >= 8 || $nodeCount >= $targetNodes) {
            return $this->makeLeaf($seed + $nodeCount);
        }

        $branchFactor = 2 + (($seed + $depth) % 4);
        $isRow = ($depth % 2 === 1);
        $container = $isRow ? Row::make() : Column::make();

        if ($depth === 0) {
            $container->fill()->safeArea();
        } else {
            $container->fillWidth()->gap(4);
        }

        $nodesPerChild = max(1, (int) (($targetNodes - $nodeCount) / $branchFactor));

        for ($i = 0; $i < $branchFactor && $nodeCount < $targetNodes; $i++) {
            $child = $this->buildSubtree(
                min($targetNodes, $nodeCount + $nodesPerChild),
                $seed + $i * 7,
                $depth + 1,
                $nodeCount,
            );
            $container->addChild($child);
        }

        return $container;
    }

    protected function makeLeaf(int $seed): Element
    {
        $leafTypes = ['text', 'button', 'list_item', 'icon', 'divider', 'spacer'];
        $type = $leafTypes[$seed % count($leafTypes)];
        $colors = ['#1F2937', '#DC2626', '#059669', '#2563EB', '#7C3AED', '#D97706'];
        $icons = ['home', 'star', 'settings', 'search', 'person', 'favorite'];

        return match ($type) {
            'text' => Text::make("Item #{$seed}")
                ->fontSize(14)
                ->color($colors[$seed % count($colors)]),
            'button' => Button::make("Btn #{$seed}")
                ->color($colors[($seed + 1) % count($colors)]),
            'list_item' => ListItem::make("Headline #{$seed}")
                ->supporting("Supporting text for item {$seed}")
                ->leadingIcon($icons[$seed % count($icons)]),
            'icon' => Icon::make($icons[$seed % count($icons)])
                ->size(24)
                ->color($colors[($seed + 2) % count($colors)]),
            'divider' => Divider::make()->fillWidth(),
            'spacer' => Spacer::make()->height(8),
        };
    }

    // ── Results Screen ──────────────────────────────

    protected function renderResults(): Element
    {
        $scroll = ScrollView::make()->fill()->safeArea()->bg('#0F172A');
        $content = Column::make()->fillWidth()->padding(16, 16, 40, 16)->gap(14);

        $content->addChild(
            Row::make(
                Text::make('RESULTS')->fontSize(13)->fontWeight(7)->color('#38BDF8'),
                Spacer::make()->flexGrow(1),
                Text::make(date('H:i:s'))->fontSize(13)->color('#475569'),
            )->fillWidth()
        );
        $content->addChild(
            Text::make('Benchmark Results')->fontSize(26)->fontWeight(7)->color('#F1F5F9')
        );
        $content->addChild(
            Text::make("Pipeline: {$this->pipeline}")->fontSize(15)->color('#94A3B8')
        );

        // Interactive scenario results (counter_tap, large_tree_tap, text_input, toggle_tree)
        foreach (['counter_tap', 'large_tree_tap', 'text_input', 'toggle_tree'] as $key) {
            $data = $this->results[$key] ?? null;
            if ($data) {
                $content->addChild($this->renderInteractionCard(
                    self::SCENARIOS[$key] ?? $key,
                    $data
                ));
            }
        }

        // List scroll FPS result
        $listScroll = $this->results['list_scroll'] ?? null;
        if ($listScroll) {
            $content->addChild($this->renderFrameCard('Large List Render', $listScroll));
        }

        // Rapid-fire result
        $rapidFire = $this->results['rapid_fire'] ?? null;
        if ($rapidFire) {
            $content->addChild($this->renderRapidFireCard($rapidFire));
        }

        // Navigation result
        $nav = $this->results['navigation'] ?? null;
        if ($nav) {
            $content->addChild($this->renderNavigationCard($nav));
        }

        // PHP-side render benchmark cards
        $hasRender = false;
        foreach ($this->results as $key => $stats) {
            if (str_starts_with($key, 'render_') && is_array($stats) && isset($stats['avg_total'])) {
                if (! $hasRender) {
                    $content->addChild(Spacer::make()->height(4));
                    $content->addChild(
                        Text::make('PHP RENDER')->fontSize(13)->fontWeight(7)->color('#38BDF8')
                    );
                    $hasRender = true;
                }
                $nodeCount = str_replace('render_', '', $key);
                $content->addChild($this->renderPhpBenchCard("{$nodeCount} nodes", $stats));
            }
        }

        $content->addChild(Spacer::make()->height(8));
        $content->addChild(
            Button::make('Back to Menu')->onPress('backToMenu')->fillWidth()
                ->color('#1E293B')->labelColor('#94A3B8')
        );

        $scroll->addChild($content);

        return $scroll;
    }

    public function backToMenu(): void
    {
        $this->phase = 'menu';
        $this->results = [];
        $this->benchmarkDone = false;
    }

    // ── Result Card Renderers ───────────────────────

    protected function renderInteractionCard(string $title, $rawData): Element
    {
        $data = is_string($rawData) ? json_decode($rawData, true) : $rawData;
        $latency = $data['interaction_latency_ms'] ?? null;
        $jank = $data['interaction_jank'] ?? null;

        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make($title)->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );

        if ($latency) {
            $count = (int) ($latency['count'] ?? 0);
            $cardContent->addChild(
                Text::make("{$count} interactions")->fontSize(14)->color('#94A3B8')
            );

            $cardContent->addChild(Spacer::make()->height(4));
            $cardContent->addChild(
                Row::make(
                    $this->statChip('avg', (float) ($latency['average'] ?? 0), '#38BDF8'),
                    $this->statChip('p50', (float) ($latency['p50'] ?? 0), '#A78BFA'),
                    $this->statChip('p95', (float) ($latency['p95'] ?? 0), '#F59E0B'),
                    $this->statChip('p99', (float) ($latency['p99'] ?? 0), '#EF4444'),
                )->fillWidth()->gap(8)
            );

            if ($jank) {
                $jankRate = (float) ($jank['paint_jank_rate'] ?? 0) * 100;
                $jankCount = (int) ($jank['paint_jank_count'] ?? 0);
                $fps = (float) ($jank['effective_fps'] ?? 0);
                $jankColor = $jankRate > 20 ? '#EF4444' : ($jankRate > 5 ? '#F59E0B' : '#10B981');
                $fpsColor = $fps > 60 ? '#10B981' : ($fps > 30 ? '#F59E0B' : '#EF4444');

                $cardContent->addChild(
                    Row::make(
                        $this->statChip('eff. FPS', $fps, $fpsColor, 'x'),
                        $this->statChip('jank', (float) $jankCount, $jankColor, 'none'),
                        $this->statChip('jank%', $jankRate, $jankColor, '%'),
                    )->fillWidth()->gap(8)
                );
            }

            $delivery = $data['event_delivery_ms'] ?? null;
            $paint = $data['frame_paint_ms'] ?? null;
            $composePost = $data['compose_post_ms'] ?? null;
            if ($delivery && $paint && $composePost) {
                $cardContent->addChild(Spacer::make()->height(6));
                $cardContent->addChild(
                    Text::make('PIPELINE')->fontSize(12)->fontWeight(7)->color('#64748B')
                );
                $cardContent->addChild(
                    Row::make(
                        $this->statChip('event', (float) ($delivery['average'] ?? 0), '#10B981'),
                        $this->statChip('post', (float) ($composePost['average'] ?? 0), '#F59E0B'),
                        $this->statChip('paint', (float) ($paint['average'] ?? 0), '#38BDF8'),
                    )->fillWidth()->gap(8)
                );
            }

            $cardContent->addChild(Spacer::make()->height(4));
            $cardContent->addChild(
                Text::make(sprintf(
                    'min %.2fms  ·  max %.2fms',
                    (float) ($latency['min'] ?? 0),
                    (float) ($latency['max'] ?? 0),
                ))->fontSize(12)->color('#64748B')
            );
        }

        return $cardContent;
    }

    protected function renderFrameCard(string $title, $rawData): Element
    {
        $data = is_string($rawData) ? json_decode($rawData, true) : $rawData;
        $frames = $data['frame_times_ms'] ?? null;

        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make($title)->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );

        if ($frames) {
            $frameCount = (int) ($data['frame_count'] ?? 0);
            $cardContent->addChild(
                Text::make("{$frameCount} frames")->fontSize(14)->color('#94A3B8')
            );

            $jankRate = (float) ($frames['jank_rate'] ?? 0) * 100;
            $fps = (float) ($frames['fps'] ?? 0);
            $jankCount = (int) ($frames['jank_count'] ?? 0);
            $jankColor = $jankRate > 20 ? '#EF4444' : ($jankRate > 5 ? '#F59E0B' : '#10B981');
            $fpsColor = $fps > 60 ? '#10B981' : ($fps > 30 ? '#F59E0B' : '#EF4444');

            $cardContent->addChild(Spacer::make()->height(4));
            $cardContent->addChild(
                Row::make(
                    $this->statChip('FPS', $fps, $fpsColor, 'x'),
                    $this->statChip('jank', (float) $jankCount, $jankColor, 'none'),
                    $this->statChip('jank%', $jankRate, $jankColor, '%'),
                )->fillWidth()->gap(8)
            );

            $cardContent->addChild(
                Row::make(
                    $this->statChip('avg', (float) ($frames['average'] ?? 0), '#38BDF8'),
                    $this->statChip('p95', (float) ($frames['p95'] ?? 0), '#F59E0B'),
                    $this->statChip('p99', (float) ($frames['p99'] ?? 0), '#EF4444'),
                )->fillWidth()->gap(8)
            );

            $cardContent->addChild(Spacer::make()->height(6));
            $cardContent->addChild(
                Text::make('RENDER PIPELINE')->fontSize(12)->fontWeight(7)->color('#64748B')
            );
            $cardContent->addChild(
                Row::make(
                    $this->statChip('layout', (float) ($frames['avg_layout_ms'] ?? 0), '#94A3B8'),
                    $this->statChip('draw', (float) ($frames['avg_draw_ms'] ?? 0), '#94A3B8'),
                    $this->statChip('sync', (float) ($frames['avg_sync_ms'] ?? 0), '#94A3B8'),
                )->fillWidth()->gap(8)
            );
        } else {
            $cardContent->addChild(
                Text::make('No frame data captured')->fontSize(14)->color('#64748B')
            );
        }

        return $cardContent;
    }

    protected function renderRapidFireCard(array $stats): Element
    {
        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make('Rapid-Fire Throughput')->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );
        $cardContent->addChild(
            Text::make(self::RAPID_FIRE_ITERATIONS . ' iterations, no event wait')->fontSize(14)->color('#94A3B8')
        );

        $eventsPerSec = (float) ($stats['events_per_sec'] ?? 0);
        $cardContent->addChild(Spacer::make()->height(4));
        $cardContent->addChild(
            Row::make(
                $this->statChip('evt/s', $eventsPerSec, '#10B981', 'x'),
                $this->statChip('avg', (float) ($stats['avg_total'] ?? 0), '#38BDF8'),
                $this->statChip('p95', (float) ($stats['p95_total'] ?? 0), '#F59E0B'),
            )->fillWidth()->gap(8)
        );

        $cardContent->addChild(Spacer::make()->height(6));
        $cardContent->addChild(
            Text::make('PHP PIPELINE')->fontSize(12)->fontWeight(7)->color('#64748B')
        );
        $cardContent->addChild(
            Row::make(
                $this->statChip('render', (float) ($stats['avg_render'] ?? 0), '#10B981'),
                $this->statChip('toArray', (float) ($stats['avg_toArray'] ?? 0), '#F59E0B'),
                $this->statChip('publish', (float) ($stats['avg_publish'] ?? 0), '#38BDF8'),
            )->fillWidth()->gap(8)
        );

        return $cardContent;
    }

    protected function renderNavigationCard(array $stats): Element
    {
        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make('Navigation Transitions')->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );
        $cardContent->addChild(
            Text::make(($stats['iterations'] ?? 0) . ' push/pop cycles')->fontSize(14)->color('#94A3B8')
        );

        $cardContent->addChild(Spacer::make()->height(4));
        $cardContent->addChild(
            Row::make(
                $this->statChip('push', (float) ($stats['avg_push_ms'] ?? 0), '#38BDF8'),
                $this->statChip('pop', (float) ($stats['avg_pop_ms'] ?? 0), '#A78BFA'),
            )->fillWidth()->gap(8)
        );
        $cardContent->addChild(
            Row::make(
                $this->statChip('p95 push', (float) ($stats['p95_push_ms'] ?? 0), '#F59E0B'),
                $this->statChip('p95 pop', (float) ($stats['p95_pop_ms'] ?? 0), '#EF4444'),
            )->fillWidth()->gap(8)
        );

        $frameData = $stats['frame_data'] ?? null;
        if ($frameData) {
            $fData = is_string($frameData) ? json_decode($frameData, true) : $frameData;
            $frames = $fData['frame_times_ms'] ?? null;
            if ($frames) {
                $cardContent->addChild(Spacer::make()->height(6));
                $cardContent->addChild(
                    Text::make('FRAME QUALITY')->fontSize(12)->fontWeight(7)->color('#64748B')
                );
                $jankCount = (int) ($frames['jank_count'] ?? 0);
                $fps = (float) ($frames['fps'] ?? 0);
                $jankRate = (float) ($frames['jank_rate'] ?? 0) * 100;
                $jankColor = $jankRate > 20 ? '#EF4444' : ($jankRate > 5 ? '#F59E0B' : '#10B981');
                $fpsColor = $fps > 60 ? '#10B981' : ($fps > 30 ? '#F59E0B' : '#EF4444');
                $cardContent->addChild(
                    Row::make(
                        $this->statChip('FPS', $fps, $fpsColor, 'x'),
                        $this->statChip('jank', (float) $jankCount, $jankColor, 'none'),
                        $this->statChip('p95', (float) ($frames['p95'] ?? 0), '#A78BFA'),
                    )->fillWidth()->gap(8)
                );
            }
        }

        return $cardContent;
    }

    protected function renderPhpBenchCard(string $title, array $stats): Element
    {
        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make($title)->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );

        $cardContent->addChild(Spacer::make()->height(4));
        $cardContent->addChild(
            Row::make(
                $this->statChip('Total', $stats['avg_total'], '#38BDF8'),
                $this->statChip('p50', $stats['p50_total'], '#A78BFA'),
                $this->statChip('p95', $stats['p95_total'], '#F59E0B'),
            )->fillWidth()->gap(8)
        );

        $cardContent->addChild(
            Row::make(
                $this->statChip('render', $stats['avg_render'], '#10B981'),
                $this->statChip('toArray', $stats['avg_toArray'], '#F59E0B'),
                $this->statChip('publish', $stats['avg_publish'], '#38BDF8'),
            )->fillWidth()->gap(8)
        );

        $cardContent->addChild(Spacer::make()->height(4));
        $cardContent->addChild(
            Text::make(sprintf(
                'min %.2fms  ·  max %.2fms',
                $stats['min_total'],
                $stats['max_total'],
            ))->fontSize(12)->color('#64748B')
        );

        return $cardContent;
    }

    protected function statChip(string $label, float $value, string $color, string $unit = 'ms'): Element
    {
        $formatted = match ($unit) {
            'none' => sprintf('%.0f', $value),
            '%' => sprintf('%.1f%%', $value),
            'x' => sprintf('%.1f', $value),
            default => $value < 0.01
                ? sprintf('%.0fμs', $value * 1000)
                : sprintf('%.2fms', $value),
        };

        return Column::make(
            Text::make($label)->fontSize(11)->fontWeight(5)->color('#94A3B8')->textAlign(1),
            Text::make($formatted)->fontSize(18)->fontWeight(7)->color($color)->textAlign(1),
        )->bg('#0F172A')->borderRadius(10)->padding(12, 16)->gap(3)->flexGrow(1)->center();
    }
}
