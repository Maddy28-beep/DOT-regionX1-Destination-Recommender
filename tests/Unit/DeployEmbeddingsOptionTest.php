<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * deploy/server-update.sh must leave the stored embedding vectors alone on an ordinary deploy and load them only
 * when --refresh-embeddings is passed. The real script runs here against a sandbox: its server paths are swapped
 * for temporary ones and every command that would touch a server (sudo, php, composer, rsync, pg_dump, curl ...)
 * is a recording stub, so nothing real is installed, imported, backed up or restarted.
 */
class DeployEmbeddingsOptionTest extends TestCase
{
    private string $sandbox;

    /** The same folder as bash sees it (/c/... under Git Bash). */
    private string $b;

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $probe = new Process(['bash', '-c', 'echo ok && command -v tar >/dev/null && command -v md5sum >/dev/null']);
        $probe->run();
        if (! $probe->isSuccessful() || ! str_contains($probe->getOutput(), 'ok')) {
            $this->markTestSkipped('bash with tar and md5sum is needed to run the deploy script.');
        }

        $this->script = dirname(__DIR__, 2).'/deploy/server-update.sh';
        $this->sandbox = str_replace('\\', '/', sys_get_temp_dir()).'/deploy-sandbox-'.bin2hex(random_bytes(4));

        $this->b = $this->bashPath($this->sandbox);

        foreach (['bin', 'app/database/data', 'backups', 'stage', 'pkg/database/data', 'pkg/deploy'] as $dir) {
            mkdir($this->sandbox.'/'.$dir, 0777, true);
        }

        $stubs = [
            // `sudo -u USER cmd ...` -> cmd ...; the stubs below do the recording.
            'sudo' => "#!/usr/bin/env bash\nif [ \"\$1\" = \"-u\" ]; then shift 2; fi\nexec \"\$@\"\n",
            'php' => "#!/usr/bin/env bash\necho \"php \$*\" >> \"\$CALLS\"\n[ \"\$1 \$2 \$3\" = \"artisan list --raw\" ] && echo \"embeddings:import\"\nexit 0\n",
            'composer' => "#!/usr/bin/env bash\necho \"composer \$*\" >> \"\$CALLS\"\n",
            'rsync' => "#!/usr/bin/env bash\necho \"rsync\" >> \"\$CALLS\"\n",
            'systemctl' => "#!/usr/bin/env bash\necho \"systemctl \$*\" >> \"\$CALLS\"\n",
            'pg_dump' => "#!/usr/bin/env bash\necho \"-- dump\"\n",
            'curl' => "#!/usr/bin/env bash\necho 200\n",
            'chown' => "#!/usr/bin/env bash\nexit 0\n",
            'chmod' => "#!/usr/bin/env bash\nexit 0\n",
        ];
        foreach ($stubs as $name => $body) {
            file_put_contents($this->sandbox.'/bin/'.$name, $body);
            chmod($this->sandbox.'/bin/'.$name, 0755);
        }

        file_put_contents($this->sandbox.'/app/database/data/destination-embeddings.json', '{"vectors":"old"}');
        file_put_contents($this->sandbox.'/pkg/database/data/destination-embeddings.json', '{"vectors":"old"}');
        file_put_contents($this->sandbox.'/pkg/artisan', "<?php\n");

        $source = str_replace(
            ['/var/www/exploredvo', '/var/backups/exploredvo', '/root/exploredvo-stage.', '/etc/cron.d/exploredvo-schedule'],
            [$this->b.'/app', $this->b.'/backups', $this->b.'/stage/exploredvo-stage.', $this->b.'/cron'],
            file_get_contents($this->script)
        );
        file_put_contents($this->sandbox.'/server-update.sandbox.sh', $source);
        // The package carries the same script as the one that runs, exactly as a real package built from one commit does.
        file_put_contents($this->sandbox.'/pkg/deploy/server-update.sh', $source);
        file_put_contents($this->sandbox.'/run.sh', "#!/usr/bin/env bash\nexport CALLS=\"".$this->sandbox."/calls.log\"\nexport PATH=\"".$this->bashPath($this->sandbox).'/bin:$PATH"'."\nexec bash \"".$this->sandbox."/server-update.sandbox.sh\" \"\$@\"\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->sandbox) && is_dir($this->sandbox)) {
            (new Process(['bash', '-c', 'rm -rf '.escapeshellarg($this->b)]))->run();
        }

