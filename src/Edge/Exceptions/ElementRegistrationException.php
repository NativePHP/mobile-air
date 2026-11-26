<?php

namespace Native\Mobile\Edge\Exceptions;

use LogicException;

/**
 * Something tried to register an element type that it can't have: another
 * owner already holds the type, or the registry locked when the app booted.
 */
class ElementRegistrationException extends LogicException
{
    public function __construct(
        string $message,
        public readonly string $type,
        public readonly ?string $currentOwner,
        public readonly string $attemptedOwner,
    ) {
        parent::__construct($message);
    }

    public static function alreadyRegistered(
        string $type,
        string $currentOwner,
        string $currentClass,
        string $attemptedOwner,
        string $attemptedClass,
    ): self {
        $message = $currentOwner === $attemptedOwner
            ? "Element type '{$type}' is already registered by {$currentOwner} as {$currentClass}. ".
                "It can't be registered again as {$attemptedClass}."
            : "Element type '{$type}' is already registered by {$currentOwner} as {$currentClass}. ".
                "{$attemptedOwner} tried to register {$attemptedClass} for it. ".
                'Give the new element its own type, ideally prefixed with the vendor name.';

        return new self($message.self::removalHint($attemptedOwner), $type, $currentOwner, $attemptedOwner);
    }

    public static function locked(string $type, string $attemptedOwner, string $attemptedClass): self
    {
        return new self(
            "Element type '{$type}' can't be registered by {$attemptedOwner} ({$attemptedClass}): ".
            'the element registry is locked once the app has booted. '.
            'Register elements while the app boots: in a plugin\'s nativephp.json `components`, or a service provider\'s boot().',
            $type,
            null,
            $attemptedOwner,
        );
    }

    /**
     * The app can't boot while a plugin's claim is refused, artisan included,
     * so say how to get it booting again.
     */
    protected static function removalHint(string $owner): string
    {
        return str_contains($owner, '/')
            ? " To boot without it, remove {$owner} from plugins() in app/Providers/NativeServiceProvider.php."
            : '';
    }
}
