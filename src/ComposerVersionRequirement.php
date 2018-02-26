<?php

namespace Deviantintegral\ComposerGavel;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Locker;
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
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::PRE_INSTALL_CMD => 'checkComposerVersion',
      ScriptEvents::PRE_UPDATE_CMD => 'checkComposerVersion',
    ];
  }

  /**
   * Check currently running composer version, and offer to set a constraint.
   *
   * @param \Composer\Script\Event $event
   */
  public function checkComposerVersion(Event $event) {
    $version = $this->composer::VERSION;
    $extra = $this->composer->getPackage()->getExtra();

    // No composer version is currently defined, offer to add it if we are
    // running composer update.
    if (empty($extra['composer-version'])) {
      $this->io->writeError('<error>composer-version is not defined in extra in composer.json.</error>');
      // Don't offer to update composer.json when running composer update,
      // otherwise the content-hash will become invalid.
      if ($event->getName() == ScriptEvents::PRE_INSTALL_CMD
        || !($this->io->askAndValidate(sprintf('Set the Composer version constraint to %s? [Y/n] ', "^$version"), $this->validate(), null, true))) {
        return;
      }

      $extra['composer-version'] = "^$version";
      $this->composer->getPackage()->setExtra($extra);
      $this->writeConstraint($extra['composer-version']);
    }

    $constraint = $extra['composer-version'];
    if (!Semver::satisfies($version, $constraint)) {
      throw new ConstraintException(sprintf('Composer %s is in use but this project requires Composer %s. Upgrade composer by running composer self-update.', $version, $constraint));
    }

    $this->io->writeError(sprintf('<info>Composer %s satisfies composer-version %s.</info>', $version, $constraint));
  }

  /**
   * Write a composer version constraint to composer.json.
   *
   * @param string $constraint The semantic version of composer to require.
   *
   * @throws \RuntimeException Thrown when composer.json is not readable.
   */
  protected function writeConstraint($constraint) {
    $file = Factory::getComposerFile();
    if (!is_readable($file)) {
      throw new \RuntimeException(sprintf('%s is not readable.', $file));
    }
    if (!is_writable($file)) {
      throw new \RuntimeException(sprintf('%s is not writable.', $file));
    }

    // Load composer.json and save the constraint.
    $json = new JsonFile($file);
    $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
    $manipulator->addProperty('extra.composer-version', $constraint);
    $contents = $manipulator->getContents();
    file_put_contents($json->getPath(), $contents);

    // Update the lockfile's content-hash property.
    $lockFile = "json" === pathinfo($file, PATHINFO_EXTENSION)
      ? substr($file, 0, -4).'lock'
      : $file . '.lock';
    $locker = new Locker($this->io, new JsonFile($lockFile, null, $this->io), $this->composer->getRepositoryManager(), $this->composer->getInstallationManager(), $contents);
    $this->composer->setLocker($locker);

    $this->io->writeError(sprintf('<info>Composer requirement set to %s.</info>', $constraint));
  }

  /**
   * Validate y, n, and a newline as Y.
   *
   * @internal
   *
   * @return \Closure
   */
  public function validate(): \Closure {
    return function ($answer) {
      $normalized = strtolower($answer);
      if (!in_array($normalized, ['y', 'n', "\n"], true)) {
        throw new \RuntimeException("Enter 'y' or 'n'");
      }

      return ($normalized == 'y' || $normalized) && $normalized != 'n';
    };
  }

}