        parent::tearDown();
    }

    /** C:/x/y -> /c/x/y, so it can sit in a PATH list under Git Bash; unchanged on Linux. */
    private function bashPath(string $path): string
    {
        return preg_match('~^([A-Za-z]):/(.*)$~', $path, $m) ? '/'.strtolower($m[1]).'/'.$m[2] : $path;
    }

    /** Packs the sandbox "package" (code files only, with the given vector file) and runs the script. */
    private function deploy(array $args = [], ?string $vectorsInPackage = null): array
    {
        if ($vectorsInPackage !== null) {
            file_put_contents($this->sandbox.'/pkg/database/data/destination-embeddings.json', $vectorsInPackage);
        }

        $pack = new Process(['bash', '-c', 'tar -czf '.escapeshellarg($this->b.'/package.tar.gz').' -C '.escapeshellarg($this->b.'/pkg').' .']);
        $pack->mustRun();

        @unlink($this->sandbox.'/calls.log');
        $run = new Process(['bash', $this->b.'/run.sh', $this->b.'/package.tar.gz', 'abc1234', ...$args]);
        $run->run();

        $calls = is_file($this->sandbox.'/calls.log') ? array_values(array_filter(explode("\n", file_get_contents($this->sandbox.'/calls.log')))) : [];

        return [$run->getExitCode(), $run->getOutput().$run->getErrorOutput(), $calls];
    }

    private function importCalls(array $calls): array
    {
        return array_keys(array_filter($calls, fn ($c) => str_contains($c, 'embeddings:import') && str_starts_with($c, 'php artisan embeddings:import')));
    }

    public function test_an_ordinary_deploy_never_imports_embeddings(): void
    {
        [$code, $output, $calls] = $this->deploy();

        $this->assertSame(0, $code, $output);
        $this->assertSame([], $this->importCalls($calls), 'no embeddings:import on a code-only deploy');
        $this->assertStringContainsString('embeddings: left as they are', $output);
        $this->assertStringContainsString('OK -- abc1234 is live.', $output);
    }

    public function test_an_explicit_refresh_imports_embeddings_once_between_migrations_and_caching(): void
    {
        [$code, $output, $calls] = $this->deploy(['--refresh-embeddings']);

        $this->assertSame(0, $code, $output);
        $imports = $this->importCalls($calls);
        $this->assertCount(1, $imports);

        $migrate = array_search('php artisan migrate --force', $calls, true);
        $cache = array_search('php artisan config:cache', $calls, true);
        $this->assertNotFalse($migrate);
        $this->assertNotFalse($cache);
        $this->assertGreaterThan($migrate, $imports[0], 'imported after the migrations, as before');
        $this->assertLessThan($cache, $imports[0], 'imported before the caches are rebuilt, as before');
        $this->assertStringContainsString('loading similar-place vectors (--refresh-embeddings)', $output);
    }

    public function test_every_other_step_is_identical_with_and_without_the_refresh(): void
    {
        [$codeA, $outA, $callsA] = $this->deploy();
        [$codeB, $outB, $callsB] = $this->deploy(['--refresh-embeddings']);

        $this->assertSame(0, $codeA, $outA);
        $this->assertSame(0, $codeB, $outB);
        $this->assertSame($callsA, array_values(array_filter($callsB, fn ($c) => ! in_array($c, ['php artisan embeddings:import', 'php artisan list --raw'], true))), 'the same commands in the same order, apart from the import and the check that the command exists');

        foreach (['[1/8] Database backup', '[2/8]', '[3/8]', '[4/8]', '[5/8]', '[6/8]', '[7/8]', '[8/8]'] as $step) {
            $this->assertStringContainsString($step, $outA);
            $this->assertStringContainsString($step, $outB);
        }

        $expected = ['php artisan migrate --force', 'php artisan config:cache', 'php artisan route:cache', 'php artisan view:cache', 'php artisan cache:clear', 'systemctl reload php8.3-fpm'];
        foreach ($expected as $call) {
            $this->assertContains($call, $callsA);
        }
        $this->assertNotEmpty(glob($this->sandbox.'/backups/pre-deploy-*-abc1234.sql.gz'), 'the database backup is still taken first');
        $this->assertFileExists($this->sandbox.'/cron');
    }

    public function test_an_unknown_option_stops_before_anything_changes(): void
    {
        [$code, $output, $calls] = $this->deploy(['--refresh-embedings']);

        $this->assertSame(64, $code);
        $this->assertStringContainsString('Unknown option', $output);
        $this->assertSame([], $calls, 'no command ran');
        $this->assertSame([], glob($this->sandbox.'/backups/*'), 'no backup, so nothing was started');
        $this->assertSame([], glob($this->sandbox.'/stage/*'), 'no stage folder left behind');
    }

    public function test_a_changed_vector_file_is_flagged_but_not_loaded_on_an_ordinary_deploy(): void
    {
        [$code, $output, $calls] = $this->deploy([], '{"vectors":"new"}');

        $this->assertSame(0, $code, $output);
        $this->assertSame([], $this->importCalls($calls));
        $this->assertStringContainsString('ships a different vector file', $output);
        $this->assertStringContainsString('-RefreshEmbeddings', $output);
        $this->assertSame('{"vectors":"old"}', file_get_contents($this->sandbox.'/app/database/data/destination-embeddings.json'), 'the server copy is not touched by the check');
    }

    public function test_no_reminder_when_the_vector_file_is_unchanged_or_when_refreshing(): void
    {
        [, $same] = $this->deploy([], '{"vectors":"old"}');
        $this->assertStringNotContainsString('ships a different vector file', $same);

        [, $refreshing] = $this->deploy(['--refresh-embeddings'], '{"vectors":"new"}');
        $this->assertStringNotContainsString('ships a different vector file', $refreshing);
    }

    public function test_the_script_has_a_single_guarded_import_and_the_pc_script_only_asks_for_it_on_request(): void
    {
        $server = file_get_contents($this->script);
        $lines = array_filter(explode("\n", $server), fn ($l) => preg_match('/artisan embeddings:import/', $l) && ! str_starts_with(trim($l), '#'));
        $this->assertCount(1, $lines, 'exactly one place can import');

        $guard = strpos($server, 'if [ "$REFRESH_EMBEDDINGS" = "1" ]');
        $import = strpos($server, 'artisan embeddings:import');
        $this->assertNotFalse($guard);
        $this->assertGreaterThan($guard, $import, 'the import sits inside the opt-in branch');

        $pc = file_get_contents(dirname(__DIR__, 2).'/deploy/deploy.ps1');
        $this->assertStringContainsString('[switch]$RefreshEmbeddings', $pc);
        $this->assertSame(1, substr_count($pc, '--refresh-embeddings'), 'the flag is only ever added by the switch');
        $this->assertMatchesRegularExpression('/\$refresh = if \(\$RefreshEmbeddings\) \{ " --refresh-embeddings" \} else \{ "" \}/', $pc);
        $this->assertStringContainsString('$sha$refresh"', $pc);
    }

    public function test_a_script_that_differs_from_the_one_in_the_package_stops_before_anything_is_deployed(): void
    {
        // A stale copy of the script (for example an older one from a checkout left on another branch).
        file_put_contents($this->sandbox.'/pkg/deploy/server-update.sh', "#!/usr/bin/env bash\n# an older version\nphp artisan embeddings:import\n");

        [$code, $output, $calls] = $this->deploy();

        $this->assertSame(65, $code, $output);
        $this->assertStringContainsString('is not the same as the one in the package', $output);
        $this->assertSame([], $this->importCalls($calls), 'no embeddings:import');
        $this->assertSame([], array_values(array_filter($calls, fn ($c) => $c === 'rsync' || str_contains($c, 'migrate') || str_starts_with($c, 'composer'))), 'no code copied, no packages, no migrations');
        $this->assertStringNotContainsString('is live', $output);
    }

    public function test_a_package_without_the_script_is_refused_too(): void
    {
        unlink($this->sandbox.'/pkg/deploy/server-update.sh');

        [$code, $output, $calls] = $this->deploy();

        $this->assertSame(65, $code, $output);
        $this->assertNotContains('rsync', $calls);
    }

    public function test_line_endings_alone_do_not_trip_the_script_check(): void
    {
        $script = file_get_contents($this->sandbox.'/pkg/deploy/server-update.sh');
        file_put_contents($this->sandbox.'/pkg/deploy/server-update.sh', str_replace(["\r\n", "\n"], "\r\n", $script));

        [$code, $output] = $this->deploy();

        $this->assertSame(0, $code, $output);
    }
}
