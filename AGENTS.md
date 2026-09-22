# NativePHP v3: core, mobile and desktop

This file is shared. The same copy lives in three places:

- NativePHP/mobile-air, on the `refactor/extract-edge-to-core` branch
- NativePHP/desktop-air, on `main`
- NativePHP/core, on `main`

If you change it, change all three in the same sitting and keep them identical.

## The goal

We are moving the platform-neutral half of `nativephp/mobile` into NativePHP/core
(package `supanative/core`, namespace `SupaNative\Core\`). Desktop (`supanative/desktop`)
is built on top of core. It runs on macOS today, and Windows and Linux come later.

Mobile has to keep working the whole way through. A mobile app, `nativephp/mobile-ui` and
third-party plugins must not need a single edit because something moved. If an extraction
only works after changing one of them, the extraction is wrong.

## The repos

These repos expect to sit side by side in one parent folder (usually `~/Projects`),
because several of them find each other through Composer path repositories like
`../supanative-core`.

| Repo | Usual local folder | Branch that matters | Package | What it is |
|---|---|---|---|---|
| NativePHP/core | `supanative-core` | `main` | `supanative/core` | The shared layer. |
| NativePHP/mobile-air | `nativephp-mobile-air` | `refactor/extract-edge-to-core` | `nativephp/mobile` | Mobile, rebuilt on core. `main` is the released mobile package and keeps moving. |
| NativePHP/desktop-air | `nativephp-desktop-shell` | `main` | `supanative/desktop` | The desktop package, with the macOS shell in `resources/mac`. |
| super-native | `super-native` | `desktop` | (an app) | The testbed app. Requires all of the above so one app runs on iOS, Android and macOS. |
| NativePHP/php-bin-mobile | | `feat/surfaces-phase1` | | The embedded PHP libraries. Multi-window support is on this branch until it merges into `main`. |
| nativephp/mobile-ui | | `feat/macos-renderers` | `nativephp/mobile-ui` | The UI components. This branch adds the macOS renderers. |

How they are wired today:

- mobile-air's refactor branch requires `supanative/core` from the path `../supanative-core`,
  symlinked. Whatever branch is checked out in that folder is the core mobile runs against.
- desktop-air requires `supanative/core` from GitHub. To test it against a local core, run
  `composer config repositories.core-local path ../supanative-core` and
  `composer update supanative/core`. Don't commit that change to `composer.json`.
- super-native takes core and desktop as path repos, mobile as
  `dev-refactor/extract-edge-to-core` from GitHub, and mobile-ui from `packages/`.
- The published PHP binaries don't support multiple windows yet. Until they do, install the
  macOS shell with `NATIVEPHP_BIN_BRANCH=feat/surfaces-phase1 php artisan native:install mac`.

How changes land:

- mobile-air's `refactor/extract-edge-to-core`: commit and push straight to the branch. Don't
  open PRs into it, and don't make side branches for it. Merge, never rebase, and never
  force-push. super-native builds from this branch.
- desktop-air and core: a branch and a PR against `main`.

## What has moved to core so far

This changes often. Check `src/` in core, and
`git diff --stat $(git merge-base origin/main HEAD)` on the mobile refactor branch, for
the real picture. Roughly:

- The Edge element layer: `Element`, the elements in `Edge/Elements`, the `<native:*>`
  Blade layer (`NativeTagPrecompiler`, `NativeElementCollector`, `Edge/Components/Native`),
  `TailwindParser`, `CallbackRegistry`, `Transition` and the enums.
- The attributes (`Computed`, `Lazy`, `Locked`, `On`, `Poll`) and `Support\NativeCallbacks`.
- The neutral half of `NativeComponent` and `NavigationIntent`. Mobile keeps real subclasses
  of both that add the mobile traits and constants.
- The registry behind `Route::native()` (`ScreenRegistry`, `ScreenRoutes`). Mobile keeps the
  HTTP half of `Route::native()` and `NativeRouter`.
- The `native:run`, `native:install`, `native:build`, `native:debug` and `native:watch`
  command names. Core owns them and hands each run to the platform that claims it. Mobile
  hasn't implemented core's platform contracts yet, so core falls back to mobile's own
  commands for iOS and Android.
- `Platform`, which reads the platform from `NATIVEPHP_PLATFORM` instead of asking the bridge.
- The platform-neutral half of `native:watch` (Watchman, the polling watcher, the watch terminal).

Mobile keeps its chrome elements (top bar, bottom nav, sheets and so on), layouts,
`NativeRouter`, Jump, the icon resolver and the Xcode and Android Studio projects.

## Old names keep working

Code everywhere still says `Native\Mobile\Edge\Element` and friends. `src/edge_aliases.php`
in mobile makes those names resolve to core's classes. Read its header before touching it.

The part that bites: PHP does not autoload when it checks a parameter or return type. So if
only `Element` were aliased, mobile-ui's `make(View|Element $content)` would throw a
TypeError that looks impossible. The shim therefore aliases the whole moved set the first
time any old Edge name is used. It finds that set by walking core's `src/Edge`, so a class
you move into core's `Edge` is covered without editing the shim. A class moved under a
prefix the shim doesn't list yet needs a new entry.

Mobile's own Edge tests still use the old names on purpose, so every run tests the aliases.

## Keeping the refactor branch in line with mobile main

This is the most important job in these repos. `main` keeps taking fixes and features
after the refactor branch was cut from it. The first cut was `4c93629` (#315, 2026-08-10).
The further the branch falls behind, the harder the merge and the more fixes get lost.

### At the start of every session in any of these repos

```sh
cd ../nativephp-mobile-air   # wherever mobile-air is
git fetch origin
git rev-list --count origin/refactor/extract-edge-to-core..origin/main
```

Tell the user how many commits the refactor branch is behind. Sync at least once a week, or
sooner if a few PRs have landed. A sync is its own piece of work: don't mix it into an
unrelated task, and ask before starting one if that's not what you were asked to do.

### Doing a sync

**1. List what landed since the last sync.**

```sh
R=origin/refactor/extract-edge-to-core
base=$(git merge-base origin/main $R)
git log --no-merges --format='%h %cs %s' $base..origin/main
gh pr list -R NativePHP/mobile-air --state merged --base main --limit 200 \
  --search "merged:>=$(git log -1 --format=%cs $base)"
```

The PR list can include one or two from the day of the last sync that are already in.
Read each PR with `gh pr view`, not just its title, and note which ones add a feature.

**2. Sort the changes against what moved.**

```sh
t=$(mktemp -d)
git diff --name-only $base origin/main | sort > $t/main
git diff --name-only --diff-filter=D $base $R | sort > $t/moved
git diff --name-only --diff-filter=MR $base $R | sort > $t/both
comm -12 $t/main $t/moved    # main changed a file that now lives in core
comm -12 $t/main $t/both     # main changed a file the refactor also changed
git diff --name-only --diff-filter=A $base origin/main   # files main added
```

`git log --no-merges --format='%h %s' $base..origin/main -- <file>` shows which commits
touched a file. These commands use the merge base, so they always mean "since the last
sync", however many syncs there have been.

**3. Deal with each group.**

- **Main changed a file that moved to core.** The merge will drop that change without a
  conflict, because the file is deleted on the refactor branch. Nothing warns you. Port
  the change into core by hand under `SupaNative\Core\`. Main's tests for it usually merge
  cleanly into mobile's `tests/` and run against core through the aliases, so a missed port
  often shows up as a failing test. Not every change comes with a test, so don't rely on it.
- **Main changed a file the refactor also changed.** Check the merged file still has main's
  change, even where git merged it without a conflict.
- **Main added a file in an area that moved** (`src/Edge`, `src/Attributes`, `src/Support`,
  `src/Concerns`, `src/Exceptions`). Decide whether it is platform-neutral. If it is, it
  belongs in core, so desktop gets it too. If you're not sure, leave it in mobile and list it
  in the PR as a candidate for core.
- **Reverts.** Look at the final state, not each commit. Some PRs were reverted and landed
  again later (#333 was reverted and came back as #391).

**4. Merge.**

- In mobile-air, on `refactor/extract-edge-to-core` itself, run `git merge origin/main`. No
  sync branch. Don't push yet.
- Do the core half on its own branch in `../supanative-core`, so mobile runs against it.

**5. Run the tests.**

- mobile-air, with `../supanative-core` on the core branch: `composer test`.
- mobile-air `main` in a separate clone or worktree with its own `composer install`:
  `composer test`. A test that passes on `main` and fails after the merge is a regression
  from the extraction. A test that fails on both is not the sync's to fix; mention it.
- desktop-air against the same core: `composer test:php`.

**6. Land it.**

- Open a PR for the core half against core's `main`, with the test results and counts.
- Once that PR is merged, push the mobile merge straight to `refactor/extract-edge-to-core`.
  No PR. Pushing before core's PR is merged would leave the branch running against a core
  without the ported fixes.
- If nothing needed porting there is no core PR, so push the merge straight away.
- The merge commit message lists the mobile PRs it covers, what was ported to core, the core
  PR it needs, and the test results with counts.

If `~/Projects/ideas` exists, log the sync on the board:
`php ~/Projects/ideas/artisan idea:log nativephp-core "<what was synced>"`.

## Tests

| Repo | Command | Notes |
|---|---|---|
| mobile-air | `composer test` | Pest. `composer analyse` runs PHPStan. |
| core | none yet | Core has no suite of its own. It is tested through mobile's and desktop's suites. |
| desktop-air | `composer test` | `test:php` is Pest. `test:mac` is Swift Testing and needs the PHP binaries installed into `resources/mac` first. See `tests/README.md`. |

Any change to core has to pass mobile's suite and desktop's `test:php`, whichever repo you
were working in when you made it.

## Rules for extracting

- APIs are cross-platform by default. One that only makes sense on one platform (the Dock,
  traffic lights) is listed as such in desktop's `docs/v2-to-v3-api.md`.
- Desktop has to stay close to the v2 `nativephp/laravel` API. Any change from it gets a row
  in desktop's `docs/v2-to-v3-api.md` in the same PR.
- Don't edit `nativephp/mobile-ui` to make an extraction work. Fix it on our side.
- Package names are still `supanative/*`. Whether they become `nativephp/*` is an open
  question, so don't rename anything in passing.
- Keep facts that go stale (open PR numbers, counts) out of this file. Give the command that
  works them out instead.

## Where to read more

- desktop-air `docs/multi-surface.md`: how one PHP runtime drives several windows.
- desktop-air `docs/v2-to-v3-api.md`: every v2 API change, method by method.
- desktop-air `docs/roadmap-notes.md`: decisions, known gaps and the work mobile still owes.
- desktop-air `docs/api/windows-and-routing.md`: `Route::window()` and window ownership.
- mobile-air `src/edge_aliases.php`: the alias shim and why it works the way it does.
- core `README.md`: why core owns the shared `native:*` command names.
