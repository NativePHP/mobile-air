<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Row;
use Native\Mobile\Edge\Elements\ScrollView;
use Nativephp\ComposeUi\Elements\Button;
use Nativephp\ComposeUi\Elements\Divider;
use Nativephp\ComposeUi\Elements\Icon;
use Nativephp\ComposeUi\Elements\ListItem;
use Nativephp\ComposeUi\Elements\Spacer;
use Nativephp\ComposeUi\Elements\Text;
use Nativephp\ComposeUi\Elements\TextInput;
use Nativephp\ComposeUi\Elements\Toggle;

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

    const DIFF_AB_ITERATIONS = 100;

    const JSON_RECORD_COUNT = 10_000;
    const JSON_PARSE_ITERATIONS = 20;
    const LARGE_LIST_ITEM_COUNT = 10_000;

    const SCENARIOS = [
        'counter_tap'   => 'Counter Tap',
        'large_tree_tap' => 'Large Tree Tap',
        'text_input'    => 'Text Input',
        'list_scroll'   => 'Large List Render',
        'json_parse'    => 'JSON 10k Parse',
        'large_list_fps' => 'List 10k FPS',
        'rapid_fire'    => 'Rapid-Fire',
        'navigation'    => 'Navigation',
        'toggle_tree'   => 'Toggle Tree',
        'diff_ab'       => 'Diff A/B Test',
        'render'        => 'PHP Render',
        'stream_render' => 'Streaming Render',
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
            'large_list_fps' => $this->renderLargeListFpsScreen(),
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
            if (($event['type'] ?? -1) === 8) {
                $this->back();
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
            if (($event['type'] ?? -1) === 8) {
                $this->backToMenu();
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
                'json_parse'     => $this->runJsonParse(),
                'large_list_fps' => $this->runLargeListFps(),
                'rapid_fire'     => $this->runRapidFire(),
                'navigation'     => $this->runNavigation(),
                'toggle_tree'    => $this->runToggleTree(),
                'diff_ab'        => $this->runDiffAB(),
                'render'         => $this->runRenderBenchmark(),
                'stream_render'  => $this->runStreamRenderBenchmark(),
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

    // ── Scenario 5a: JSON 10k Parse ─────────────────

    protected function runJsonParse(): void
    {
        $this->publishProgressScreen('JSON 10k Parse', 'Generating ' . self::JSON_RECORD_COUNT . ' records...');
        usleep(50_000);

        // Generate a realistic JSON dataset: 10k records with nested fields
        $records = [];
        for ($i = 0; $i < self::JSON_RECORD_COUNT; $i++) {
            $records[] = [
                'id' => $i,
                'name' => "User #{$i}",
                'email' => "user{$i}@example.com",
                'age' => 18 + ($i % 60),
                'active' => $i % 3 !== 0,
                'score' => round($i * 1.7 % 100, 2),
                'tags' => ['tag_' . ($i % 5), 'tag_' . ($i % 7)],
                'address' => [
                    'city' => ['NYC', 'LA', 'CHI', 'HOU', 'PHX'][$i % 5],
                    'zip' => str_pad((string) ($i % 99999), 5, '0', STR_PAD_LEFT),
                ],
            ];
        }

        // Encode to JSON string
        $t0 = microtime(true);
        $jsonString = json_encode($records);
        $encodeMs = (microtime(true) - $t0) * 1000;
        $jsonSize = strlen($jsonString);

        $this->publishProgressScreen('JSON 10k Parse', 'Parsing ' . round($jsonSize / 1024) . 'KB...');

        // Benchmark: decode JSON multiple times
        $decodeTimes = [];
        for ($i = 0; $i < self::JSON_PARSE_ITERATIONS; $i++) {
            $t0 = microtime(true);
            $decoded = json_decode($jsonString, true);
            $decodeTimes[] = (microtime(true) - $t0) * 1000;
        }

        // Benchmark: iterate + filter (simulating real work after parse)
        $filterTimes = [];
        for ($i = 0; $i < self::JSON_PARSE_ITERATIONS; $i++) {
            $decoded = json_decode($jsonString, true);
            $t0 = microtime(true);
            $filtered = array_filter($decoded, fn ($r) => $r['active'] && $r['score'] > 50);
            $count = count($filtered);
            $filterTimes[] = (microtime(true) - $t0) * 1000;
        }

        // Benchmark: decode + render as list items
        $renderTimes = [];
        for ($iter = 0; $iter < 5; $iter++) {
            $decoded = json_decode($jsonString, true);
            $this->callbacks = new CallbackRegistry;

            $t0 = microtime(true);
            $root = Column::make()->fill()->safeArea();
            $scroll = ScrollView::make()->fillWidth()->flexGrow(1);
            $content = Column::make()->fillWidth();

            // Render first 1000 items (rendering 10k would be excessive for the tree)
            $renderCount = min(1000, count($decoded));
            for ($i = 0; $i < $renderCount; $i++) {
                $r = $decoded[$i];
                $content->addChild(
                    ListItem::make($r['name'])
                        ->supporting($r['email'] . ' · ' . $r['address']['city'])
                );
            }

            $scroll->addChild($content);
            $root->addChild($scroll);

            $tree = $root->toArray($this->callbacks);
            nativephp_element_publish($tree);
            $renderTimes[] = (microtime(true) - $t0) * 1000;
        }

        sort($decodeTimes);
        sort($filterTimes);
        sort($renderTimes);
        $decodeCount = count($decodeTimes);
        $filterCount = count($filterTimes);
        $renderCount = count($renderTimes);

        $this->results['json_parse'] = [
            'record_count' => self::JSON_RECORD_COUNT,
            'json_size_kb' => round($jsonSize / 1024, 1),
            'encode_ms' => round($encodeMs, 2),
            'decode_avg_ms' => round(array_sum($decodeTimes) / $decodeCount, 2),
            'decode_min_ms' => round($decodeTimes[0], 2),
            'decode_max_ms' => round($decodeTimes[$decodeCount - 1], 2),
            'decode_p95_ms' => round($decodeTimes[(int) floor($decodeCount * 0.95)], 2),
            'filter_avg_ms' => round(array_sum($filterTimes) / $filterCount, 2),
            'filter_result_count' => $count,
            'render_avg_ms' => round(array_sum($renderTimes) / $renderCount, 2),
            'iterations' => self::JSON_PARSE_ITERATIONS,
        ];
    }

    // ── Scenario 5b: Large List 10k FPS ──────────────

    /** Flag set when user taps Done on the 10k list screen */
    protected function renderLargeListFpsScreen(): Element
    {
        $icons = ['home', 'star', 'settings', 'search', 'person', 'favorite'];
        $itemCount = self::LARGE_LIST_ITEM_COUNT;

        $root = Column::make()->fill()->safeArea()->bg('#0F172A');

        $root->addChild(
            Column::make(
                Row::make(
                    Button::make('Back')->onPress('skipScenario')->color('#334155')->labelColor('#94A3B8'),
                    Spacer::make()->flexGrow(1),
                    Text::make('AUTO-SCROLLING...')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                )->fillWidth()->gap(8),
                Text::make('LIST 10K FPS')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                Text::make('Auto-scrolling through ' . number_format($itemCount) . ' items')->fontSize(12)->color('#64748B'),
            )->fillWidth()->padding(12)->gap(6)
        );

        $scroll = ScrollView::make()->fillWidth()->flexGrow(1)->autoScrollTo($itemCount - 1);
        $content = Column::make()->fillWidth()->gap(0);

        for ($i = 0; $i < $itemCount; $i++) {
            $content->addChild(
                ListItem::make("Item #{$i}")
                    ->supporting("Description for list item {$i}")
                    ->leadingIcon($icons[$i % 6])
            );
        }

        $scroll->addChild($content);
        $root->addChild($scroll);

        return $root;
    }

    protected function runLargeListFps(): void
    {
        $this->phase = 'large_list_fps';

        $itemCount = self::LARGE_LIST_ITEM_COUNT;
        $this->publishProgressScreen('List ' . number_format($itemCount) . ' FPS', "Building {$itemCount}-item list...");
        usleep(100_000); // Let Compose render the progress screen

        // Phase 1: Build + publish the auto-scrolling 10k list
        $this->callbacks = new CallbackRegistry;

        $buildStart = microtime(true);
        $element = $this->renderLargeListFpsScreen();
        $buildMs = (microtime(true) - $buildStart) * 1000;

        $toArrayStart = microtime(true);
        $tree = $element->toArray($this->callbacks);
        $toArrayMs = (microtime(true) - $toArrayStart) * 1000;

        // Start FPS capture before publishing so we catch the initial render
        nativephp_call('Perf.Enable', '{}');
        nativephp_call('Perf.StartCaptureWindow', '{}');

        $publishStart = microtime(true);
        nativephp_element_publish($tree);
        $publishMs = (microtime(true) - $publishStart) * 1000;

        // Phase 2: Wait for auto-scroll to complete (~7.5s: 0.5s delay + 6s scroll + 1s buffer)
        $scrollTimeout = 7.5;
        $startTime = microtime(true);

        while ($this->running && (microtime(true) - $startTime) < $scrollTimeout) {
            $remaining = $scrollTimeout - (microtime(true) - $startTime);
            $timeoutMs = (int) ($remaining * 1000);
            if ($timeoutMs <= 0) break;

            $event = nativephp_element_wait_event(min($timeoutMs, 500));
            if ($event === null) continue;

            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->running = false;
                break;
            }
            if (($event['type'] ?? -1) === 8) {
                $this->skipScenario();
                break;
            }
        }

        // Phase 3: Export captured FPS data
        $captureResult = nativephp_call('Perf.StopCaptureWindow', '{}');
        nativephp_call('Perf.Disable', '{}');

        $frameData = json_decode($captureResult, true)['data'] ?? '{}';

        $this->results['large_list_fps'] = [
            'item_count' => self::LARGE_LIST_ITEM_COUNT,
            'build_ms' => round($buildMs, 2),
            'toArray_ms' => round($toArrayMs, 2),
            'publish_ms' => round($publishMs, 2),
            'total_initial_ms' => round($buildMs + $toArrayMs + $publishMs, 2),
            'frame_data' => $frameData,
        ];
    }

    // ── Scenario 6: Rapid-Fire ──────────────────────

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

            // Simulate push: reset buffers, publish new tree (no transition)
            nativephp_element_reset();

            $element = Column::make(
                Text::make("Screen #{$i} - Forward")->fontSize(20)->fontWeight(6)->color('#2563EB'),
                Text::make("Navigation benchmark iteration {$i}")->fontSize(14)->color('#6B7280'),
            )->fill()->center()->safeArea();

            $tree = $element->toArray($this->callbacks);
            nativephp_element_publish($tree);
            $t1 = microtime(true);

            // Simulate pop: reset buffers, publish original tree (no transition)
            $this->callbacks = new CallbackRegistry;
            nativephp_element_reset();

            $element = Column::make(
                Text::make("Screen #{$i} - Back")->fontSize(20)->fontWeight(6)->color('#059669'),
                Text::make("Returning from iteration {$i}")->fontSize(14)->color('#6B7280'),
            )->fill()->center()->safeArea();

            $tree = $element->toArray($this->callbacks);
            nativephp_element_publish($tree);
            $t2 = microtime(true);

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

    // ── Scenario 8: Diff A/B Test ─────────────────────

    protected function runDiffAB(): void
    {
        $iterations = self::DIFF_AB_ITERATIONS;
        $warmup = 30;

        // ── Warmup: run both paths to warm JIT/caches ──
        $this->publishProgressScreen('Diff A/B', "Warming up ({$warmup} iterations)...");
        $this->counter = 0;
        $this->interactionCount = 0;
        $this->phase = 'large_tree_tap';

        for ($i = 0; $i < $warmup && $this->running; $i++) {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->renderLargeTreeTapScreen()->toArray($this->callbacks);
            nativephp_element_publish($tree);
            usleep(20_000);
            $cbId = $this->callbacks->lookup('onTap');
            if ($cbId === null) break;
            $event = $this->simulatePress($cbId);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->running = false;
                return;
            }
            $this->dispatch($event);
        }

        // ── Pass 1: Diff OFF (run first to avoid warmup advantage) ──
        $this->publishProgressScreen('Diff A/B', "Pass 1/{$iterations}: diff OFF");
        nativephp_call('Perf.SetDiffEnabled', json_encode(['enabled' => false]));

        $this->counter = 0;
        $this->interactionCount = 0;

        nativephp_call('Perf.Enable', '{}');

        for ($i = 0; $i < $iterations && $this->running; $i++) {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->renderLargeTreeTapScreen()->toArray($this->callbacks);
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

        $diffOffResult = nativephp_call('Perf.Export', '{}');
        nativephp_call('Perf.Disable', '{}');

        // ── Pass 2: Diff ON ──
        $this->publishProgressScreen('Diff A/B', "Pass 2/{$iterations}: diff ON");
        nativephp_call('Perf.SetDiffEnabled', json_encode(['enabled' => true]));

        $this->counter = 0;
        $this->interactionCount = 0;

        nativephp_call('Perf.Enable', '{}');

        for ($i = 0; $i < $iterations && $this->running; $i++) {
            $this->callbacks = new CallbackRegistry;
            $tree = $this->renderLargeTreeTapScreen()->toArray($this->callbacks);
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

        $diffOnResult = nativephp_call('Perf.Export', '{}');
        nativephp_call('Perf.Disable', '{}');

        // Restore diff enabled
        nativephp_call('Perf.SetDiffEnabled', json_encode(['enabled' => true]));

        $this->results['diff_ab'] = [
            'iterations' => $iterations,
            'diff_on' => json_decode($diffOnResult, true)['data'] ?? '{}',
            'diff_off' => json_decode($diffOffResult, true)['data'] ?? '{}',
        ];
    }

    // ── Scenario 9: PHP Render Benchmark (existing) ─

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

    // ── Streaming Render Benchmark ──────────────────

    protected function runStreamRenderBenchmark(): void
    {
        if (! function_exists('nphp_frame_begin')) {
            $this->publishProgressScreen('Streaming Render', 'Skipped — rebuild PHP with streaming functions');
            usleep(1_500_000);

            return;
        }

        $total = count(self::SIZES);
        foreach (self::SIZES as $idx => $size) {
            $step = $idx + 1;
            $this->publishProgressScreen('Streaming Render', "{$size} nodes ({$step}/{$total})");
            usleep(50_000);
            $this->results["stream_{$size}"] = $this->benchmarkStreamSize($size);
        }
    }

    protected function benchmarkStreamSize(int $targetNodes): array
    {
        $timings = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $t0 = microtime(true);

            nphp_frame_begin();
            $nodeCount = 0;
            $this->buildStreamSubtree($targetNodes, $i, 0, $nodeCount);
            $t1 = microtime(true);

            nphp_frame_end();
            $t2 = microtime(true);

            $timings[] = [
                'render' => ($t1 - $t0) * 1000,
                'toArray' => 0.0,
                'publish' => ($t2 - $t1) * 1000,
                'total' => ($t2 - $t0) * 1000,
            ];

            usleep(1_000);
        }

        $measured = array_slice($timings, self::WARMUP);
        $stats = $this->computeStats($measured);

        NativeRouter::debugLog(sprintf(
            'BENCH STREAM nodes=%d iter=%d avg_render=%.2fms avg_publish=%.2fms avg_total=%.2fms p50=%.2fms p95=%.2fms',
            $targetNodes,
            count($measured),
            $stats['avg_render'],
            $stats['avg_publish'],
            $stats['avg_total'],
            $stats['p50_total'],
            $stats['p95_total'],
        ));

        return $stats;
    }

    protected function buildStreamSubtree(int $targetNodes, int $seed, int $depth, int &$nodeCount): void
    {
        $nodeCount++;

        if ($depth >= 8 || $nodeCount >= $targetNodes) {
            $this->streamLeaf($seed + $nodeCount);

            return;
        }

        $branchFactor = 2 + (($seed + $depth) % 4);
        $isRow = ($depth % 2 === 1);
        $type = $isRow ? 'row' : 'column';

        $layout = [];
        if ($depth === 0) {
            $layout = ['width' => 'fill', 'height' => 'fill', 'safe_area' => 1];
        } else {
            $layout = ['width' => 'fill', 'gap' => 4.0];
        }

        nphp_node_open($type, $layout, null, 0, 0);

        $nodesPerChild = max(1, (int) (($targetNodes - $nodeCount) / $branchFactor));

        for ($i = 0; $i < $branchFactor && $nodeCount < $targetNodes; $i++) {
            $this->buildStreamSubtree(
                min($targetNodes, $nodeCount + $nodesPerChild),
                $seed + $i * 7,
                $depth + 1,
                $nodeCount,
            );
        }

        nphp_node_close();
    }

    protected function streamLeaf(int $seed): void
    {
        $leafTypes = ['text', 'button', 'list_item', 'icon', 'divider', 'spacer'];
        $type = $leafTypes[$seed % count($leafTypes)];
        $colors = ['#1F2937', '#DC2626', '#059669', '#2563EB', '#7C3AED', '#D97706'];
        $icons = ['home', 'star', 'settings', 'search', 'person', 'favorite'];

        $props = match ($type) {
            'text' => ['text' => "Item #{$seed}", 'fontSize' => 14, 'color' => $colors[$seed % count($colors)]],
            'button' => ['label' => "Btn #{$seed}", 'color' => $colors[($seed + 1) % count($colors)]],
            'list_item' => ['headline' => "Headline #{$seed}", 'supporting' => "Supporting text for item {$seed}", 'leadingIcon' => $icons[$seed % count($icons)]],
            'icon' => ['name' => $icons[$seed % count($icons)], 'size' => 24, 'color' => $colors[($seed + 2) % count($colors)]],
            'divider' => [],
            'spacer' => [],
        };

        $layout = match ($type) {
            'divider' => ['width' => 'fill'],
            'spacer' => ['height' => 8.0],
            default => null,
        };

        nphp_node_leaf($type, $layout, null, ! empty($props) ? $props : null, 0, 0);
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

        // JSON 10k parse result
        $jsonParse = $this->results['json_parse'] ?? null;
        if ($jsonParse) {
            $content->addChild($this->renderJsonParseCard($jsonParse));
        }

        // Large list 10k FPS result
        $largeListFps = $this->results['large_list_fps'] ?? null;
        if ($largeListFps) {
            $content->addChild($this->renderLargeListFpsCard($largeListFps));
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

        // Diff A/B result
        $diffAB = $this->results['diff_ab'] ?? null;
        if ($diffAB) {
            $content->addChild($this->renderDiffABCard($diffAB));
        }

        // PHP-side render benchmark cards
        $hasRender = false;
        foreach ($this->results as $key => $stats) {
            if (str_starts_with($key, 'render_') && is_array($stats) && isset($stats['avg_total'])) {
                if (! $hasRender) {
                    $content->addChild(Spacer::make()->height(4));
                    $content->addChild(
                        Text::make('PHP RENDER (LEGACY)')->fontSize(13)->fontWeight(7)->color('#38BDF8')
                    );
                    $hasRender = true;
                }
                $nodeCount = str_replace('render_', '', $key);
                $content->addChild($this->renderPhpBenchCard("{$nodeCount} nodes", $stats));
            }
        }

        // Streaming render benchmark cards
        $hasStream = false;
        foreach ($this->results as $key => $stats) {
            if (str_starts_with($key, 'stream_') && is_array($stats) && isset($stats['avg_total'])) {
                if (! $hasStream) {
                    $content->addChild(Spacer::make()->height(4));
                    $content->addChild(
                        Text::make('STREAMING RENDER')->fontSize(13)->fontWeight(7)->color('#10B981')
                    );
                    $hasStream = true;
                }
                $nodeCount = str_replace('stream_', '', $key);

                // Show comparison if legacy result exists
                $legacyKey = "render_{$nodeCount}";
                $legacyStats = $this->results[$legacyKey] ?? null;
                $content->addChild($this->renderStreamBenchCard("{$nodeCount} nodes", $stats, $legacyStats));
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
                $fps = (float) ($jank['effective_fps'] ?? 0);
                $fpsColor = $fps > 60 ? '#10B981' : ($fps > 30 ? '#F59E0B' : '#EF4444');

                $cardContent->addChild(
                    Row::make(
                        $this->statChip('eff. FPS', $fps, $fpsColor, 'x'),
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

            $fps = (float) ($frames['fps'] ?? 0);
            $fpsColor = $fps > 60 ? '#10B981' : ($fps > 30 ? '#F59E0B' : '#EF4444');

            $cardContent->addChild(Spacer::make()->height(4));
            $cardContent->addChild(
                Row::make(
                    $this->statChip('FPS', $fps, $fpsColor, 'x'),
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
                $fps = (float) ($frames['fps'] ?? 0);
                $fpsColor = $fps > 60 ? '#10B981' : ($fps > 30 ? '#F59E0B' : '#EF4444');
                $cardContent->addChild(
                    Row::make(
                        $this->statChip('FPS', $fps, $fpsColor, 'x'),
                        $this->statChip('p95', (float) ($frames['p95'] ?? 0), '#A78BFA'),
                    )->fillWidth()->gap(8)
                );
            }
        }

        return $cardContent;
    }

    protected function renderDiffABCard(array $stats): Element
    {
        $iterations = $stats['iterations'] ?? 0;
        $onRaw = $stats['diff_on'] ?? '{}';
        $offRaw = $stats['diff_off'] ?? '{}';
        $on = is_string($onRaw) ? json_decode($onRaw, true) : $onRaw;
        $off = is_string($offRaw) ? json_decode($offRaw, true) : $offRaw;

        $onLatency = $on['interaction_latency_ms'] ?? [];
        $offLatency = $off['interaction_latency_ms'] ?? [];
        $onDiff = $on['diff_stats'] ?? [];
        $offDiff = $off['diff_stats'] ?? [];

        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make('Diff A/B Test')->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );
        $cardContent->addChild(
            Text::make("{$iterations} large-tree taps per pass")->fontSize(14)->color('#94A3B8')
        );

        // Diff ON section
        $cardContent->addChild(Spacer::make()->height(6));
        $cardContent->addChild(
            Text::make('DIFF ON')->fontSize(12)->fontWeight(7)->color('#10B981')
        );
        $cardContent->addChild(
            Row::make(
                $this->statChip('avg', (float) ($onLatency['average'] ?? 0), '#10B981'),
                $this->statChip('p50', (float) ($onLatency['p50'] ?? 0), '#10B981'),
                $this->statChip('p95', (float) ($onLatency['p95'] ?? 0), '#10B981'),
            )->fillWidth()->gap(8)
        );

        $reuseRatio = (float) ($onDiff['avg_reuse_ratio'] ?? 0) * 100;
        $diffTime = (float) (($onDiff['diff_time_ms'] ?? [])['average'] ?? 0);
        $cardContent->addChild(
            Row::make(
                $this->statChip('reuse', $reuseRatio, '#10B981', '%'),
                $this->statChip('diff', $diffTime, '#10B981'),
            )->fillWidth()->gap(8)
        );

        // Diff OFF section
        $cardContent->addChild(Spacer::make()->height(6));
        $cardContent->addChild(
            Text::make('DIFF OFF')->fontSize(12)->fontWeight(7)->color('#EF4444')
        );
        $cardContent->addChild(
            Row::make(
                $this->statChip('avg', (float) ($offLatency['average'] ?? 0), '#EF4444'),
                $this->statChip('p50', (float) ($offLatency['p50'] ?? 0), '#EF4444'),
                $this->statChip('p95', (float) ($offLatency['p95'] ?? 0), '#EF4444'),
            )->fillWidth()->gap(8)
        );

        // Delta
        $onAvg = (float) ($onLatency['average'] ?? 0);
        $offAvg = (float) ($offLatency['average'] ?? 0);
        if ($offAvg > 0 && $onAvg > 0) {
            $deltaMs = $offAvg - $onAvg;
            $deltaPct = ($deltaMs / $offAvg) * 100;
            $deltaColor = $deltaMs > 0 ? '#10B981' : '#EF4444';

            $cardContent->addChild(Spacer::make()->height(6));
            $cardContent->addChild(
                Text::make('IMPROVEMENT')->fontSize(12)->fontWeight(7)->color('#64748B')
            );
            $cardContent->addChild(
                Row::make(
                    $this->statChip('saved', abs($deltaMs), $deltaColor),
                    $this->statChip('faster', abs($deltaPct), $deltaColor, '%'),
                )->fillWidth()->gap(8)
            );
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

    protected function renderStreamBenchCard(string $title, array $stats, ?array $legacyStats): Element
    {
        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make($title)->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );

        $cardContent->addChild(Spacer::make()->height(4));
        $cardContent->addChild(
            Row::make(
                $this->statChip('Total', $stats['avg_total'], '#10B981'),
                $this->statChip('p50', $stats['p50_total'], '#A78BFA'),
                $this->statChip('p95', $stats['p95_total'], '#F59E0B'),
            )->fillWidth()->gap(8)
        );

        $cardContent->addChild(
            Row::make(
                $this->statChip('build', $stats['avg_render'], '#10B981'),
                $this->statChip('frame_end', $stats['avg_publish'], '#38BDF8'),
            )->fillWidth()->gap(8)
        );

        // Speedup vs legacy
        if ($legacyStats && $legacyStats['avg_total'] > 0) {
            $speedup = $legacyStats['avg_total'] / max(0.001, $stats['avg_total']);
            $speedupColor = $speedup >= 5 ? '#10B981' : ($speedup >= 2 ? '#F59E0B' : '#EF4444');

            $cardContent->addChild(Spacer::make()->height(4));
            $cardContent->addChild(
                Row::make(
                    $this->statChip('legacy', $legacyStats['avg_total'], '#EF4444'),
                    $this->statChip('stream', $stats['avg_total'], '#10B981'),
                    $this->statChip(sprintf('%.1fx', $speedup), $speedup, $speedupColor, 'x'),
                )->fillWidth()->gap(8)
            );
        }

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

    protected function renderJsonParseCard(array $stats): Element
    {
        $ours = (float) ($stats['decode_avg_ms'] ?? 0);
        $reactNative = 45.0;
        $flutter = 38.0;

        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make('JSON 10k Parse')->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );
        $cardContent->addChild(
            Text::make(($stats['record_count'] ?? 0) . ' records · ' . ($stats['json_size_kb'] ?? 0) . 'KB')->fontSize(14)->color('#94A3B8')
        );

        $cardContent->addChild(Spacer::make()->height(4));
        $cardContent->addChild(
            Text::make('JSON DECODE')->fontSize(12)->fontWeight(7)->color('#64748B')
        );
        $cardContent->addChild(
            Row::make(
                $this->statChip('avg', $ours, '#38BDF8'),
                $this->statChip('min', (float) ($stats['decode_min_ms'] ?? 0), '#10B981'),
                $this->statChip('p95', (float) ($stats['decode_p95_ms'] ?? 0), '#F59E0B'),
            )->fillWidth()->gap(8)
        );

        // Cross-framework comparison
        $cardContent->addChild(Spacer::make()->height(6));
        $cardContent->addChild(
            Text::make('VS OTHER FRAMEWORKS')->fontSize(12)->fontWeight(7)->color('#64748B')
        );

        $oursColor = ($ours <= $flutter) ? '#10B981' : (($ours <= $reactNative) ? '#F59E0B' : '#EF4444');
        $rnColor = ($reactNative < $ours) ? '#10B981' : '#EF4444';
        $flColor = ($flutter < $ours) ? '#10B981' : '#EF4444';

        $cardContent->addChild(
            Row::make(
                $this->statChip('NativePHP', $ours, $oursColor),
                $this->statChip('React Native', $reactNative, $rnColor),
                $this->statChip('Flutter', $flutter, $flColor),
            )->fillWidth()->gap(8)
        );

        if ($ours > 0) {
            $vsRn = (($reactNative - $ours) / $reactNative) * 100;
            $vsFlutter = (($flutter - $ours) / $flutter) * 100;

            $rnDelta = $vsRn > 0
                ? sprintf('%.0f%% faster than RN', $vsRn)
                : sprintf('%.0f%% slower than RN', abs($vsRn));
            $flDelta = $vsFlutter > 0
                ? sprintf('%.0f%% faster than Flutter', $vsFlutter)
                : sprintf('%.0f%% slower than Flutter', abs($vsFlutter));

            $cardContent->addChild(
                Text::make($rnDelta)->fontSize(13)->fontWeight(6)->color($vsRn > 0 ? '#10B981' : '#EF4444')
            );
            $cardContent->addChild(
                Text::make($flDelta)->fontSize(13)->fontWeight(6)->color($vsFlutter > 0 ? '#10B981' : '#EF4444')
            );
        }

        $cardContent->addChild(Spacer::make()->height(4));
        $cardContent->addChild(
            Text::make('ENCODE + FILTER + RENDER')->fontSize(12)->fontWeight(7)->color('#64748B')
        );
        $cardContent->addChild(
            Row::make(
                $this->statChip('encode', (float) ($stats['encode_ms'] ?? 0), '#A78BFA'),
                $this->statChip('filter', (float) ($stats['filter_avg_ms'] ?? 0), '#10B981'),
                $this->statChip('render', (float) ($stats['render_avg_ms'] ?? 0), '#EF4444'),
            )->fillWidth()->gap(8)
        );

        $filterCount = (int) ($stats['filter_result_count'] ?? 0);
        $cardContent->addChild(
            Text::make("Filter matched {$filterCount} of " . ($stats['record_count'] ?? 0) . ' records')->fontSize(12)->color('#64748B')
        );

        return $cardContent;
    }

    protected function renderLargeListFpsCard(array $stats): Element
    {
        $cardContent = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);

        $cardContent->addChild(
            Text::make('List 10k FPS')->fontSize(20)->fontWeight(7)->color('#F1F5F9')
        );
        $cardContent->addChild(
            Text::make(($stats['item_count'] ?? 0) . ' items · scroll FPS capture')->fontSize(14)->color('#94A3B8')
        );

        // Initial render pipeline
        $cardContent->addChild(Spacer::make()->height(4));
        $cardContent->addChild(
            Text::make('INITIAL RENDER')->fontSize(12)->fontWeight(7)->color('#64748B')
        );
        $cardContent->addChild(
            Row::make(
                $this->statChip('build', (float) ($stats['build_ms'] ?? 0), '#10B981'),
                $this->statChip('toArray', (float) ($stats['toArray_ms'] ?? 0), '#F59E0B'),
                $this->statChip('publish', (float) ($stats['publish_ms'] ?? 0), '#38BDF8'),
            )->fillWidth()->gap(8)
        );
        $cardContent->addChild(
            Row::make(
                $this->statChip('total', (float) ($stats['total_initial_ms'] ?? 0), '#A78BFA'),
            )->fillWidth()->gap(8)
        );

        // Frame data from Compose
        $frameRaw = $stats['frame_data'] ?? null;
        $frames = null;
        if ($frameRaw) {
            $fData = is_string($frameRaw) ? json_decode($frameRaw, true) : $frameRaw;
            $frames = $fData['frame_times_ms'] ?? null;
        }

        if ($frames) {
            $cardContent->addChild(Spacer::make()->height(6));
            $cardContent->addChild(
                Text::make('COMPOSE FPS')->fontSize(12)->fontWeight(7)->color('#64748B')
            );

            $fps = (float) ($frames['fps'] ?? 0);
            $fpsColor = $fps > 60 ? '#10B981' : ($fps > 30 ? '#F59E0B' : '#EF4444');

            $cardContent->addChild(
                Row::make(
                    $this->statChip('FPS', $fps, $fpsColor, 'x'),
                    $this->statChip('avg', (float) ($frames['average'] ?? 0), '#38BDF8'),
                    $this->statChip('p95', (float) ($frames['p95'] ?? 0), '#F59E0B'),
                )->fillWidth()->gap(8)
            );
        } else {
            $cardContent->addChild(Spacer::make()->height(4));
            $cardContent->addChild(
                Text::make('No Compose frame data captured')->fontSize(13)->color('#64748B')
            );
        }

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
