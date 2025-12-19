<?php

namespace Deviantintegral\ComposerGavel\Tests;

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Locker;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Deviantintegral\ComposerGavel\ComposerVersionRequirement;
use Deviantintegral\ComposerGavel\Exception\ConstraintException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @class ComposerVersionRequirementTest
 *
 * @coversDefaultClass \Deviantintegral\ComposerGavel\ComposerVersionRequirement
 */
class ComposerVersionRequirementTest extends TestCase
{
    /**
     * @var string
     */
    protected $composerJsonFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Not all tests require this, but we want to ensure we never accidentally
        // touch our real composer.json.
        vfsStream::setup('project');
        $this->composerJsonFile = vfsStream::url('project/composer.json');
        file_put_contents($this->composerJsonFile, '{}');
        putenv("COMPOSER=$this->composerJsonFile");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Unset the COMPOSER variable.
        putenv('COMPOSER');
    }

    /**
     * @covers ::getSubscribedEvents
     */
    public function testGetSubscribedEvents(): void
    {
        $this->assertEquals([
            ScriptEvents::PRE_INSTALL_CMD => 'checkComposerVersion',
            ScriptEvents::PRE_UPDATE_CMD => 'checkComposerVersion',
        ], ComposerVersionRequirement::getSubscribedEvents());
    }

    /**
     * Test that a missing version constraint during install touches nothing.
     *
     * @covers ::activate
     * @covers ::checkComposerVersion
     */
    public function testCheckNoComposerVersionInstall(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&Composer $composer */
        $composer = $this->getMockBuilder(Composer::class)->getMock();

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->method('getPackage')->willReturn($package);

        $package->method('getExtra')->willReturn([]);
        // If setExtra is called then our check for the install event failed.
        $package->expects($this->never())->method('setExtra');

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->expects($this->once())->method('writeError')->with('<error>composer-version is not defined in extra in composer.json.</error>');

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);
        $vr->checkComposerVersion($event);
    }

    /**
     * Test that dev releases of composer don't fail.
     *
     * @covers ::activate
     * @covers ::checkComposerVersion
     */
    public function testCheckComposerDev(): void
    {
        /** @var DummyComposer $composer */
        $composer = new DummyComposer();

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->expects($this->once())->method('writeError')->with('<warning>You are running a development version of Composer. The Composer version will not be enforced.</warning>');

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);
        $vr->checkComposerVersion($event);
    }

    /**
     * Test that snapshot releases of composer don't fail.
     *
     * @covers ::activate
     * @covers ::checkComposerVersion
     */
    public function testCheckComposerSnapshot(): void
    {
        /** @var SnapshotComposer $composer */
        $composer = new SnapshotComposer();

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->expects($this->once())->method('writeError')->with('<warning>You are running a snapshot version of Composer (7ea15eec6c92fac66f4a5810b11b495e6ab1861b). The Composer version will not be enforced.</warning>');

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);
        $vr->checkComposerVersion($event);
    }

    /**
     * Test that uppercase snapshot releases are detected (case insensitive).
     *
     * @covers ::activate
     * @covers ::checkComposerVersion
     */
    public function testCheckComposerSnapshotUppercase(): void
    {
        /** @var SnapshotComposerUppercase $composer */
        $composer = new SnapshotComposerUppercase();

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->expects($this->once())->method('writeError')->with('<warning>You are running a snapshot version of Composer (7EA15EEC6C92FAC66F4A5810B11B495E6AB1861B). The Composer version will not be enforced.</warning>');

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);
        $vr->checkComposerVersion($event);
    }

    /**
     * Test that a version with a prefix is not detected as a snapshot.
     *
     * @covers ::activate
     * @covers ::checkComposerVersion
     */
    public function testCheckComposerSnapshotWithPrefixIsNotSnapshot(): void
    {
        /** @var SnapshotComposerWithPrefix $composer */
        $composer = new SnapshotComposerWithPrefix();

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->setPackage($package);

        $package->method('getExtra')->willReturn(['composer-version' => '^2.0']);

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);

        // This should throw because the version is not a valid snapshot
        // (has prefix) and doesn't match semver
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid version string "v7ea15eec6c92fac66f4a5810b11b495e6ab1861b"');
        $vr->checkComposerVersion($event);
    }

    /**
     * Test that a version with a suffix is not detected as a snapshot.
     *
     * @covers ::activate
     * @covers ::checkComposerVersion
     */
    public function testCheckComposerSnapshotWithSuffixIsNotSnapshot(): void
    {
        /** @var SnapshotComposerWithSuffix $composer */
        $composer = new SnapshotComposerWithSuffix();

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->setPackage($package);

        $package->method('getExtra')->willReturn(['composer-version' => '^2.0']);

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);

        // This should throw because the version is not a valid snapshot
        // (has suffix) and doesn't match semver
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid version string "7ea15eec6c92fac66f4a5810b11b495e6ab1861b-dirty"');
        $vr->checkComposerVersion($event);
    }

    /**
     * Test that a missing version constraint during update is added.
     *
     * @covers ::activate
     * @covers ::checkComposerVersion
     * @covers ::writeConstraint
     */
    public function testCheckAddComposerVersionUpdate(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&Composer $composer */
        $composer = $this->getMockBuilder(Composer::class)->getMock();
        $version = $composer::VERSION;
        $repositoryManager = $this->getMockBuilder(RepositoryManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $composer->method('getRepositoryManager')->willReturn($repositoryManager);
        $installationManager = $this->getMockBuilder(InstallationManager::class)
          ->disableOriginalConstructor()
          ->getMock();
        $composer->method('getInstallationManager')->willReturn($installationManager);

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->method('getPackage')->willReturn($package);

        $package->method('getExtra')->willReturn([]);
        // If setExtra is called then our check for the install event failed.
        $extra = ['composer-version' => '^'.$composer::VERSION];
        $package->expects($this->once())->method('setExtra')->with($extra);

        $composer->expects($this->once())->method('setLocker');
        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $expectedMessages = [
            '<error>composer-version is not defined in extra in composer.json.</error>',
            '<info>Composer requirement set to ^'.$version.'.</info>',
            '<info>Composer '.$version.' satisfies composer-version ^'.$version.'.</info>',
        ];
        $callCount = 0;
        $io->expects($this->exactly(3))->method('writeError')
          ->willReturnCallback(function ($message) use (&$callCount, $expectedMessages) {
              $this->assertEquals($expectedMessages[$callCount], $message);
              ++$callCount;
          });
        $io->expects($this->once())->method('askAndValidate')
          ->willReturnCallback(function ($question, $validator, $attempts, $default) {
              // Verify the default is true (pressing Enter means "yes")
              $this->assertTrue($default, 'The default should be true (yes) when user presses Enter');

              return true;
          });

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_UPDATE_CMD, $composer, $io);
        $vr->checkComposerVersion($event);

        $composerJson = new JsonFile($this->composerJsonFile);
        $this->assertEquals(['extra' => $extra], $composerJson->read());
    }

    /**
     * @covers ::checkComposerVersion
     */
    public function testConstraintPasses(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&Composer $composer */
        $composer = $this->getMockBuilder(Composer::class)->getMock();

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->method('getPackage')->willReturn($package);

        // This matches the constraint in require-dev in composer.json.
        $package->method('getExtra')->willReturn(['composer-version' => '^2.0']);

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->expects($this->once())->method('writeError')->with('<info>Composer '.$composer::VERSION.' satisfies composer-version ^2.0.</info>');

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);
        $vr->checkComposerVersion($event);
    }

    /**
     * @covers ::checkComposerVersion
     */
    public function testConstraintFails(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&Composer $composer */
        $composer = $this->getMockBuilder(Composer::class)->getMock();

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->method('getPackage')->willReturn($package);

        $package->method('getExtra')->willReturn(['composer-version' => '^2.9.9']);

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('Composer '.$composer::VERSION.' is in use but this project requires Composer ^2.9.9. Upgrade composer by running composer self-update.');
        $vr->checkComposerVersion($event);
    }

    /**
     * @covers ::validate
     */
    public function testValidate(): void
    {
        $vr = new ComposerVersionRequirement();
        $validator = $vr->validate();
        $this->assertTrue($validator('Y'));
        $this->assertTrue($validator('y'));
        $this->assertTrue($validator('1'));
    }

    /**
     * @covers ::validate
     */
    public function testValidateFalse(): void
    {
        $vr = new ComposerVersionRequirement();
        $validator = $vr->validate();
        $this->assertFalse($validator('N'));
        $this->assertFalse($validator('n'));
    }

    /**
     * @covers ::validate
     */
    public function testValidateInvalid(): void
    {
        $vr = new ComposerVersionRequirement();
        $validator = $vr->validate();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Enter 'y' or 'n'");
        $validator('invalid');
    }

    /**
     * @covers ::writeConstraint
     */
    public function testComposerJsonFileNotReadable(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&Composer $composer */
        $composer = $this->getMockBuilder(Composer::class)->getMock();

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->method('getPackage')->willReturn($package);

        $package->method('getExtra')->willReturn([]);
        // If setExtra is called then our check for the install event failed.
        $extra = ['composer-version' => '^'.$composer::VERSION];
        $package->expects($this->once())->method('setExtra')->with($extra);

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->expects($this->once())->method('writeError')->with('<error>composer-version is not defined in extra in composer.json.</error>');
        $io->expects($this->once())->method('askAndValidate')->willReturn(true);

        chmod($this->composerJsonFile, 0);

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_UPDATE_CMD, $composer, $io);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vfs://project/composer.json is not readable.');
        $vr->checkComposerVersion($event);
    }

    public function testComposerJsonFileNotWritable(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&Composer $composer */
        $composer = $this->getMockBuilder(Composer::class)->getMock();

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->method('getPackage')->willReturn($package);

        $package->method('getExtra')->willReturn([]);
        // If setExtra is called then our check for the install event failed.
        $extra = ['composer-version' => '^'.$composer::VERSION];
        $package->expects($this->once())->method('setExtra')->with($extra);

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->expects($this->once())->method('writeError')->with('<error>composer-version is not defined in extra in composer.json.</error>');
        $io->expects($this->once())->method('askAndValidate')->willReturn(true);

        chmod($this->composerJsonFile, 0444);

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_UPDATE_CMD, $composer, $io);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vfs://project/composer.json is not writable.');
        $vr->checkComposerVersion($event);
    }

    /**
     * Test that the lockfile path is correctly computed from composer.json.
     *
     * @covers ::writeConstraint
     */
    public function testLockfilePathIsCorrectlyComputed(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&Composer $composer */
        $composer = $this->getMockBuilder(Composer::class)->getMock();
        $repositoryManager = $this->getMockBuilder(RepositoryManager::class)
          ->disableOriginalConstructor()
          ->getMock();
        $composer->method('getRepositoryManager')->willReturn($repositoryManager);
        $installationManager = $this->getMockBuilder(InstallationManager::class)
          ->disableOriginalConstructor()
          ->getMock();
        $composer->method('getInstallationManager')->willReturn($installationManager);

        /** @var \PHPUnit\Framework\MockObject\MockObject&RootPackageInterface $package */
        $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $composer->method('getPackage')->willReturn($package);

        $package->method('getExtra')->willReturn([]);
        $extra = ['composer-version' => '^'.$composer::VERSION];
        $package->expects($this->once())->method('setExtra')->with($extra);

        $expectedLockFile = 'vfs://project/composer.lock';
        $composer->expects($this->once())->method('setLocker')
          ->willReturnCallback(function (Locker $locker) use ($expectedLockFile) {
              // Use reflection to verify the lockfile path
              $reflection = new \ReflectionClass($locker);
              $lockFileProperty = $reflection->getProperty('lockFile');
              $lockFile = $lockFileProperty->getValue($locker);

              $jsonFileReflection = new \ReflectionClass($lockFile);
              $pathProperty = $jsonFileReflection->getProperty('path');
              $path = $pathProperty->getValue($lockFile);

              $this->assertEquals($expectedLockFile, $path);
          });

        /** @var \PHPUnit\Framework\MockObject\MockObject&IOInterface $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->method('askAndValidate')->willReturn(true);

        $vr = new ComposerVersionRequirement();
        $vr->activate($composer, $io);

        $event = new Event(ScriptEvents::PRE_UPDATE_CMD, $composer, $io);
        $vr->checkComposerVersion($event);
    }
}

class DummyComposer extends Composer
{
    public const VERSION = '@package_version@';
}

class SnapshotComposer extends Composer
{
    public const VERSION = '7ea15eec6c92fac66f4a5810b11b495e6ab1861b';
}

class SnapshotComposerUppercase extends Composer
{
    public const VERSION = '7EA15EEC6C92FAC66F4A5810B11B495E6AB1861B';
}

class SnapshotComposerWithPrefix extends Composer
{
    public const VERSION = 'v7ea15eec6c92fac66f4a5810b11b495e6ab1861b';
}

class SnapshotComposerWithSuffix extends Composer
{
    public const VERSION = '7ea15eec6c92fac66f4a5810b11b495e6ab1861b-dirty';
}
