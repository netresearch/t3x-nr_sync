<?php


namespace Netresearch\NrSync\Traits;

use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class FlashMessage Trait
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
trait FlashMessageTrait
{
    /**
     * Creates an error message
     *
     * @param string $message Error message
     *
     * @return void
     */
    private function addErrorMessage(string $message): void
    {
        $this->createFlashMessage($message, '', FlashMessage::ERROR);
    }

    /**
     * Creates an error message
     *
     * @param string $message Error message
     *
     * @return void
     */
    private function addInfoMessage(string $message): void
    {
        $this->createFlashMessage($message, '', FlashMessage::INFO);
    }

    /**
     * Creates an error message
     *
     * @param string $message Error message
     *
     * @return void
     */
    private function addWarningMessage(string $message): void
    {
        $this->createFlashMessage($message, '', FlashMessage::WARNING);
    }

    /**
     * Creates an error message
     *
     * @param string $message Error message
     *
     * @return void
     */
    private function addSuccessMessage(string $message): void
    {
        $this->createFlashMessage($message, '', FlashMessage::OK);
    }

    /**
     * Creates an error message
     *
     * @param string $message Error message
     *
     * @return void
     */
    private function addNoticeMessage(string $message): void
    {
        $this->createFlashMessage($message, '', FlashMessage::NOTICE);
    }



    /**
     * creates a error via flash-message
     *
     * @param string  $message  content of the error
     * @param string  $headline Headline
     * @param integer $severity Severity of the message
     *
     * @return void
     *@throws \InvalidArgumentException
     */
    private function createFlashMessage(string $message, string $headline = '', $severity = FlashMessage::INFO): void
    {
        /** @var FlashMessage $flashMessage */
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $headline,
            $severity
        );

        /** @var FlashMessageQueue $flashMessageQueue */
        $flashMessageService = GeneralUtility::makeInstance(ObjectManager::class)->get(FlashMessageService::class);
        $flashMessageQueue = $flashMessageService->getMessageQueueByIdentifier('core.template.flashMessages');
        $flashMessageQueue->addMessage($flashMessage);
    }
}
