<?php

namespace Native\Mobile\Edge\Recording;

use Native\Mobile\Edge\Web\WebRenderer;

/**
 * POC time-travel viewer: server-renders every recorded tree frame to
 * HTML via the same WebRenderer the live web target uses, then a small
 * scrubber page steps through them with events marked on the timeline.
 */
class ReplayViewer
{
    public static function index()
    {
        $rows = '';

        foreach (TreeRecorder::recordings() as $r) {
            $when = date('M j, H:i:s', $r['mtime']);
            $kb = round($r['bytes'] / 1024, 1);
            $rows .= "<a href=\"".\Native\Mobile\Edge\Web\EdgeEndpoint::replayPath()."/{$r['name']}\" class=\"block px-5 py-4 rounded-xl bg-neutral-900 hover:bg-neutral-800 border border-neutral-800\">
                <div class=\"font-semibold\">{$r['name']}</div>
                <div class=\"text-sm text-neutral-400\">{$when} · {$r['frames']} frames · {$kb} KB</div>
            </a>";
        }

        if ($rows === '') {
            $rows = '<p class="text-neutral-400">No recordings yet. Browse the app with EDGE_RECORD=true, then come back.</p>';
        }

        return response(static::shell('Recordings', "<div class=\"max-w-xl mx-auto p-8 flex flex-col gap-3\">
            <h1 class=\"text-2xl font-bold mb-2\">EDGE session recordings</h1>{$rows}</div>"));
    }

    public static function show(string $name)
    {
        $frames = TreeRecorder::load($name);

        // Pre-render every tree frame to HTML; carry events/nav through as
        // timeline entries. Times become offsets from the first frame.
        $t0 = $frames[0]['t'] ?? 0;
        $timeline = [];

        // Device recordings interleave keyframes with REUSE deltas (the
        // wire protocol's subtree memoization). Resolve deltas against an
        // id → node index accumulated from prior frames, exactly like the
        // native readers splice their previousTree.
        $reuseIndex = [];

        foreach ($frames as $frame) {
            $entry = [
                'kind' => $frame['kind'],
                'at' => round(($frame['t'] ?? $t0) - $t0, 3),
            ];

            switch ($frame['kind']) {
                case 'tree':
                    $entry['screen'] = $frame['screen'] ?? '';
                    $resolved = static::resolveReuse($frame['payload'] ?? [], $reuseIndex);
                    $entry['html'] = WebRenderer::render(static::webifySrcs($resolved));
                    break;
                case 'event':
                    $entry['label'] = $frame['method'] ?? ('#'.($frame['payload']['callback_id'] ?? '?'));
                    break;
                case 'nav':
                    $entry['label'] = 'navigate → '.($frame['payload']['redirect'] ?? 'back');
                    break;
                case 'meta':
                    $entry['label'] = ($frame['app'] ?? 'app').' · '.($frame['platform'] ?? '');
                    $entry['screen'] = $frame['screen'] ?? '';
                    break;
            }

            $timeline[] = $entry;
        }

        $json = json_encode($timeline, JSON_HEX_TAG | JSON_HEX_AMP);
        $nameEsc = htmlspecialchars($name, ENT_QUOTES);
        $replayIndex = \Native\Mobile\Edge\Web\EdgeEndpoint::replayPath();

        $body = <<<HTML
<div class="h-screen flex flex-col">
  <header class="flex items-center gap-4 px-5 py-3 border-b border-neutral-800 shrink-0">
    <a href="{$replayIndex}" class="text-neutral-400 hover:text-white">&larr;</a>
    <h1 class="font-bold">Replay: {$nameEsc}</h1>
    <span id="status" class="text-sm text-neutral-400"></span>
    <span class="flex-1"></span>
    <button id="play" class="px-4 py-1.5 rounded-full bg-white text-black text-sm font-semibold">Play</button>
  </header>

  <div class="flex-1 min-h-0 flex">
    <div class="flex-1 overflow-auto bg-neutral-950 flex justify-center p-6">
      <div id="stage" class="bg-white dark:bg-black w-full max-w-5xl rounded-xl overflow-auto shadow-2xl" style="height: fit-content; min-height: 80%"></div>
    </div>
    <aside class="w-72 shrink-0 border-l border-neutral-800 overflow-y-auto p-3" id="eventlist"></aside>
  </div>

  <footer class="px-5 py-4 border-t border-neutral-800 shrink-0 flex items-center gap-4">
    <input id="scrub" type="range" min="0" value="0" class="flex-1 accent-white">
    <span id="clock" class="text-sm text-neutral-400 w-24 text-right font-mono"></span>
  </footer>
</div>

<script type="application/json" id="timeline">{$json}</script>
<script>
(() => {
  const T = JSON.parse(document.getElementById('timeline').textContent);
  const trees = T.map((f, i) => ({...f, i})).filter(f => f.kind === 'tree');
  const stage = document.getElementById('stage');
  const scrub = document.getElementById('scrub');
  const clock = document.getElementById('clock');
  const status = document.getElementById('status');
  const list = document.getElementById('eventlist');

  scrub.max = trees.length - 1;

  // Sidebar: full timeline with events interleaved
  T.forEach((f, i) => {
    const el = document.createElement('div');
    el.className = 'px-3 py-2 rounded-lg text-sm mb-1 cursor-pointer ' + (
      f.kind === 'event' ? 'bg-amber-900/40 text-amber-200' :
      f.kind === 'nav'   ? 'bg-blue-900/40 text-blue-200' :
      f.kind === 'meta'  ? 'bg-neutral-800 text-neutral-300' :
                           'bg-neutral-900 text-neutral-400');
    el.textContent = f.kind === 'tree'
      ? `frame · \${f.screen} · \${f.at.toFixed(2)}s`
      : `\${f.kind}: \${f.label} · \${f.at.toFixed(2)}s`;
    el.onclick = () => {
      // jump to the nearest tree frame at-or-after this entry
      const idx = trees.findIndex(t => t.i >= i);
      go(idx === -1 ? trees.length - 1 : idx);
    };
    list.appendChild(el);
  });

  function go(n) {
    n = Math.max(0, Math.min(trees.length - 1, n));
    scrub.value = n;
    const f = trees[n];
    stage.innerHTML = f.html;
    clock.textContent = f.at.toFixed(2) + 's';
    status.textContent = `frame \${n + 1}/\${trees.length} — \${f.screen}`;
    [...list.children].forEach((el, i) => {
      el.classList.toggle('ring-1', T[i].kind === 'tree' && trees[n].i === i);
      el.classList.toggle('ring-white', T[i].kind === 'tree' && trees[n].i === i);
    });
  }

  scrub.addEventListener('input', () => go(+scrub.value));

  // Play at real recorded pacing
  let timer = null;
  document.getElementById('play').addEventListener('click', (e) => {
    if (timer) { clearTimeout(timer); timer = null; e.target.textContent = 'Play'; return; }
    e.target.textContent = 'Pause';
    let n = +scrub.value >= trees.length - 1 ? 0 : +scrub.value;
    const step = () => {
      go(n);
      if (n >= trees.length - 1) { timer = null; e.target.textContent = 'Play'; return; }
      const dt = Math.min(3, Math.max(0.15, trees[n + 1].at - trees[n].at));
      n++;
      timer = setTimeout(step, dt * 1000);
    };
    step();
  });

  go(0);
})();
</script>
HTML;

        return response(static::shell("Replay {$nameEsc}", $body));
    }

