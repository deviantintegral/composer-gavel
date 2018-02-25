<?php

namespace Deviantintegral\ComposerGavel;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Semver\Semver;

class ComposerVersionRequirement implements PluginInterface, EventSubscriberInterface {

  /**
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * Apply plugin modifications to Composer
   *
   * @param \Composer\Composer $composer
   * @param \Composer\IO\IOInterface $io
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
    $io->write('Here');
  }

  /**
   * Returns an array of event names this subscriber wants to listen to.
   *
   * The array keys are event names and the value can be:
   *
   * * The method name to call (priority defaults to 0)
   * * An array composed of the method name to call and the priority
   * * An array of arrays composed of the method names to call and respective
   *   priorities, or 0 if unset
   *
   * For instance:
   *
   * * array('eventName' => 'methodName')
   * * array('eventName' => array('methodName', $priority))
   * * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
   *
   * @return array The event names to listen to
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::PRE_INSTALL_CMD => 'checkComposerVersion',
      ScriptEvents::PRE_UPDATE_CMD => 'checkComposerVersion',
    ];
  }

  public function checkComposerVersion(Event $event) {
    $version = $this->composer::VERSION;
    $constraint = $this->composer->getConfig()->get('composer-version');

    if (!Semver::satisfies($version, $constraint)) {
      throw new \RuntimeException(sprintf('Composer %s is in use but this project requires Composer %s. Upgrade composer by running composer self-update'));
    }
  }

}
