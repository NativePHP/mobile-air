<?php

use Illuminate\Support\Facades\File;
use Native\Mobile\Support\GradleJava;

/**
 * GradleJava picks the Java that starts the Gradle wrapper. The daemon's own
 * JDK is pinned by the template, so all that matters here is finding a JDK
 * 17+ to launch with, preferring what the developer chose.
 */
function fakeJdk(string $path, string $version): string
{
    File::ensureDirectoryExists($path);
    File::put($path.'/release', "IMPLEMENTOR=\"Test\"\nJAVA_VERSION=\"{$version}\"\n");

    return $path;
}

/** Where GradleJava looks for Android Studio's JDK under $HOME on this OS. */
function fakeAndroidStudioJdk(string $home, string $version): string
{
    $path = match (PHP_OS_FAMILY) {
        'Darwin' => $home.'/Applications/Android Studio.app/Contents/jbr/Contents/Home',
        'Windows' => getenv('LOCALAPPDATA').'\\Programs\\Android Studio\\jbr',
        default => $home.'/android-studio/jbr',
    };

    return fakeJdk($path, $version);
}

function skipWhenAndroidStudioIsInstalledSystemWide(object $test): void
{
    if (GradleJava::androidStudioJdks() !== []) {
        $test->markTestSkipped('Android Studio is installed outside $HOME, so there is always a fallback here.');
    }
}

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/nativephp_gradle_java_'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/home');

    $this->originalEnv = [
        'HOME' => getenv('HOME'),
        'JAVA_HOME' => getenv('JAVA_HOME'),
        'LOCALAPPDATA' => getenv('LOCALAPPDATA'),
    ];

    putenv('HOME='.$this->sandbox.'/home');
    putenv('LOCALAPPDATA='.$this->sandbox.'/home/AppData/Local');
    putenv('JAVA_HOME');
    config(['nativephp.android.gradle_jdk_path' => null]);
});

afterEach(function () {
    foreach ($this->originalEnv as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }

    File::deleteDirectory($this->sandbox);
});

it('reads major versions in both numbering schemes', function (string $version, ?int $major) {
    expect(GradleJava::majorVersion($version))->toBe($major);
})->with([
    ['25.0.3', 25],
    ['27', 27],
    ['21.0.12.1', 21],
    ['1.8.0_402', 8],
    ['not a version', null],
]);

it('reads the version from a JDK release file', function () {
    $jdk = fakeJdk($this->sandbox.'/jdk-21', '21.0.12.1');

    expect(GradleJava::versionOf($jdk))->toBe(21)
        ->and(GradleJava::versionOf($this->sandbox.'/not-a-jdk'))->toBeNull();
});

it('starts Gradle with JAVA_HOME when it is new enough, however new', function () {
    putenv('JAVA_HOME='.fakeJdk($this->sandbox.'/jdk-30', '30'));

    $java = GradleJava::find();

    expect($java->source)->toBe('JAVA_HOME')
        ->and($java->version)->toBe(30)
        ->and($java->environment())->toBe(['JAVA_HOME' => $this->sandbox.'/jdk-30']);
});

it('prefers NATIVEPHP_GRADLE_PATH over JAVA_HOME', function () {
    putenv('JAVA_HOME='.fakeJdk($this->sandbox.'/jdk-21', '21'));
    config(['nativephp.android.gradle_jdk_path' => fakeJdk($this->sandbox.'/jdk-25', '25.0.3')]);

    $java = GradleJava::find();

    expect($java->source)->toBe('NATIVEPHP_GRADLE_PATH')
        ->and($java->home)->toBe($this->sandbox.'/jdk-25');
});

it("falls back to Android Studio's JDK when JAVA_HOME is too old", function () {
    putenv('JAVA_HOME='.fakeJdk($this->sandbox.'/jdk-11', '11.0.2'));
    $jbr = fakeAndroidStudioJdk($this->sandbox.'/home', '25.0.3');

    $java = GradleJava::find();

    expect($java->source)->toBe('Android Studio')
        ->and($java->environment())->toBe(['JAVA_HOME' => $jbr]);
});

it("hands Android Studio's JDK to Gradle so the daemon pin can match it", function () {
    putenv('JAVA_HOME='.fakeJdk($this->sandbox.'/jdk-21', '21'));
    $jbr = fakeAndroidStudioJdk($this->sandbox.'/home', '25.0.3');

    expect(GradleJava::find()->gradleArguments())
        ->toBe(' '.escapeshellarg('-Dorg.gradle.java.installations.paths='.$jbr));
});

it('explains a JAVA_HOME that is too old when nothing else will do', function () {
    skipWhenAndroidStudioIsInstalledSystemWide($this);

    putenv('JAVA_HOME='.fakeJdk($this->sandbox.'/jdk-11', '11.0.2'));

    expect(GradleJava::find())->toBeNull()
        ->and(GradleJava::missingReason())->toBe('JAVA_HOME points to Java 11, and Gradle needs Java 17 or newer to start.');
});

it("explains a JAVA_HOME that isn't a JDK", function () {
    skipWhenAndroidStudioIsInstalledSystemWide($this);

    putenv('JAVA_HOME='.$this->sandbox.'/nowhere');

    expect(GradleJava::find())->toBeNull()
        ->and(GradleJava::missingReason())->toBe("JAVA_HOME is set to {$this->sandbox}/nowhere, which isn't a JDK.");
});

/** An Android project with the given wrapper Gradle, with or without the daemon JDK pin. */
function fakeAndroidProject(string $path, string $gradle, bool $pinned): string
{
    File::ensureDirectoryExists($path.'/gradle/wrapper');
    File::put(
        $path.'/gradle/wrapper/gradle-wrapper.properties',
        "distributionUrl=https\\://services.gradle.org/distributions/gradle-{$gradle}-bin.zip\n"
    );

    if ($pinned) {
        File::put($path.'/gradle/gradle-daemon-jvm.properties', "toolchainVersion=25\n");
    }

    return $path;
}

it('stops an unpinned Gradle 8 project from running on Java 25 before Gradle does', function () {
    putenv('JAVA_HOME='.fakeJdk($this->sandbox.'/jdk-25', '25.0.3'));
    $project = fakeAndroidProject($this->sandbox.'/old', '8.14.5', pinned: false);

    expect(GradleJava::find()->projectProblem($project))
        ->toBe("This Android project uses Gradle 8.14.5, which can't run on Java 25 (JAVA_HOME).");
});

it('lets older projects build on the Java their Gradle supports', function () {
    putenv('JAVA_HOME='.fakeJdk($this->sandbox.'/jdk-21', '21'));

    expect(GradleJava::find()->projectProblem(fakeAndroidProject($this->sandbox.'/old', '8.14.5', pinned: false)))->toBeNull();
});

it('lets pinned projects build whatever Java launches Gradle', function () {
    putenv('JAVA_HOME='.fakeJdk($this->sandbox.'/jdk-30', '30'));

    expect(GradleJava::find()->projectProblem(fakeAndroidProject($this->sandbox.'/new', '9.8.2', pinned: true)))->toBeNull();
});
