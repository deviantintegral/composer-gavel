<?php

namespace Deviantintegral\ComposerGavel\Tests;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Deviantintegral\ComposerGavel\ComposerVersionRequirement;
use PHPUnit\Framework\TestCase;

/**
 * Functional test using the real Composer snapshot PHAR.
 *
 * @coversDefaultClass \Deviantintegral\ComposerGavel\ComposerVersionRequirement
 */
class ComposerSnapshotFunctionalTest extends TestCase
{
    private const SNAPSHOT_PHAR = __DIR__.'/fixtures/composer-snapshot.phar';

    private static ?string $snapshotVersion = null;

    /**
     * Get the VERSION constant from the snapshot PHAR using a subprocess.
     *
     * This is necessary because the vendor Composer is already loaded
     * by PHPUnit, so we can't load the PHAR's Composer class directly.
     */
    private function getSnapshotVersion(): string
    {
        if (null === self::$snapshotVersion) {
            $code = \sprintf(
                'require "phar://%s/vendor/autoload.php"; echo Composer\\Composer::VERSION;',
                self::SNAPSHOT_PHAR
            );
            $output = [];
            $returnVar = 0;
            exec(\sprintf('php -r %s 2>/dev/null', escapeshellarg($code)), $output, $returnVar);

            if (0 !== $returnVar || empty($output)) {
                $this->fail('Failed to extract VERSION from Composer snapshot PHAR');
            }

            self::$snapshotVersion = $output[0];
        }

        return self::$snapshotVersion;
    }

    /**
     * Test that the snapshot PHAR has a 40-character git hash as VERSION.
     */
    public function testSnapshotPharHasGitHashVersion(): void
    {
        $version = $this->getSnapshotVersion();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/i',
            $version,
            \sprintf('Composer snapshot should have a 40-character git hash as VERSION, got: %s', $version)
        );
    }

    /**
     * Functional test that composer-gavel works with snapshot releases.
     *
     * This test creates a Composer subclass with the real snapshot's
     * VERSION constant to verify that the plugin correctly handles
     * snapshot versions (git commit hashes) by issuing a warning
     * instead of throwing an exception.
     *
     * @covers ::activate
     * @covers ::checkComposerVersion
     *
     * @depends testSnapshotPharHasGitHashVersion
     */
    public function testComposerGavelWorksWithSnapshotRelease(): void
    {
        $snapshotVersion = $this->getSnapshotVersion();

        // Create a dynamic Composer class with the snapshot VERSION
        $className = 'Deviantintegral\\ComposerGavel\\Tests\\SnapshotComposerFunctional_'.substr($snapshotVersion, 0, 8);
        if (!class_exists($className)) {
            eval(\sprintf(
                'namespace Deviantintegral\\ComposerGavel\\Tests; class SnapshotComposerFunctional_%s extends \\Composer\\Composer { public const VERSION = %s; }',
                substr($snapshotVersion, 0, 8),
                var_export($snapshotVersion, true)
            ));
        }

        /** @var Composer $composer */
        $composer = new $className();

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();

        // Set a composer-version constraint that would normally be checked
        $package->method('getExtra')->willReturn(['composer-version' => '^2.0']);

        // Use reflection to set the package
        $reflection = new \ReflectionClass($composer);
        // Find the package property by traversing parent classes
        while ($reflection && !$reflection->hasProperty('package')) {
            $reflection = $reflection->getParentClass();
        }
        if ($reflection) {
            $property = $reflection->getProperty('package');
            $property->setValue($composer, $package);
        } else {
            $this->fail('Could not find package property on Composer class');
        }

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();

        // Expect a warning to be written about the snapshot version
        $io->expects($this->once())
            ->method('writeError')
            ->with($this->stringContains('You are running a snapshot version of Composer'));

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);

        // This should NOT throw an exception - it should issue a warning and return
        $vr->checkComposerVersion($event);
    }
}
