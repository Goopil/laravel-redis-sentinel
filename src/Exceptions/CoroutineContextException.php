<?php

namespace Goopil\LaravelRedisSentinel\Exceptions;

use RuntimeException;

/**
 * Exception to be used if the coroutine runtime does not return the expected per-coroutine storage.
 */
class CoroutineContextException extends RuntimeException {}
