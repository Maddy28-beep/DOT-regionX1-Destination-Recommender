<?php

namespace Tests\Concerns;

/**
 * Points the landing page at a temporary folder for its hero footage and photograph (config/hero.php), so a test can offer
 * or withhold either without touching a real file under public/. The folder is removed when the test ends.
 *
 * Nothing is chosen until a test asks: by default there is no photograph and no footage, which is the bare illustrated hero.
 */
trait UsesHeroFixtures
{
    private ?string $heroDir = null;

    protected function heroDir(): string
    {
        if ($this->heroDir === null) {
            $this->heroDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hero-fixture-'.bin2hex(random_bytes(6));
            mkdir($this->heroDir, 0777, true);
            config(['hero.video_directory' => $this->heroDir, 'hero.photo_path' => $this->heroDir.DIRECTORY_SEPARATOR.'no-photo.jpg']);

            $dir = $this->heroDir;
            $this->beforeApplicationDestroyed(function () use ($dir) {
                foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            });
        }

        return $this->heroDir;
    }

    /** A stand-in file in the footage folder (hero.mp4, hero.webm or hero-poster.jpg). */
    protected function putHeroFile(string $file): void
    {
        file_put_contents($this->heroDir().DIRECTORY_SEPARATOR.$file, 'not-really-a-file');
    }

    /** Makes the Mount Apo photograph "exist" for this test. */
    protected function putHeroPhoto(): void
    {
        $path = $this->heroDir().DIRECTORY_SEPARATOR.'photo.jpg';
        file_put_contents($path, 'not-really-a-photo');
        config(['hero.photo_path' => $path]);
    }
}
