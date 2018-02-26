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
 * @class ComposerVersionRequirementTest
 * @package Deviantintegral\ComposerGavel\Tests
 * @coversDefaultClass \Deviantintegral\ComposerGavel\ComposerVersionRequirement
 */
class ComposerVersionRequirementTest extends TestCase {

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

}
