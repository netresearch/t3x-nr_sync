<?php

namespace Netresearch\NrSync;

use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Extension Configuration.
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Thomas Schöne <thomas.schoene@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class ExtensionConfiguration
{
    private $extensionKey = 'nr_sync';

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * ExtensionConfiguration constructor.
     *
     * @param ObjectManager        $objectManager
     */
    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Returns a desired extension configuration
     *
     * @param string $name Name of extension configuration to get
     *
     * @return mixed
     * @throws \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException
     * @throws \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException
     */
    public function getConfigurationValue(string $name)
    {
        if (true === $this->isNewerTypo3()) {
            $extensionConfiguration = $this->objectManager->get(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class);
            return $extensionConfiguration->get($this->extensionKey, $name);
        }

        $configurationUtility = $this->objectManager->get(TYPO3\CMS\Extensionmanager\Utility\ConfigurationUtility::class);
        $extensionConfiguration = $configurationUtility->getCurrentConfiguration($this->extensionKey);

        return $extensionConfiguration[$name]['value'];
    }

    /**
     * Saves a extension configuration
     *
     * @param string $name  Name of extension configuration
     * @param mixed  $value Value to save
     *
     * @return void
     */
    public function setConfigurationValue(string $name, $value): void
    {
        if (true === $this->isNewerTypo3()) {
            $extensionConfiguration = $this->objectManager->get(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class);
            $extensionConfiguration->set($this->extensionKey, $name, $value);
            return;
        }

        $configurationUtility = $this->objectManager->get(TYPO3\CMS\Extensionmanager\Utility\ConfigurationUtility::class);
        $extensionConfiguration = $configurationUtility->getCurrentConfiguration($this->extensionKey);

        $extensionConfiguration[$name]['value'] = $value;

        /** @var array $nestedConfiguration */
        $nestedConfiguration = $configurationUtility->convertValuedToNestedConfiguration($extensionConfiguration);

        // i want to have updated the configuration during run time
        // so any following check will have new updated values
        $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extensionKey] = serialize(
            $nestedConfiguration
        );

        $configurationUtility->writeConfiguration($nestedConfiguration, $this->extensionKey);
    }

    /**
     * Return true if typo3 version is newer or equal 9
     *
     * @return bool
     */
    public function isNewerTypo3()
    {
        return VersionNumberUtility::getCurrentTypo3Version() >= 9;
    }
}
