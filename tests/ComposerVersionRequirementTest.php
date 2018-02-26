<?php

namespace Deviantintegral\ComposerGavel\Tests;

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Deviantintegral\ComposerGavel\ComposerVersionRequirement;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @class ComposerVersionRequirementTest
 * @package Deviantintegral\ComposerGavel\Tests
 * @coversDefaultClass \Deviantintegral\ComposerGavel\ComposerVersionRequirement
 */
class ComposerVersionRequirementTest extends TestCase {

  /**
   * @var string
   */
  protected $composerJsonFile;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Not all tests require this, but we want to ensure we never accidentally
    // touch our real composer.json.
    vfsStream::setup('project');
    $this->composerJsonFile = vfsStream::url('project/composer.json');
    file_put_contents($this->composerJsonFile, '{}');
    putenv("COMPOSER=$this->composerJsonFile");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown() {
    parent::tearDown();
    // Unset the COMPOSER variable.
    putenv('COMPOSER');
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
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
  public function testCheckNoComposerVersionInstall() {
    /** @var \PHPUnit\Framework\MockObject\MockObject|\Composer\Composer $composer */
    $composer = $this->getMockBuilder(Composer::class)->getMock();

    /** @var \PHPUnit\Framework\MockObject\MockObject|\Composer\Package\RootPackageInterface $package */
    $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
    $composer->method('getPackage')->willReturn($package);

    $package->method('getExtra')->willReturn([]);
    // If setExtra is called then our check for the install event failed.
    $package->expects($this->never())->method('setExtra');

    /** @var \PHPUnit\Framework\MockObject\MockObject|\Composer\IO\IOInterface $io */
    $io = $this->getMockBuilder(IOInterface::class)->getMock();
    $io->expects($this->once())->method('writeError')->with('<error>composer-version is not defined in extra in composer.json.</error>');

    $vr = new ComposerVersionRequirement();
    $vr->activate($composer, $io);

    $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $composer, $io);
    $vr->checkComposerVersion($event);
  }

  /**
   * Test that a missing version constraint during update is added.
   *
   * @covers ::activate
   * @covers ::checkComposerVersion
   * @covers ::writeConstraint
   */
  public function testCheckAddComposerVersionUpdate() {
    /** @var \PHPUnit\Framework\MockObject\MockObject|\Composer\Composer $composer */
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

    /** @var \PHPUnit\Framework\MockObject\MockObject|\Composer\Package\RootPackageInterface $package */
    $package = $this->getMockBuilder(RootPackageInterface::class)->getMock();
    $composer->method('getPackage')->willReturn($package);

    $package->method('getExtra')->willReturn([]);
    // If setExtra is called then our check for the install event failed.
    $extra = ['composer-version' => '^' . $composer::VERSION];
    $package->expects($this->once())->method('setExtra')->with($extra);

    $composer->expects($this->once())->method('setLocker');
    /** @var \PHPUnit\Framework\MockObject\MockObject|\Composer\IO\IOInterface $io */
    $io = $this->getMockBuilder(IOInterface::class)->getMock();
    $io->expects($this->exactly(3))->method('writeError')->withConsecutive([
      '<error>composer-version is not defined in extra in composer.json.</error>',
    ], [
      '<info>Composer requirement set to ^' . $version . '.</info>',
    ], [
      '<info>Composer ' . $version . ' satisfies composer-version ^' . $version . '.</info>',
    ]);
    $io->expects($this->once())->method('askAndValidate')->willReturn(true);

    $vr = new ComposerVersionRequirement();
    $vr->activate($composer, $io);

    $event = new Event(ScriptEvents::PRE_UPDATE_CMD, $composer, $io);
    $vr->checkComposerVersion($event);

    $composerJson = new JsonFile($this->composerJsonFile);
    $this->assertEquals(['extra' => $extra], $composerJson->read());
  }

  /**
   * @covers ::validate
   */
  public function testValidate() {
    $vr = new ComposerVersionRequirement();
    $validator = $vr->validate();
    $this->assertTrue($validator('Y'));
    $this->assertTrue($validator('y'));
    $this->assertTrue($validator('1'));
  }

  /**
   * @covers ::validate
   */
  public function testValidateFalse() {
    $vr = new ComposerVersionRequirement();
    $validator = $vr->validate();
    $this->assertFalse($validator('N'));
    $this->assertFalse($validator('n'));
  }

  /**
   * @covers ::validate
   */
  public function testValidateInvalid() {
    $vr = new ComposerVersionRequirement();
    $validator = $vr->validate();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("Enter 'y' or 'n'");
    $validator('invalid');
  }

}
