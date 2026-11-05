<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * An unanchored rsync pattern such as 'vendor/' matches a folder of that name at any depth, so it also skipped
 * public/vendor/ (Bootstrap, Chart.js, Leaflet) and those files never reached the server. Keep every exclude anchored
 * to the top of the package.
 */
class DeployRsyncExcludesTest extends TestCase
{
    public function test_deploy_excludes_are_anchored_so_public_vendor_is_still_copied(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/deploy/server-update.sh');

        preg_match_all("/--exclude='([^']+)'/", $script, $m);
        $this->assertNotEmpty($m[1], 'the rsync excludes were not found');

        foreach ($m[1] as $pattern) {
            $this->assertStringStartsWith('/', $pattern, "exclude '{$pattern}' must be anchored with a leading /");
        }

        $this->assertContains('/vendor/', $m[1]);
        $this->assertNotContains('/public/vendor/', $m[1]);
    }
}
