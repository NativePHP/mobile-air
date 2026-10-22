<?php

namespace Native\Mobile\Support;

use Illuminate\Support\Facades\Process;

/**
 * Picks the Java that starts Gradle for the Android build.
 *
 * The Android template pins the JDK the Gradle daemon runs on in
 * `gradle/gradle-daemon-jvm.properties`, and Gradle finds or downloads that
 * JDK itself. So all this has to find is something Gradle's launcher can
 * start on: any JDK 17 or newer, however new. In order of preference:
 * NATIVEPHP_GRADLE_PATH, JAVA_HOME, Android Studio's bundled JDK (JBR), then
 * `java` on the PATH.
 *
 * Android Studio's JBR is also passed to Gradle as an extra place to look
 * for JDKs, so a daemon pin that matches it doesn't need a download.
 */
final class GradleJava
{
    /** The oldest Java the template's Gradle wrapper can start on. */
    public const MINIMUM_VERSION = 17;

    private function __construct(
        public readonly ?string $home,
        public readonly int $version,
        public readonly string $source,
    ) {}

    /**
     * The Java to start Gradle with, or null when there is none new enough.
     */
    public static function find(): ?self
    {
        foreach (self::configuredHomes() as $source => $home) {
            $version = self::versionOf($home);

            if ($version !== null && $version >= self::MINIMUM_VERSION) {
                return new self($home, $version, $source);
            }
        }

        foreach (self::androidStudioJdks() as $jbr) {
            $version = self::versionOf($jbr);

            if ($version !== null && $version >= self::MINIMUM_VERSION) {
                return new self($jbr, $version, 'Android Studio');
            }
        }

        // With JAVA_HOME set, the Gradle wrapper never looks at the PATH
        if (self::env('JAVA_HOME') === null) {
            $version = self::versionOnPath();

            if ($version !== null && $version >= self::MINIMUM_VERSION) {
                return new self(null, $version, 'PATH');
            }
        }

        return null;
    }

    /**
     * Why find() came up empty, for the error shown before Gradle runs.
     */
    public static function missingReason(): string
    {
        foreach (self::configuredHomes() as $source => $home) {
            $version = self::versionOf($home);

            return $version === null
                ? "{$source} is set to {$home}, which isn't a JDK."
                : "{$source} points to Java {$version}, and Gradle needs Java ".self::MINIMUM_VERSION.' or newer to start.';
        }

        $version = self::versionOnPath();

        return $version === null
            ? 'No Java was found: JAVA_HOME is not set, Android Studio was not found, and there is no `java` on the PATH.'
            : "The `java` on the PATH is Java {$version}, and Gradle needs Java ".self::MINIMUM_VERSION.' or newer to start.';
    }

    /**
     * Environment variables for the Gradle process.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return $this->home === null ? [] : ['JAVA_HOME' => $this->home];
    }

    /**
     * Extra Gradle arguments that let the daemon's JDK pin match a JDK
     * Android Studio already has, instead of downloading one.
     */
    public function gradleArguments(): string
    {
        $jdks = self::androidStudioJdks();

        if ($jdks === []) {
            return '';
        }

        return ' '.escapeshellarg('-Dorg.gradle.java.installations.paths='.implode(',', $jdks));
    }

    /**
     * Why an Android project installed before the daemon JDK was pinned
     * can't build on this Java, if it can't.
     *
     * Without the pin the daemon runs on this Java too, and Gradle before 9.1
     * can't run on Java 25 or newer. It fails with nothing but the version
     * number: "What went wrong: 25.0.3".
     */
    public function projectProblem(string $androidPath): ?string
    {
        if (is_file($androidPath.'/gradle/gradle-daemon-jvm.properties') || $this->version < 25) {
            return null;
        }

        $wrapper = @file_get_contents($androidPath.'/gradle/wrapper/gradle-wrapper.properties');

        if (! is_string($wrapper) || ! preg_match('/gradle-(\d+\.\d+(?:\.\d+)?)-(?:bin|all)\.zip/', $wrapper, $matches)) {
            return null;
        }

        if (version_compare($matches[1], '9.1', '>=')) {
            return null;
        }

        return "This Android project uses Gradle {$matches[1]}, which can't run on Java {$this->version} ({$this->source}).";
    }

    public function describe(): string
    {
        return "Java {$this->version} from {$this->source}".($this->home !== null ? " ({$this->home})" : '');
    }

    /**
     * Java homes inside the Android Studio installs we know how to find.
     *
     * @return list<string>
     */
    public static function androidStudioJdks(): array
    {
        $home = self::env('HOME') ?? '';

        $candidates = match (PHP_OS_FAMILY) {
            'Darwin' => [
                '/Applications/Android Studio.app/Contents/jbr/Contents/Home',
                $home.'/Applications/Android Studio.app/Contents/jbr/Contents/Home',
                '/Applications/Android Studio Preview.app/Contents/jbr/Contents/Home',
                $home.'/Applications/Android Studio Preview.app/Contents/jbr/Contents/Home',
            ],
            'Windows' => [
                (self::env('ProgramFiles') ?? 'C:\\Program Files').'\\Android\\Android Studio\\jbr',
                self::env('LOCALAPPDATA').'\\Programs\\Android Studio\\jbr',
            ],
            default => [
                '/opt/android-studio/jbr',
                '/usr/local/android-studio/jbr',
                '/snap/android-studio/current/jbr',
                $home.'/android-studio/jbr',
                $home.'/.local/share/JetBrains/Toolbox/apps/android-studio/jbr',
            ],
        };

        return array_values(array_filter($candidates, fn (string $path) => self::versionOf($path) !== null));
    }

    /**
     * The major Java version of a Java home, read from its `release` file.
     */
    public static function versionOf(string $javaHome): ?int
    {
        $release = rtrim($javaHome, '/\\').DIRECTORY_SEPARATOR.'release';

        if (! is_file($release) || ! preg_match('/^JAVA_VERSION="([^"]+)"/m', (string) file_get_contents($release), $matches)) {
            return null;
        }

        return self::majorVersion($matches[1]);
    }

    /**
     * "25.0.3" is 25, "21" is 21, and the old "1.8.0_402" scheme is 8.
     */
    public static function majorVersion(string $version): ?int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?/', $version, $matches)) {
            return null;
        }

        return $matches[1] === '1' && isset($matches[2]) ? (int) $matches[2] : (int) $matches[1];
    }

    /**
     * Java homes the developer chose, keyed by where they set them.
     *
     * @return array<string, string>
     */
    private static function configuredHomes(): array
    {
        return array_filter([
            'NATIVEPHP_GRADLE_PATH' => trim((string) config('nativephp.android.gradle_jdk_path')) ?: null,
            'JAVA_HOME' => self::env('JAVA_HOME'),
        ]);
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function versionOnPath(): ?int
    {
        try {
            $result = Process::timeout(15)->run(['java', '-XshowSettings:properties', '-version']);
        } catch (\Throwable) {
            return null;
        }

        if (! $result->successful()
            || ! preg_match('/java\.specification\.version = (\S+)/', $result->errorOutput().$result->output(), $matches)) {
            return null;
        }

        return self::majorVersion($matches[1]);
    }
}
