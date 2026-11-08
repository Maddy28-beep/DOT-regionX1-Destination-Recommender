<?php

namespace App\Console\Commands;

use App\Support\StyleAsset;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MinifyStyles extends Command
{
    protected $signature = 'styles:minify {--check : Only report whether the minified copies are current}';

    protected $description = 'Write minified copies of the stylesheets the landing page loads (name.min.css beside name.css)';

    /** The stylesheets every page or the landing page loads first. */
    public const FILES = [
        'css/app.css', 'css/mobile-drawer.css', 'css/landing-sections.css',
        'css/mood-cards.css', 'css/hero-search.css', 'css/home-slides.css',
    ];

    public function handle(): int
    {
        $stale = 0;

        foreach (self::FILES as $file) {
            $source = public_path($file);
            $min = preg_replace('/\.css$/', '.min.css', $file);
            $sha = sha1_file($source);
            $current = StyleAsset::fingerprintOf($min) === $sha;

            if ($this->option('check')) {
                $this->line(($current ? 'current  ' : 'STALE    ').$file);
                $stale += $current ? 0 : 1;

                continue;
            }

            $esbuild = base_path('node_modules/.bin/esbuild'.(PHP_OS_FAMILY === 'Windows' ? '.cmd' : ''));
            $process = new Process([$esbuild, $source, '--minify', '--legal-comments=none', '--log-level=error']);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error($file.': '.trim($process->getErrorOutput()));

                return self::FAILURE;
            }

            file_put_contents(public_path($min), StyleAsset::HEADER.$sha."*/\n".$process->getOutput());
            $this->info(sprintf('%s  %s -> %s KB', $file, number_format(filesize($source) / 1024, 1), number_format(filesize(public_path($min)) / 1024, 1)));
        }

        return $stale ? self::FAILURE : self::SUCCESS;
    }
}
