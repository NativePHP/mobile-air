<?php

use Native\Mobile\Support\Path;

it('rewrites backslashes to forward slashes', function () {
    expect(Path::normalize('C:\\code\\project\\app\\Foo.php'))
        ->toBe('C:/code/project/app/Foo.php');
});

it('leaves an already normalized path alone', function () {
    expect(Path::normalize('app/NativeComponents/Foo.php'))
        ->toBe('app/NativeComponents/Foo.php');
});

it('strips the base directory', function () {
    expect(Path::relativeTo('/var/www/app/app/Foo.php', '/var/www/app'))
        ->toBe('app/Foo.php');
});

it('strips a Windows base directory from a Windows path', function () {
    expect(Path::relativeTo('C:\\code\\project\\app\\Foo.php', 'C:\\code\\project'))
        ->toBe('app/Foo.php');
});

it('strips the base when the two sides disagree on separators', function () {
    // The Windows case that started all this: base_path() hands back
    // backslashes while the path being matched uses forward slashes.
    expect(Path::relativeTo('C:/code/project/app/Foo.php', 'C:\\code\\project'))
        ->toBe('app/Foo.php')
        ->and(Path::relativeTo('C:\\code\\project\\app\\Foo.php', 'C:/code/project'))
        ->toBe('app/Foo.php');
});

it('does not care whether the base has a trailing separator', function () {
    expect(Path::relativeTo('/var/www/app/app/Foo.php', '/var/www/app/'))
        ->toBe('app/Foo.php')
        ->and(Path::relativeTo('C:\\code\\project\\app\\Foo.php', 'C:\\code\\project\\'))
        ->toBe('app/Foo.php');
});

it('returns a path outside the base untouched, but normalized', function () {
    expect(Path::relativeTo('C:\\elsewhere\\Foo.php', 'C:\\code\\project'))
        ->toBe('C:/elsewhere/Foo.php');
});

it('only strips the base as a prefix, not everywhere it appears', function () {
    // str_replace() would have mangled the second "project/" here.
    expect(Path::relativeTo('/srv/project/nested/project/Foo.php', '/srv/project'))
        ->toBe('nested/project/Foo.php');
});

it('does not strip a base that only partially matches a directory name', function () {
    expect(Path::relativeTo('/srv/project-two/Foo.php', '/srv/project'))
        ->toBe('/srv/project-two/Foo.php');
});
