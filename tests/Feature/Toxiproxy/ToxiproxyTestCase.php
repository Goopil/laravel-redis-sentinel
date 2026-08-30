<?php

namespace Goopil\LaravelRedisSentinel\Tests\Feature\Toxiproxy;

use Goopil\LaravelRedisSentinel\Tests\Support\Toxiproxy\InteractsWithToxiproxy;
use Goopil\LaravelRedisSentinel\Tests\TestCase;

/**
 * Base class for CLASS-BASED chaos tests.
 *
 * Pest closure tests in this folder are wired to the InteractsWithToxiproxy trait
 * through tests/Pest.php instead (a bound test-case class cannot coexist with the
 * global TestCase binding, and Pest's Testable trait shadows inherited setUp()).
 */
abstract class ToxiproxyTestCase extends TestCase
{
    use InteractsWithToxiproxy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootToxiproxy();
    }

    protected function tearDown(): void
    {
        $this->cleanupToxiproxy();

        parent::tearDown();
    }
}
