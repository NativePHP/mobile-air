<?php

use Native\Mobile\Support\Watch\WatchConsole;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Strip SGR colour codes but keep the cursor/erase sequences — those are what
 * has to be right for the sticky footer to stay in one place.
 */
function layout(BufferedOutput $output): string
{
    return preg_replace('/\e\[\d*(?:;\d+)*m/', '', $output->fetch());
}

function console(BufferedOutput $output, bool $decorated = true): WatchConsole
{
    $output->setDecorated($decorated);

    $console = new WatchConsole($output, interactive: false);
    $console->keys(['l' => 'change screen', 'q' => 'quit']);
    $console->status([[null, 'android'], ['screen', '/']]);

    return $console;
}

it('draws the status, activity and legend as a four line footer', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

    console($output)->start();

    $rendered = layout($output);

    expect($rendered)->toContain('android')
        ->toContain('screen /')
        ->toContain('L change screen')
        ->toContain('Q quit');

    // Hidden cursor, four freshly-cleared lines, no cursor movement on the
    // very first draw (there is nothing above to back up over).
    expect($rendered)->toStartWith("\e[?25l")
        ->and(substr_count($rendered, "\e[2K"))->toBe(4)
        ->and($rendered)->not->toContain("\e[4A");
});

it('redraws the footer in place rather than scrolling it', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $console = console($output);
    $console->start();
    $output->fetch();

    $console->activity('synced routes/web.php · 1 change', 'green');

    // Back up over exactly the four lines drawn last time, then rewrite them.
    expect(layout($output))
        ->toStartWith("\e[4A")
        ->toContain('synced routes/web.php · 1 change');
});

it('keeps notes in the scrollback above the footer', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $console = console($output);
    $console->start();
    $output->fetch();

    $console->note('adb push failed');

    // Erase the footer, write the note, redraw the footer underneath it.
    expect(layout($output))->toStartWith("\e[4A\e[0J".'adb push failed');
});

it('truncates footer lines so they cannot wrap and desync the redraw', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $console = console($output);
    $console->start();
    $output->fetch();

    $console->activity(str_repeat('deeply/nested/', 40).'file.blade.php');

    $width = (new Terminal)->getWidth();

    foreach (explode("\n", trim(layout($output))) as $line) {
        expect(mb_strlen(str_replace(["\e[4A", "\e[2K"], '', $line)))
            ->toBeLessThan($width);
    }
});

it('escapes activity text instead of parsing it as markup', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $console = console($output);
    $console->start();
    $output->fetch();

    $console->activity('resources/views/<x-thing>.blade.php');

    expect(layout($output))->toContain('resources/views/<x-thing>.blade.php');
});

it('leaves no footer behind when it hands the terminal back', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $console = console($output);
    $console->start();
    $output->fetch();

    $console->stop();

    expect(layout($output))->toBe("\e[4A\e[0J\e[?25h");
});

it('can be stopped more than once', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $console = console($output);
    $console->start();
    $console->stop();
    $output->fetch();

    $console->stop();

    expect(layout($output))->toBe('');
});

it('reports no keypresses when the terminal cannot be put into raw mode', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $console = console($output);
    $console->start();

    expect($console->isInteractive())->toBeFalse();
    expect($console->readKey())->toBeNull();
});

it('falls back to plain lines when the output is not decorated', function () {
    $output = new BufferedOutput;
    $console = console($output, decorated: false);
    $console->start();
    $output->fetch();

    $console->activity('synced routes/web.php');
    $console->note('adb push failed');

    // No footer, no cursor games — just readable log lines.
    expect($output->fetch())->toBe("synced routes/web.php\nadb push failed\n");
});