    /**
     * Splice REUSE markers (flags=1) with the previously-resolved node of
     * the same id, and index every fully-emitted node for future frames.
     */
    protected static function resolveReuse(array $node, array &$index): array
    {
        if (($node['flags'] ?? 0) === 1) {
            return $index[$node['id'] ?? -1] ?? [];
        }

        if (! empty($node['children'])) {
            $node['children'] = array_values(array_filter(array_map(
                fn ($child) => static::resolveReuse($child, $index),
                $node['children'],
            )));
        }

        if (isset($node['id'])) {
            $index[$node['id']] = $node;
        }

        return $node;
    }

    /**
     * Device recordings reference bundled assets by on-device file path
     * (`file:///…/public/img/x.png`). Map anything under the app's public
     * dir back to its web URL so the browser can load it.
     */
    protected static function webifySrcs(array $node): array
    {
        $src = $node['props']['src'] ?? null;

        if (is_string($src) && str_starts_with($src, 'file://') && ($pos = strpos($src, '/public/')) !== false) {
            $node['props']['src'] = url(substr($src, $pos + strlen('/public/')));
        }

        if (! empty($node['children'])) {
            $node['children'] = array_map([static::class, 'webifySrcs'], $node['children']);
        }

        return $node;
    }

    protected static function shell(string $title, string $body): string
    {
        $titleEsc = htmlspecialchars($title, ENT_QUOTES);
        [$light, $dark] = \Native\Mobile\Edge\Web\WebTheme::palette();
        $vars = '';
        foreach ($light as $token => $hex) {
            $vars .= "  --color-theme-{$token}: {$hex};\n";
        }
        $darkVars = '';
        foreach ($dark as $token => $hex) {
            $darkVars .= "    --color-theme-{$token}: {$hex};\n";
        }

        return <<<HTML
<!doctype html>
<html lang="en" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleEsc}</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<style type="text/tailwindcss">
@theme {
{$vars}}
@media (prefers-color-scheme: dark) {
  :root {
{$darkVars}  }
}
</style>
<style>
.material-symbols-outlined { font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal; line-height: 1; display: inline-block; -webkit-font-smoothing: antialiased; user-select: none; }
.edge-stack > * { grid-area: 1 / 1; }
</style>
</head>
<body class="bg-neutral-950 text-white antialiased">
{$body}
</body>
</html>
HTML;
    }
}
