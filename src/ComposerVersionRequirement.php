<?php

namespace Deviantintegral\ComposerGavel;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Semver\Semver;
use Deviantintegral\ComposerGavel\Exception\ConstraintException;

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
    $extra = $this->composer->getPackage()->getExtra();

    $validator = function($answer) {
      return $answer === 'Y' || $answer === 'y';
    };

    // See if this can be done during the initial plugin install.
    if (empty($extra['composer-version'])) {
      $this->io->askAndValidate(sprintf('No composer-version key is defined in the composer.json config. Set the Composer version constraint to %s? [Y/n] ', "^$version"), $validator, null, 'Y');
      $extra['composer-version'] = "^$version";
      $this->composer->getPackage()->setExtra($extra);
      // Write the new composer.json.
      $this->setConstraint($extra['composer-version']);
    }

    $constraint = $extra['composer-version'];
    if (!Semver::satisfies($version, $constraint)) {
      throw new ConstraintException(sprintf('Composer %s is in use but this project requires Composer %s. Upgrade composer by running composer self-update.', $version, $constraint));
    }

    $this->io->writeError(sprintf('<info>Composer %s satisfies composer-version %s.</info>', $version, $constraint));
  }

  protected function setConstraint($constraint) {
    $file = Factory::getComposerFile();
    if (!is_readable($file)) {
      $this->io->writeError('<error>'.$file.' is not readable.</error>');

      return 1;
    }
    if (!is_writable($file)) {
      $this->io->writeError('<error>'.$file.' is not writable.</error>');

      return 1;
    }

    $json = new JsonFile($file);
    $contents = file_get_contents($json->getPath());

    $manipulator = new JsonManipulator($contents);
    $manipulator->addProperty('extra.composer-version', $constraint);

    file_put_contents($json->getPath(), $manipulator->getContents());

    $this->io->writeError(sprintf('<info>Composer requirement set to %s.</info>', $constraint));
  }

}
