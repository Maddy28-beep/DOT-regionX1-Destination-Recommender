<?php

namespace Tests\Feature;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The other JS tests only look for strings in the scripts, so a syntax error (one stray apostrophe) slips through and
 * the whole script silently never runs in the browser. This parses every shipped script with `node --check`.
 */
class PublicJsSyntaxTest extends TestCase
{
    public function test_every_public_script_parses(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not installed.');
        }

        $scripts = glob(public_path('js/*.js'));
        $this->assertNotEmpty($scripts);

        foreach ($scripts as $script) {
            $process = new Process([$node, '--check', $script]);
            $process->run();

            $this->assertTrue($process->isSuccessful(), basename($script)." has a syntax error:\n".$process->getErrorOutput());
        }
    }
}
