<?php

namespace Netresearch\NrSync;

use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extensionmanager\Utility\ConfigurationUtility;

/**
 * Class SyncLock
 *
 * @package   Netresearch\NrSync
 * @author    Sebatsian Mendel <sebastian.mendel@netresearch.de>
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class SyncLock
{
    /**
     * @var ExtensionConfiguration
     */
    private $extensionConfiguration;

    /**
     * SyncLock constructor.
     *
     * @param \Netresearch\NrSync\ExtensionConfiguration $extensionConfiguration
     */
    public function injectExtensionConfiguration(ExtensionConfiguration $extensionConfiguration)
    {
        $this->extensionConfiguration = $extensionConfiguration;
    }

    /**
     * Returns message for current lock.
     *
     * @return string
     */
    public function getLockMessage()
    {
        return (string) $this->extensionConfiguration->getConfigurationValue('syncModuleLockedMessage');
    }

    /**
     * React to requests concerning lock or unlock of the module.
     *
     * @return void
     * @throws Exception
     */
    public function handleLockRequest()
    {
        if (false === $this->receivedLockChangeRequest()) {
            return;
        }

        try {
            $this->storeLockConfiguration();
            $this->messageOk(
                'Sync module was ' . ($this->isLockRequested() ? 'locked.' : 'unlocked.')
            );
        } catch (\Exception $exception) {
            throw new Exception(
                'Error in nr_sync configuration: '
                . $exception->getMessage()
                . ' Please check configuration in the Extension Manager.'
            );
        }
    }

    /**
     * Send OK message to user.
     *
     * @param string $strMessage Message to user
     */
    protected function messageOk($strMessage)
    {
        /* @var $objectManager ObjectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);

        /* @var $message FlashMessage */
        $message = $objectManager->get(FlashMessage::class, $strMessage);

        /* @var $messageService FlashMessageService */
        $messageService = GeneralUtility::makeInstance(
            FlashMessageService::class
        );

        $messageService->getMessageQueueByIdentifier()->addMessage($message);
    }



    /**
     * Returns true if lock state change request was sent.
     *
     * @return bool
     */
    protected function receivedLockChangeRequest()
    {
        return isset($_REQUEST['data']['lock']);
    }

    /**
     * Returns requested lock state.
     *
     * @return bool
     */
    protected function isLockRequested()
    {
        return (bool) $_REQUEST['data']['lock'];
    }

    /**
     * Returns current lock state.
     *
     * @return bool
     */
    public function isLocked()
    {
        return (boolean) $this->extensionConfiguration->getConfigurationValue('syncModuleLocked');
    }


    /**
     * Persist lock state in extension configuration.
     *
     * @return void
     */
    protected function storeLockConfiguration()
    {
        $this->extensionConfiguration->setConfigurationValue('syncModuleLocked', (bool) $this->isLockRequested());
    }
}
