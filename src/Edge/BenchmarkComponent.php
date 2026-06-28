<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Divider;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\Row;
use Native\Mobile\Edge\Elements\ScrollView;
use Native\Mobile\Edge\Elements\Spacer;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TextInput;

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

    const JSON_RECORD_COUNT = 10_000;
    const JSON_PARSE_ITERATIONS = 20;
    const LARGE_LIST_ITEM_COUNT = 10_000;

    /**
     * Scenarios in run order. Labels are user-facing; keys are stable
     * identifiers (don't rename — used as result-array keys + dispatch
     * names downstream).
     *
     * `navigation` is the historical key for what's actually a "tree
     * replace" benchmark (two consecutive publishes with a buffer reset
     * between, no real router involvement). Renaming the key would
     * invalidate saved benchmark runs; we keep the key and call out the
     * scope in the label.
     */
    const SCENARIOS = [
        'counter_tap'    => 'Counter Tap',
        'large_tree_tap' => 'Large Tree Tap',
        'text_input'     => 'Text Input',
        'list_scroll'    => 'Large List Render',
        'json_parse'     => 'JSON 10k Parse',
        'large_list_fps' => 'List 10k FPS',
        'rapid_fire'     => 'Rapid-Fire',
        'navigation'     => 'Tree Replace (push/pop sim)',
        'toggle_tree'    => 'Toggle Tree',
        'render'         => 'PHP Render (tree builder)',
        'stream_render'  => 'PHP Render (streaming)',
    ];

    /**
     * Per-scenario explainer payload — shown inline on each result card
     * so the user understands what they're looking at without leaving
     * the screen.
     *
     * Each entry has:
     *   - `measures`:      one-paragraph statement of what is measured
     *   - `methodology`:   how the scenario actually drives the system
     *   - `metric_meaning`: dict of metric name → human-readable gloss
     *   - `reference`:     ordered list of comparison rows (framework + note)
     *
     * Reference numbers are deliberately ranges, not single points —
     * published benchmarks vary by device, OS version, and methodology.
     * Sources: SynergyBoat 2025–2026 benchmark series, React Native
     * Fabric / TurboModules docs, Flutter DeviceLab, Apple WWDC perf
     * sessions.
     */
    const SCENARIO_INFO = [
        'counter_tap' => [
            'measures' => 'End-to-end press → render round-trip latency on a minimal screen. Lower is better. This is the "tap a button, screen updates" baseline that every framework optimizes for.',
            'methodology' => '100 simulated press events on a counter. Each press fires from native through the bridge into PHP, PHP dispatches the handler, increments state, re-renders the tree, publishes the new tree, native diffs + paints. Total RTT is measured from press-send to frame-drawn.',
            'metric_meaning' => [
                'avg' => 'Mean round-trip across all 100 presses (ms).',
                'p50 / p95 / p99' => 'Percentiles. p95 = 95% of presses were faster than this. Tail latency matters for perceived responsiveness.',
                'event_delivery' => 'Native press → PHP event loop. Transport + serialization cost.',
                'compose_post' => 'PHP-published tree → native UI thread. Diff + dispatch.',
                'frame_paint' => 'UI thread post → frame visible. SwiftUI / Compose render.',
            ],
            'reference' => [
                ['framework' => 'Native Swift / Kotlin',         'note' => '~5–15ms typical'],
                ['framework' => 'Flutter',                        'note' => '~8–20ms (Skia, platform thread)'],
                ['framework' => 'React Native (Fabric, Hermes)', 'note' => '~30–50ms — JS bridge + renderer + Yoga'],
                ['framework' => 'React Native (Expo)',           'note' => '~30–60ms — same engine, slight cold-path tax'],
            ],
        ],
        'large_tree_tap' => [
            'measures' => 'Press → render RTT when the tree being rebuilt has ~200 nodes. Probes whether the framework\'s diff / reconciliation degrades under tree-size load.',
            'methodology' => 'Same loop as Counter Tap but with a 200-node tree (rows of bullets + text + values) on screen. Each press triggers a full re-render of the tree.',
            'metric_meaning' => [
                'avg' => 'Mean RTT under 200-node tree load.',
                'p95 / p99' => 'Tail latency — captures GC pauses, allocator pressure, etc.',
            ],
            'reference' => [
                ['framework' => 'Native Swift / Kotlin',         'note' => '~10–25ms typical'],
                ['framework' => 'Flutter',                        'note' => '~15–35ms'],
                ['framework' => 'React Native (Fabric)',         'note' => '~40–80ms — diff cost is super-linear in tree size before Fabric'],
                ['framework' => 'React Native (Expo)',           'note' => '~40–90ms'],
            ],
        ],
        'text_input' => [
            'measures' => 'Per-keystroke RTT for text input. Critical for typing feel — anything above ~30ms feels laggy.',
            'methodology' => '50 simulated character changes appended to a `<text_input>` field. Each character fires a text-change event through the bridge, PHP updates state, tree republishes.',
            'metric_meaning' => [
                'avg' => 'Mean per-keystroke RTT (ms).',
                'p95' => 'Tail-end keystrokes — if this exceeds 30ms users feel hitching.',
            ],
            'reference' => [
                ['framework' => 'Native UITextField / EditText', 'note' => '~5–15ms typical'],
                ['framework' => 'Flutter TextField',              'note' => '~10–25ms'],
                ['framework' => 'React Native TextInput',         'note' => '~25–50ms per keystroke (controlled component)'],
            ],
        ],
        'list_scroll' => [
            'measures' => 'Cost of repeatedly re-rendering a 1000-item list. Probes the framework\'s diff path under list-shaped trees.',
            'methodology' => '100 back-to-back full publishes of a 1000-row list where each row\'s text rotates by frame index. Forces the bridge to diff every row each frame.',
            'metric_meaning' => [
                'fps' => 'Effective frames-per-second during the burst. Higher is better.',
                'avg / p95 / p99' => 'Per-frame latency. 16.67ms is the 60Hz budget.',
                'drop%' => 'Frames slower than 16.67ms. Lower is better.',
                'jank%' => 'Frames slower than the jank threshold (2× target). Lower is better.',
                'CoV' => 'Coefficient of variation. Lower = smoother frame timing.',
            ],
            'reference' => [
                ['framework' => 'Native UICollectionView',       'note' => '~58–60 fps stable on 60Hz'],
                ['framework' => 'Flutter ListView',               'note' => '~60 fps, p95 ~2–5ms (article-cited)'],
                ['framework' => 'React Native FlatList',         'note' => '~55–58 fps; ~15% dropped on iOS 60Hz'],
            ],
        ],
        'json_parse' => [
            'measures' => 'Pure PHP-side JSON encode / decode / filter / render throughput. No native involvement.',
            'methodology' => 'Builds 10,000 records with nested fields. Times one `json_encode`, then 20 `json_decode`s, then 20 filter passes, then 5 decode+render-1000-list-items cycles.',
            'metric_meaning' => [
                'encode_ms' => 'One-time encode of 10k records to JSON string.',
                'decode_avg_ms' => 'Mean of 20 `json_decode` runs.',
                'decode_p95_ms' => 'Tail decode latency.',
                'filter_avg_ms' => 'Iterate + `array_filter` on 10k decoded records.',
                'render_avg_ms' => 'Decode + build element tree for first 1000 items.',
            ],
            'reference' => [
                ['framework' => 'PHP json_decode (native ext)',   'note' => 'this benchmark IS the comparison — no framework equivalent'],
                ['framework' => 'JS JSON.parse (RN / Expo)',      'note' => '10k records typically 5–15ms (V8 / Hermes)'],
            ],
        ],
        'large_list_fps' => [
            'measures' => 'Sustained FPS while auto-scrolling a 10,000-row list end-to-end. The canonical scrolling-list benchmark.',
            'methodology' => 'Builds a 10k-item list once, publishes, then auto-scrolls to the last row over ~6 seconds. FPS is captured between Perf.StartCaptureWindow / Perf.StopCaptureWindow so only scroll frames count.',
            'metric_meaning' => [
                'fps' => 'Mean FPS during the scroll capture window.',
                'avg / p95 / p99' => 'Per-frame interval (ms).',
                'drop% / jank%' => 'Frames missing the 60Hz budget.',
                'CoV' => 'Smoothness measure — lower is steadier.',
                'build_ms / toArray_ms / publish_ms' => 'PHP-side cost of building the initial 10k tree (one-shot).',
            ],
            'reference' => [
                ['framework' => 'Native Swift (60Hz, 100 rows)',  'note' => 'avg ~17.2ms, p95 ~16.7ms, ~58.5 fps (SynergyBoat 2025)'],
                ['framework' => 'Flutter (60Hz, 100 rows)',       'note' => 'avg ~1.7ms, p95 ~2.5ms, ~59.3 fps (SynergyBoat 2025)'],
                ['framework' => 'React Native (60Hz, 100 rows)',  'note' => 'avg ~16.7ms, ~57.5 fps, ~15% dropped (SynergyBoat 2025)'],
                ['framework' => 'Native Kotlin (120Hz)',          'note' => 'avg ~8.3ms, ~119.8 fps (SynergyBoat 2025)'],
            ],
        ],
        'rapid_fire' => [
            'measures' => 'PHP-side publish throughput with no event-wait between iterations. Probes the max events/sec the framework can sustain.',
            'methodology' => '500 publishes of a small tree, back to back, no event loop pause. Times render, toArray, and publish per iteration. Discards first 10 as warmup.',
            'metric_meaning' => [
                'evt/s' => 'Sustained events per second. Higher is better.',
                'avg / p95' => 'Per-iteration total time (render + toArray + publish).',
                'render / toArray / publish' => 'Per-phase breakdown of the publish pipeline.',
            ],
            'reference' => [
                ['framework' => 'Native programmatic updates',    'note' => 'typically 500–2000 evt/s, very implementation-dependent'],
                ['framework' => 'React Native bridge',            'note' => '~60–120 evt/s for full re-renders'],
            ],
        ],
        'navigation' => [
            'measures' => 'Cost of replacing one full tree with another — the dominant cost of a real navigation transition.',
            'methodology' => '20 cycles of: reset bridge buffers + publish "Screen A", then reset + publish "Screen B". Times each push / pop separately. NOT a real router navigation (no transitions, no nav stack), just the tree-replace cost in isolation.',
            'metric_meaning' => [
                'avg_push_ms / avg_pop_ms' => 'Mean time for one tree replacement.',
                'p95_push_ms / p95_pop_ms' => 'Tail latency for tree replace.',
            ],
            'reference' => [
                ['framework' => 'Native nav controllers',         'note' => '~5–15ms for tree mount, transitions are separate'],
                ['framework' => 'Flutter Navigator',              'note' => '~10–25ms for route push'],
                ['framework' => 'React Navigation',               'note' => '~30–60ms route push (Fabric, no animation)'],
            ],
        ],
        'toggle_tree' => [
            'measures' => 'RTT when a state change adds or removes ~200 nodes from the tree. Exercises the diff path harder than a simple counter — half the iterations mount a subtree, half unmount it.',
            'methodology' => '50 simulated toggle events. Each one flips a boolean that the render function uses to include or exclude a 200-node subtree.',
            'metric_meaning' => [
                'avg / p95 / p99' => 'Per-toggle RTT (ms).',
                'event_delivery / compose_post / frame_paint' => 'Pipeline breakdown — see Counter Tap for definitions.',
            ],
            'reference' => [
                ['framework' => 'Native Swift / Kotlin',         'note' => '~10–25ms for 200-node mount'],
                ['framework' => 'React Native (Fabric)',         'note' => '~40–80ms — mount/unmount stresses the renderer harder than re-render'],
            ],
        ],
        'render' => [
            'measures' => 'Pure PHP-side cost of building and converting an element tree to its wire array — no native publish. Useful for separating PHP overhead from native overhead.',
            'methodology' => 'For each size in [10, 50, 100, 500] nodes: 100 iterations of `render() → toArray()`. Discards first 10 as warmup. Times the render call, the toArray serialization, and the total. Trees are built via the fluent element builder.',
            'metric_meaning' => [
                'avg_total' => 'Mean total time per iteration (ms).',
                'p50 / p95' => 'Median and tail per-iteration time.',
                'avg_render' => 'Time spent in the Blade-style fluent tree builder.',
                'avg_toArray' => 'Time spent serializing the tree to a wire array.',
            ],
            'reference' => [
                ['framework' => 'PHP-only test',                  'note' => 'no direct framework analog — compare against Streaming below'],
            ],
        ],
        'stream_render' => [
            'measures' => 'Same workload as Render, but using streaming-mode functions (`nphp_frame_begin` / `nphp_node_open` / `nphp_frame_end`) that write directly into the bridge buffer instead of constructing an intermediate Element tree. Measures whether streaming actually wins.',
            'methodology' => 'Same per-size iteration count and warmup as Render. Trees are built procedurally via the streaming API. `toArray` cost is zero because there\'s no intermediate tree.',
            'metric_meaning' => [
                'avg_total' => 'Mean total time per streaming iteration (ms).',
                'avg_render' => 'Time spent in the streaming `nphp_node_*` calls.',
                'avg_publish' => 'Time spent in `nphp_frame_end` flushing the buffer.',
                'vs tree builder' => 'Speedup over the Render scenario at the same node count.',
            ],
            'reference' => [
                ['framework' => 'PHP-only test',                  'note' => 'compare directly against the Render rows above'],
            ],
        ],
    ];

    /**
     * One-line description per scenario — what it actually measures.
     * Shown under each menu button so users aren't surprised by the
     * methodology. Keep concise; full detail belongs in commit / docs.
     */
    const SCENARIO_DESCRIPTIONS = [
        'counter_tap'    => '100 simulated taps on a minimal screen — measures press→render round-trip',
        'large_tree_tap' => '100 taps with a 200-node tree mounted — RTT under build/diff load',
        'text_input'     => '50 simulated text changes — measures text-event RTT through the bridge',
        'list_scroll'    => 'Re-renders a 1000-item list 100× back to back — diff/publish cost',
        'json_parse'     => 'PHP-only: encode 10k records, decode 20×, filter, render first 1000',
        'large_list_fps' => 'Publishes a 10000-item list with auto-scroll — captures FPS during scroll',
        'rapid_fire'     => '500 publishes with no event wait — PHP throughput + events/sec',
        'navigation'     => '20 cycles of tree-reset → publish A → reset → publish B (NOT real router nav)',
        'toggle_tree'    => '50 toggles flipping a 200-node subtree on/off',
        'render'         => 'PHP-only: build & toArray() trees of 10/50/100/500 nodes (no native publish)',
        'stream_render'  => 'PHP-only: same as Render but streaming directly into the bridge buffer',
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

        // Back-to-launcher chevron — the component overlays the
        // StackLayout's back chrome with its own dark theme so we need
        // our own way out.
        $content->addChild(
            Pressable::make(
                Row::make(
                    Text::make('<')->fontSize(20)->fontWeight(7)->color('#94A3B8'),
                    Spacer::make()->width(8),
                    Text::make('Back')->fontSize(15)->color('#94A3B8'),
                )->gap(0)
            )->onPress('backToLauncher')->padding(4, 0, 4, 0)
        );

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
            $description = self::SCENARIO_DESCRIPTIONS[$key] ?? '';
            $content->addChild($this->makeScenarioCard($key, $label, $description));
        }

        $content->addChild(Spacer::make()->height(4));
        $content->addChild(
            Pressable::make(
                Text::make('Run All Scenarios')->fontSize(14)->fontWeight(6)->color('#FFFFFF')
            )->fillWidth()->bg('#059669')->borderRadius(10)->padding(14)->center()->onPress('startAll')
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
        $this->nativeCallbacks = new CallbackRegistry;
        $this->nativeRunning = true;
        $this->nativeNavigationIntent = null;
        $this->nativeHasError = false;

        // Set window background to match dark theme behind system bars
        nativephp_call('UI.SetBackground', json_encode(['color' => '#0F172A']));

        while ($this->nativeRunning) {
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
        while ($this->nativeRunning && $this->phase === 'menu') {
            $this->nativeCallbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->nativeCallbacks);
            nativephp_element_publish($tree);

            $event = nativephp_element_wait_event(-1);
            if ($event === null) {
                continue;
            }
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->nativeRunning = false;
                break;
            }
            if (($event['type'] ?? -1) === 8) {
                $this->back();
                $this->nativeRunning = false;
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
        while ($this->nativeRunning && $this->phase === 'results') {
            $this->nativeCallbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->nativeCallbacks);
            nativephp_element_publish($tree);

            $event = nativephp_element_wait_event(-1);
            if ($event === null) {
                continue;
            }
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->nativeRunning = false;
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
        while (! empty($this->scenarioQueue) && $this->nativeRunning) {
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
                Pressable::make(Text::make('Back')->fontSize(13)->fontWeight(5)->color('#94A3B8'))->bg('#334155')->borderRadius(8)->padding(8, 14)->onPress('skipScenario'),
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
            Pressable::make(Text::make('+1')->fontSize(16)->fontWeight(7)->color('#38BDF8'))->bg('#1E293B')->borderRadius(10)->padding(14, 28)->onPress('onTap'),
            Spacer::make()->height(1)->flexGrow(1),
        )->fill()->center()->safeArea()->bg('#0F172A');
    }

    protected function runCounterTap(): void
    {
        $this->phase = 'counter_tap';
        $this->counter = 0;
        $this->interactionCount = 0;

        nativephp_call('Perf.Enable', '{}');

        while ($this->nativeRunning && $this->interactionCount < self::TAP_ITERATIONS) {
            $this->nativeCallbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->nativeCallbacks);
            nativephp_element_publish($tree);

            usleep(20_000); // Let Compose render the frame

            $cbId = $this->nativeCallbacks->lookup('onTap');
            if ($cbId === null) break;

            $event = $this->simulatePress($cbId);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->nativeRunning = false;
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
                Pressable::make(Text::make('Back')->fontSize(13)->fontWeight(5)->color('#94A3B8'))->bg('#334155')->borderRadius(8)->padding(8, 14)->onPress('skipScenario'),
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
                Pressable::make(Text::make('+1')->fontSize(16)->fontWeight(7)->color('#38BDF8'))->bg('#1E293B')->borderRadius(10)->padding(14, 28)->onPress('onTap'),
            )->fillWidth()->center()->padding(12)->bg('#0F172A')->borderRadius(8)
        );

        $scroll->addChild($treeContent);
        $root->addChild($scroll);

        return $root;
    }

    protected function buildLargeTreeContent(Element $container, int &$nodeCount): void
    {
        $colors = ['#1F2937', '#DC2626', '#059669', '#2563EB', '#7C3AED', '#D97706'];
        $bullets = ['●', '★', '◆', '▶', '■', '▲'];

        // Generate ~200 nodes as a mix of rows, text, bullets
        while ($nodeCount < self::LARGE_TREE_NODE_COUNT) {
            $row = Row::make()->fillWidth()->gap(8)->padding(4);
            $row->addChild(Text::make($bullets[$nodeCount % count($bullets)])->fontSize(16)->color($colors[$nodeCount % count($colors)]));
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

        while ($this->nativeRunning && $this->interactionCount < self::LARGE_TREE_TAP_ITERATIONS) {
            $this->nativeCallbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->nativeCallbacks);
            nativephp_element_publish($tree);

            usleep(20_000);

            $cbId = $this->nativeCallbacks->lookup('onTap');
            if ($cbId === null) break;

            $event = $this->simulatePress($cbId);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->nativeRunning = false;
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
        // Real `<text_input>` element so the simulated text changes
        // actually appear in a typing affordance — earlier this was a
        // Pressable mocked up to look like an input, which obscured what
        // the test was measuring (the RTT was real, the UI lied).
        return Column::make(
            Row::make(
                Pressable::make(Text::make('Back')->fontSize(13)->fontWeight(5)->color('#94A3B8'))->bg('#334155')->borderRadius(8)->padding(8, 14)->onPress('skipScenario'),
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
                ->fillWidth()->bg('#1E293B')->borderRadius(8)->padding(14),
            Spacer::make()->height(12),
            Text::make($this->textValue ?: 'waiting for input...')->fontSize(14)->color('#64748B'),
            Spacer::make()->height(24),
            Pressable::make(Text::make('Skip')->fontSize(13)->fontWeight(5)->color('#94A3B8'))->bg('#334155')->borderRadius(8)->padding(8, 14)->onPress('skipScenario'),
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

        while ($this->nativeRunning && $this->interactionCount < self::TEXT_INPUT_ITERATIONS) {
            $this->nativeCallbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->nativeCallbacks);
            nativephp_element_publish($tree);

            usleep(20_000);

            $cbId = $this->nativeCallbacks->lookup('onTextChange');
            if ($cbId === null) break;

            // Simulate typing one character at a time
            $nextText = substr($sampleText, 0, $this->interactionCount + 1);
            $event = $this->simulateTextChange($cbId, $nextText);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->nativeRunning = false;
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
                    Pressable::make(Text::make('Back')->fontSize(13)->fontWeight(5)->color('#94A3B8'))->bg('#334155')->borderRadius(8)->padding(8, 14)->onPress('skipScenario'),
                    Spacer::make()->flexGrow(1),
                    Pressable::make(Text::make('Done')->fontSize(13)->fontWeight(5)->color('#FFFFFF'))->bg('#059669')->borderRadius(8)->padding(8, 14)->onPress('onScrollDone'),
                )->fillWidth()->gap(8),
                Text::make('LARGE LIST RENDER')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                Text::make('Scroll through the list, then tap Done')->fontSize(12)->color('#64748B'),
            )->fillWidth()->padding(12)->gap(6)
        );

        $scroll = ScrollView::make()->fillWidth()->flexGrow(1);
        $content = Column::make()->fillWidth()->gap(0);

        for ($i = 0; $i < self::LIST_SCROLL_ITEM_COUNT; $i++) {
            $bullets = ['●', '★', '◆', '▶', '■', '▲'];
            $content->addChild(
                $this->makeListItem("Item #{$i}", "Description for list item {$i}", $bullets[$i % 6])
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

        nativephp_call('Perf.Enable', '{}');
        nativephp_call('Perf.StartCaptureWindow', '{}');

        // Rapidly re-render the 1000-item list with changing content
        // to force Compose to diff and re-render each frame
        for ($frame = 0; $frame < self::LIST_RERENDER_ITERATIONS; $frame++) {
            $this->nativeCallbacks = new CallbackRegistry;

            $root = Column::make()->fill()->safeArea();
            $root->addChild(
                Text::make("Large List Re-render {$frame}/" . self::LIST_RERENDER_ITERATIONS)
                    ->fontSize(16)->fontWeight(6)->color('#1F2937')->padding(12)
            );

            $scroll = ScrollView::make()->fillWidth()->flexGrow(1);
            $content = Column::make()->fillWidth();

            $bullets = ['●', '★', '◆', '▶', '■', '▲'];
            for ($i = 0; $i < self::LIST_SCROLL_ITEM_COUNT; $i++) {
                $content->addChild(
                    $this->makeListItem("Item #" . (($i + $frame) % self::LIST_SCROLL_ITEM_COUNT), "Frame {$frame}", $bullets[$i % 6])
                );
            }

            $scroll->addChild($content);
            $root->addChild($scroll);

            $tree = $root->toArray($this->nativeCallbacks);
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
            $this->nativeCallbacks = new CallbackRegistry;

            $t0 = microtime(true);
            $root = Column::make()->fill()->safeArea();
            $scroll = ScrollView::make()->fillWidth()->flexGrow(1);
            $content = Column::make()->fillWidth();

            // Render first 1000 items (rendering 10k would be excessive for the tree)
            $renderCount = min(1000, count($decoded));
            for ($i = 0; $i < $renderCount; $i++) {
                $r = $decoded[$i];
                $content->addChild(
                    $this->makeListItem($r['name'], $r['email'] . ' · ' . $r['address']['city'])
                );
            }

            $scroll->addChild($content);
            $root->addChild($scroll);

            $tree = $root->toArray($this->nativeCallbacks);
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
        $itemCount = self::LARGE_LIST_ITEM_COUNT;

        $root = Column::make()->fill()->safeArea()->bg('#0F172A');

        $root->addChild(
            Column::make(
                Row::make(
                    Pressable::make(Text::make('Back')->fontSize(13)->fontWeight(5)->color('#94A3B8'))->bg('#334155')->borderRadius(8)->padding(8, 14)->onPress('skipScenario'),
                    Spacer::make()->flexGrow(1),
                    Text::make('AUTO-SCROLLING...')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                )->fillWidth()->gap(8),
                Text::make('LIST 10K FPS')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                Text::make('Auto-scrolling through ' . number_format($itemCount) . ' items')->fontSize(12)->color('#64748B'),
            )->fillWidth()->padding(12)->gap(6)
        );

        $scroll = ScrollView::make()->fillWidth()->flexGrow(1)->autoScrollTo($itemCount - 1);
        $content = Column::make()->fillWidth()->gap(0);

        $bullets = ['●', '★', '◆', '▶', '■', '▲'];
        for ($i = 0; $i < $itemCount; $i++) {
            $content->addChild(
                $this->makeListItem("Item #{$i}", "Description for list item {$i}", $bullets[$i % 6])
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
        $this->nativeCallbacks = new CallbackRegistry;

        $buildStart = microtime(true);
        $element = $this->renderLargeListFpsScreen();
        $buildMs = (microtime(true) - $buildStart) * 1000;

        $toArrayStart = microtime(true);
        $tree = $element->toArray($this->nativeCallbacks);
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

        while ($this->nativeRunning && (microtime(true) - $startTime) < $scrollTimeout) {
            $remaining = $scrollTimeout - (microtime(true) - $startTime);
            $timeoutMs = (int) ($remaining * 1000);
            if ($timeoutMs <= 0) break;

            $event = nativephp_element_wait_event(min($timeoutMs, 500));
            if ($event === null) continue;

            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->nativeRunning = false;
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
            $this->nativeCallbacks = new CallbackRegistry;

            $t0 = microtime(true);
            $element = $this->generateRapidFireTree($i);
            $t1 = microtime(true);

            $tree = $element->toArray($this->nativeCallbacks);
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
            $this->nativeCallbacks = new CallbackRegistry;

            $t0 = microtime(true);

            // Simulate push: reset buffers, publish new tree (no transition)
            nativephp_element_reset();

            $element = Column::make(
                Text::make("Screen #{$i} - Forward")->fontSize(20)->fontWeight(6)->color('#2563EB'),
                Text::make("Navigation benchmark iteration {$i}")->fontSize(14)->color('#6B7280'),
            )->fill()->center()->safeArea();

            $tree = $element->toArray($this->nativeCallbacks);
            nativephp_element_publish($tree);
            $t1 = microtime(true);

            // Simulate pop: reset buffers, publish original tree (no transition)
            $this->nativeCallbacks = new CallbackRegistry;
            nativephp_element_reset();

            $element = Column::make(
                Text::make("Screen #{$i} - Back")->fontSize(20)->fontWeight(6)->color('#059669'),
                Text::make("Returning from iteration {$i}")->fontSize(14)->color('#6B7280'),
            )->fill()->center()->safeArea();

            $tree = $element->toArray($this->nativeCallbacks);
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
                    Pressable::make(Text::make('Back')->fontSize(13)->fontWeight(5)->color('#94A3B8'))->bg('#334155')->borderRadius(8)->padding(8, 14)->onPress('skipScenario'),
                    Spacer::make()->flexGrow(1),
                    Text::make("{$this->interactionCount}/" . self::TOGGLE_TREE_ITERATIONS)->fontSize(12)->fontWeight(6)->color('#38BDF8'),
                )->fillWidth()->gap(8),
                Text::make('TOGGLE TREE')->fontSize(12)->fontWeight(7)->color('#38BDF8'),
                Spacer::make()->height(8),
                Row::make(
                    Text::make('Show 200-node subtree')->fontSize(14)->color('#CBD5E1'),
                    Spacer::make()->flexGrow(1),
                    Pressable::make(
                        Text::make($this->toggleState ? 'ON' : 'OFF')
                            ->fontSize(13)->fontWeight(6)->color($this->toggleState ? '#10B981' : '#64748B')
                    )->bg($this->toggleState ? '#064E3B' : '#1E293B')->borderRadius(16)->padding(8, 16)->onPress('onToggle'),
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

        while ($this->nativeRunning && $this->interactionCount < self::TOGGLE_TREE_ITERATIONS) {
            $this->nativeCallbacks = new CallbackRegistry;
            $tree = $this->render()->toArray($this->nativeCallbacks);
            nativephp_element_publish($tree);

            usleep(20_000);

            $cbId = $this->nativeCallbacks->lookup('onToggle');
            if ($cbId === null) break;

            // Alternate toggle state each iteration
            $nextValue = !$this->toggleState;
            $event = $this->simulateToggle($cbId, $nextValue);
            if ($event === null) continue;
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->nativeRunning = false;
                break;
            }
            $this->dispatch($event);
        }

        $exportResult = nativephp_call('Perf.Export', '{}');
        $this->results['toggle_tree'] = json_decode($exportResult, true)['data'] ?? '{}';
        nativephp_call('Perf.Disable', '{}');
    }

    // ── PHP Render Benchmark ──────────────────────────

    protected function publishProgressScreen(string $label, string $detail): void
    {
        $this->phase = 'running';
        $this->nativeCallbacks = new CallbackRegistry;
        $tree = Column::make(
            Text::make('RUNNING')->fontSize(13)->fontWeight(7)->color('#38BDF8'),
            Spacer::make()->height(8),
            Text::make($label)->fontSize(24)->fontWeight(7)->color('#F1F5F9'),
            Spacer::make()->height(6),
            Text::make($detail)->fontSize(15)->color('#94A3B8'),
        )->fill()->center()->safeArea()->bg('#0F172A')->toArray($this->nativeCallbacks);
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
            // Surface the skip in results so the user sees something
            // rather than the scenario silently producing no card.
            $this->results['stream_render_skipped'] = [
                'reason' => 'PHP extension is missing `nphp_frame_begin` — streaming-mode functions need a rebuild of the PHP runtime.',
            ];

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
        $leafTypes = ['text', 'pressable', 'text', 'divider', 'spacer'];
        $type = $leafTypes[$seed % count($leafTypes)];
        $colors = ['#1F2937', '#DC2626', '#059669', '#2563EB', '#7C3AED', '#D97706'];

        $props = match ($type) {
            'text' => ['text' => "Item #{$seed}", 'font_size' => 14, 'color' => $colors[$seed % count($colors)]],
            'pressable', 'divider', 'spacer' => [],
        };

        $layout = match ($type) {
            'divider' => ['width' => 'fill'],
            'spacer' => ['height' => 8.0],
            'pressable' => ['padding' => [10.0, 14.0, 10.0, 14.0]],
            default => null,
        };

        $style = match ($type) {
            'pressable' => ['bg_color' => $colors[($seed + 1) % count($colors)], 'border_radius' => 8.0],
            default => null,
        };

        if ($type === 'pressable') {
            // Pressable with a text child
            nphp_node_open('pressable', $layout, $style, 0, 0);
            nphp_node_leaf('text', null, null, ['text' => "Btn #{$seed}", 'font_size' => 13, 'color' => '#E2E8F0'], 0, 0);
            nphp_node_close();
        } else {
            nphp_node_leaf($type, $layout, $style, ! empty($props) ? $props : null, 0, 0);
        }
    }

    protected function benchmarkSize(int $targetNodes): array
    {
        $timings = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $this->nativeCallbacks = new CallbackRegistry;

            $t0 = microtime(true);
            $element = $this->generateTree($targetNodes, $i);
            $t1 = microtime(true);

            $tree = $element->toArray($this->nativeCallbacks);
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
        $leafTypes = ['text', 'pressable', 'row', 'divider', 'spacer'];
        $type = $leafTypes[$seed % count($leafTypes)];
        $colors = ['#1F2937', '#DC2626', '#059669', '#2563EB', '#7C3AED', '#D97706'];
        $bullets = ['●', '★', '◆', '▶', '■', '▲'];

        return match ($type) {
            'text' => Text::make("Item #{$seed}")
                ->fontSize(14)
                ->color($colors[$seed % count($colors)]),
            'pressable' => Pressable::make(
                Text::make("Btn #{$seed}")->fontSize(13)->fontWeight(5)->color('#E2E8F0')
            )->bg($colors[($seed + 1) % count($colors)])->borderRadius(8)->padding(10, 14),
            'row' => $this->makeListItem("Headline #{$seed}", "Supporting text for item {$seed}", $bullets[$seed % count($bullets)]),
            'divider' => Divider::make()->fillWidth(),
            'spacer' => Spacer::make()->height(8),
        };
    }

    // ── Primitive Helpers ────────────────────────────

    protected function makeButton(string $label): Pressable
    {
        return Pressable::make(
            Text::make($label)->fontSize(14)->fontWeight(6)->color('#E2E8F0')
        )->fillWidth()->bg('#1E293B')->borderRadius(10)->padding(14)->center();
    }

    /**
     * Append the per-scenario "what this measures / what the numbers
     * mean / how it compares to RN / Flutter / Native" panel to a card.
     *
     * Pulls from `SCENARIO_INFO` so per-test docs live with the test
     * definition, not scattered through the renderers. Designed so a
     * scenario can be added by editing one constant and one render
     * site picks it up.
     */
    protected function appendScenarioInfo(Element $parent, string $key): void
    {
        $info = self::SCENARIO_INFO[$key] ?? null;
        if (! $info) {
            return;
        }

        $parent->addChild(Spacer::make()->height(12));
        $parent->addChild(
            Text::make('ABOUT THIS TEST')->fontSize(12)->fontWeight(7)->color('#64748B')
        );
        if (isset($info['measures'])) {
            $parent->addChild(
                Text::make($info['measures'])->fontSize(13)->color('#CBD5E1')
            );
        }
        if (isset($info['methodology'])) {
            $parent->addChild(Spacer::make()->height(4));
            $parent->addChild(
                Text::make('Methodology')->fontSize(11)->fontWeight(7)->color('#475569')
            );
            $parent->addChild(
                Text::make($info['methodology'])->fontSize(12)->color('#94A3B8')
            );
        }

        if (!empty($info['metric_meaning'])) {
            $parent->addChild(Spacer::make()->height(8));
            $parent->addChild(
                Text::make('Reading the numbers')->fontSize(11)->fontWeight(7)->color('#475569')
            );
            foreach ($info['metric_meaning'] as $metric => $meaning) {
                $parent->addChild(
                    Row::make(
                        Text::make($metric)->fontSize(12)->fontWeight(6)->color('#38BDF8')->width(140),
                        Text::make($meaning)->fontSize(12)->color('#94A3B8')->flexGrow(1),
                    )->fillWidth()->gap(8)
                );
            }
        }

        if (!empty($info['reference'])) {
            $parent->addChild(Spacer::make()->height(8));
            $parent->addChild(
                Text::make('Compared to other frameworks')->fontSize(11)->fontWeight(7)->color('#475569')
            );
            foreach ($info['reference'] as $row) {
                $framework = (string) ($row['framework'] ?? '');
                $note = (string) ($row['note'] ?? '');
                $parent->addChild(
                    Row::make(
                        Text::make($framework)->fontSize(12)->fontWeight(6)->color('#CBD5E1')->width(180),
                        Text::make($note)->fontSize(12)->color('#94A3B8')->flexGrow(1),
                    )->fillWidth()->gap(8)
                );
            }
        }
    }

    /**
     * Two-line scenario menu entry: bold label + one-line description.
     * Replaces the prior bare-button rendering so users can see what
     * each test actually measures before tapping it.
     */
    protected function makeScenarioCard(string $key, string $label, string $description): Pressable
    {
        $inner = Column::make()->fillWidth()->gap(4);
        $inner->addChild(
            Text::make($label)->fontSize(14)->fontWeight(6)->color('#E2E8F0')
        );
        if ($description !== '') {
            $inner->addChild(
                Text::make($description)->fontSize(12)->color('#94A3B8')
            );
        }

        return Pressable::make($inner)
            ->fillWidth()
            ->bg('#1E293B')
            ->borderRadius(10)
            ->padding(14)
            ->onPress("startScenario('{$key}')");
    }

    protected function makeListItem(string $headline, string $supporting = '', string $bullet = '•'): Row
    {
        $row = Row::make()->fillWidth()->gap(12)->padding(14, 16);

        $row->addChild(Text::make($bullet)->fontSize(16)->color('#64748B'));

        $textCol = Column::make()->flexGrow(1)->gap(2);
        $textCol->addChild(Text::make($headline)->fontSize(15)->fontWeight(5)->color('#E2E8F0'));
        if ($supporting !== '') {
            $textCol->addChild(Text::make($supporting)->fontSize(13)->color('#94A3B8'));
        }
        $row->addChild($textCol);

        return $row;
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

        // Each result card injects the per-scenario "what this measures
        // / what the numbers mean / how it compares" panel before being
        // attached, so every test on this screen comes with its own
        // documentation.

        // Interactive scenario results (counter_tap, large_tree_tap, text_input, toggle_tree)
        foreach (['counter_tap', 'large_tree_tap', 'text_input', 'toggle_tree'] as $key) {
            $data = $this->results[$key] ?? null;
            if ($data) {
                $card = $this->renderInteractionCard(self::SCENARIOS[$key] ?? $key, $data);
                $this->appendScenarioInfo($card, $key);
                $content->addChild($card);
            }
        }

        // List scroll FPS result
        $listScroll = $this->results['list_scroll'] ?? null;
        if ($listScroll) {
            $card = $this->renderFrameCard(self::SCENARIOS['list_scroll'], $listScroll);
            $this->appendScenarioInfo($card, 'list_scroll');
            $content->addChild($card);
        }

        // JSON 10k parse result
        $jsonParse = $this->results['json_parse'] ?? null;
        if ($jsonParse) {
            $card = $this->renderJsonParseCard($jsonParse);
            $this->appendScenarioInfo($card, 'json_parse');
            $content->addChild($card);
        }

        // Large list 10k FPS result
        $largeListFps = $this->results['large_list_fps'] ?? null;
        if ($largeListFps) {
            $card = $this->renderLargeListFpsCard($largeListFps);
            $this->appendScenarioInfo($card, 'large_list_fps');
            $content->addChild($card);
        }

        // Rapid-fire result
        $rapidFire = $this->results['rapid_fire'] ?? null;
        if ($rapidFire) {
            $card = $this->renderRapidFireCard($rapidFire);
            $this->appendScenarioInfo($card, 'rapid_fire');
            $content->addChild($card);
        }

        // Navigation / Tree Replace result
        $nav = $this->results['navigation'] ?? null;
        if ($nav) {
            $card = $this->renderNavigationCard($nav);
            $this->appendScenarioInfo($card, 'navigation');
            $content->addChild($card);
        }

        // PHP-side render benchmark cards (tree builder mode) — collect
        // first so we can render the section header even when only the
        // streaming variant produced data, and vice versa.
        $renderEntries = [];
        $streamEntries = [];
        foreach ($this->results as $key => $stats) {
            if (str_starts_with($key, 'render_') && is_array($stats) && isset($stats['avg_total'])) {
                $renderEntries[(int) str_replace('render_', '', $key)] = $stats;
            } elseif (str_starts_with($key, 'stream_') && $key !== 'stream_render_skipped' && is_array($stats) && isset($stats['avg_total'])) {
                $streamEntries[(int) str_replace('stream_', '', $key)] = $stats;
            }
        }
        ksort($renderEntries);
        ksort($streamEntries);

        // Tree-builder section
        if (! empty($renderEntries)) {
            $content->addChild(Spacer::make()->height(4));
            $content->addChild(
                Text::make('PHP RENDER (TREE BUILDER)')->fontSize(13)->fontWeight(7)->color('#38BDF8')
            );
            $sectionInfoEmitted = false;
            foreach ($renderEntries as $nodeCount => $stats) {
                $card = $this->renderPhpBenchCard("{$nodeCount} nodes", $stats);
                // Emit the info panel ONCE under the first card so the
                // section explainer isn't repeated 4× for [10/50/100/500].
                if (! $sectionInfoEmitted) {
                    $this->appendScenarioInfo($card, 'render');
                    $sectionInfoEmitted = true;
                }
                $content->addChild($card);
            }
        }

        // Streaming section — three branches: real results, skipped
        // (extension missing), or genuinely absent (not run at all).
        $streamSkipped = $this->results['stream_render_skipped'] ?? null;
        $streamRanAtAll = ! empty($streamEntries) || $streamSkipped !== null;

        if ($streamRanAtAll) {
            $content->addChild(Spacer::make()->height(4));
            $headerColor = $streamSkipped ? '#F59E0B' : '#10B981';
            $content->addChild(
                Text::make('PHP RENDER (STREAMING)')->fontSize(13)->fontWeight(7)->color($headerColor)
            );

            if ($streamSkipped !== null) {
                $reason = (string) ($streamSkipped['reason'] ?? 'Skipped.');
                $skipCard = Column::make()->fillWidth()->bg('#1E293B')->borderRadius(12)->padding(20)->gap(6);
                $skipCard->addChild(
                    Row::make(
                        Text::make('Skipped')->fontSize(18)->fontWeight(7)->color('#F1F5F9'),
                        Spacer::make()->flexGrow(1),
                        Text::make('NOT AVAILABLE')->fontSize(11)->fontWeight(7)->color('#F59E0B'),
                    )->fillWidth()
                );
                $skipCard->addChild(Text::make($reason)->fontSize(13)->color('#94A3B8'));
                $this->appendScenarioInfo($skipCard, 'stream_render');
                $content->addChild($skipCard);
            } else {
                $sectionInfoEmitted = false;
                foreach ($streamEntries as $nodeCount => $stats) {
                    // Pair with the tree-builder result at the same node
                    // count so the "% faster" comparison is shown inline.
                    $legacyStats = $renderEntries[$nodeCount] ?? null;
                    $card = $this->renderStreamBenchCard("{$nodeCount} nodes", $stats, $legacyStats);
                    if (! $sectionInfoEmitted) {
                        $this->appendScenarioInfo($card, 'stream_render');
                        $sectionInfoEmitted = true;
                    }
                    $content->addChild($card);
                }
            }
        }

        $content->addChild(Spacer::make()->height(8));
        $content->addChild(
            $this->makeButton('Back to Menu')->onPress('backToMenu')
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

    /**
     * Leave the suite entirely. The component owns its own dark UI and
     * `safeArea()` so the StackLayout's auto-chevron is hidden — give
     * the user an explicit way out via this handler.
     */
    public function backToLauncher(): void
    {
        $this->back();
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
        $rawFrames = $data['raw_frames'] ?? null;

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
                    $this->statChip('p50', (float) ($frames['p50'] ?? 0), '#38BDF8'),
                    $this->statChip('p95', (float) ($frames['p95'] ?? 0), '#F59E0B'),
                    $this->statChip('p99', (float) ($frames['p99'] ?? 0), '#EF4444'),
                )->fillWidth()->gap(8)
            );

            // Dropped % / jank % / CoV — derived from raw_frames when
            // available, otherwise from the summary jank counts.
            $extended = $this->computeExtendedFrameMetrics($frames, $rawFrames);
            if ($extended !== null) {
                $cardContent->addChild(Spacer::make()->height(4));
                $cardContent->addChild(
                    Row::make(
                        $this->statChip('drop%', $extended['dropped_pct'], $extended['dropped_pct'] > 5 ? '#EF4444' : '#10B981'),
                        $this->statChip('jank%', $extended['jank_pct'], $extended['jank_pct'] > 3 ? '#F59E0B' : '#10B981'),
                        $this->statChip('CoV', $extended['cov'], '#94A3B8'),
                    )->fillWidth()->gap(8)
                );
            }

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

            // Inline comparison against RN / Flutter / Native — same
            // metrics methodology as SynergyBoat 2025-2026.
            $this->appendReferenceRows($cardContent, $data, $frames);
        } else {
            $cardContent->addChild(
                Text::make('No frame data captured')->fontSize(14)->color('#64748B')
            );
        }

        return $cardContent;
    }

    /**
     * Derive p50/p95-adjacent metrics that aren't always in the summary
     * block. Pulls from raw frame times when available, otherwise falls
     * back to summary counts (and CoV stays null without raw data).
     */
    protected function computeExtendedFrameMetrics(array $frames, ?array $rawFrames): ?array
    {
        $count = (int) ($frames['count'] ?? 0);
        if ($count === 0) {
            return null;
        }

        $jankCount = (int) ($frames['jank_count'] ?? 0);
        $jankPct = ($jankCount / $count) * 100;

        // Dropped frames = those that missed a 60Hz budget (16.67ms).
        // For 120Hz devices the platform-side fps stat is more telling,
        // so we'd ideally use 8.33ms — but the cross-platform metric
        // everyone publishes is the 60Hz-equivalent dropped rate.
        $droppedCount = 0;
        $cov = 0.0;

        if (is_array($rawFrames) && !empty($rawFrames)) {
            $totals = array_map(fn($f) => (float) ($f['total_ms'] ?? 0), $rawFrames);
            foreach ($totals as $ms) {
                if ($ms > 16.67) $droppedCount++;
            }
            $mean = array_sum($totals) / count($totals);
            if ($mean > 0) {
                $variance = array_sum(array_map(fn($x) => ($x - $mean) ** 2, $totals)) / count($totals);
                $cov = sqrt($variance) / $mean;
            }
        } else {
            // Approximate: use jank count as a proxy for drops.
            $droppedCount = $jankCount;
        }

        return [
            'dropped_pct' => round(($droppedCount / $count) * 100, 1),
            'jank_pct' => round($jankPct, 1),
            'cov' => round($cov, 2),
        ];
    }

    /**
     * Append a "vs reference" panel comparing our measured numbers
     * against published RN / Flutter / Native iOS / Android values
     * from the SynergyBoat 2025-2026 benchmark series.
     */
    protected function appendReferenceRows(Element $parent, array $data, array $frames): void
    {
        $framework = $data['framework'] ?? '';
        $platform = stripos($framework, 'iOS') !== false ? 'ios' : 'android';
        $refs = BenchmarkReferenceData::listScrollFor($platform);

        $parent->addChild(Spacer::make()->height(8));
        $parent->addChild(
            Text::make('VS REFERENCE (' . strtoupper($platform) . ', PUBLISHED)')->fontSize(12)->fontWeight(7)->color('#64748B')
        );

        // "Ours" row first for visual comparison.
        $oursAvg = (float) ($frames['average'] ?? 0);
        $oursP95 = (float) ($frames['p95'] ?? 0);
        $oursFps = (float) ($frames['fps'] ?? 0);
        $parent->addChild($this->referenceRow('NativePHP (ours)', $oursAvg, $oursP95, $oursFps, '#38BDF8'));

        foreach ($refs as $name => $ref) {
            $label = match ($name) {
                'native_swift'  => 'Native (Swift)',
                'native_kotlin' => 'Native (Kotlin)',
                'flutter'       => 'Flutter',
                'react_native'  => 'React Native',
                default         => $name,
            };
            $parent->addChild($this->referenceRow(
                $label,
                (float) $ref['avg_ms'],
                (float) $ref['p95_ms'],
                (float) $ref['fps'],
                '#475569'
            ));
        }
    }

    /** One row of the reference comparison table. */
    protected function referenceRow(string $label, float $avg, float $p95, float $fps, string $accent): Element
    {
        return Row::make(
            Text::make($label)->fontSize(13)->color($accent)->flexGrow(1),
            Text::make(number_format($avg, 1) . 'ms')->fontSize(12)->color('#94A3B8'),
            Spacer::make()->width(8),
            Text::make('p95 ' . number_format($p95, 1))->fontSize(12)->color('#94A3B8'),
            Spacer::make()->width(8),
            Text::make(number_format($fps, 0) . 'fps')->fontSize(12)->color('#94A3B8'),
        )->fillWidth()->gap(0);
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
