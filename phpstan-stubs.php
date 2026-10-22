<?php

namespace OpenSwoole;

use ArrayObject;

/**
 * phpstan-only bootstrap stub. OpenSwoole is an optional runtime extension:
 * it is absent from CI but may be loaded on dev machines, which makes the
 * "unknown class" error environment-dependent. Defining the class here when
 * the extension is missing gives static analysis a single, stable shape.
 * Not autoloaded at runtime; loaded via bootstrapFiles in phpstan.neon.
 */
if (! class_exists(Coroutine::class)) {
    final class Coroutine
    {
        public static function getCid(): int
        {
            return -1;
        }

        public static function getContext(): ?ArrayObject
        {
            return null;
        }
    }
}
