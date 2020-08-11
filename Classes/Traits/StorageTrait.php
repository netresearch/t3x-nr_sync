<?php


namespace Netresearch\NrSync\Traits;

use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * Class StorageTrait
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
trait StorageTrait
{
    /**
     * @var ResourceStorage
     */
    private $defaultStorage;

    /**
     * @var Folder
     */
    private $syncFolder;

    /**
     * @var Folder
     */
    private $tempFolder;

    /**
     * Identifier for TempFolder
     *
     * @var string
     */
    private $tempFolderIdentifier = "nr_sync_temp/";

    /**
     * @var string
     */
    private $baseFolderIdentifier = 'nr_sync/';

    /**
     * Returns the default storage
     *
     * @return ResourceStorage
     */
    private function getDefaultStorage(): ResourceStorage
    {
        if ($this->defaultStorage instanceof ResourceStorage) {
            return $this->defaultStorage;
        }

        $this->defaultStorage = ResourceFactory::getInstance()->getDefaultStorage();
        return $this->defaultStorage;
    }

    /**
     * Returns a instance of the temp-folder Instance
     *
     * @return Folder|\TYPO3\CMS\Core\Resource\InaccessibleFolder|null
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException
     */
    private function getTempFolder()
    {
        if ($this->tempFolder instanceof Folder) {
            return $this->tempFolder;
        }

        $storage = ResourceFactory::getInstance()->getStorageObject(1);
        if (false === $storage->hasFolder($this->tempFolderIdentifier)) {
            $storage->createFolder($this->tempFolderIdentifier);
        }

        $this->tempFolder = $storage->getFolder($this->tempFolderIdentifier);
        return $this->tempFolder;
    }

    /**
     * @return Folder
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException
     */
    private function getSyncFolder(): Folder
    {
        if (false === $this->getDefaultStorage()->hasFolder($this->baseFolderIdentifier)) {
            $this->getDefaultStorage()->createFolder($this->baseFolderIdentifier);
        }

        $this->syncFolder = $this->getDefaultStorage()->getFolder($this->baseFolderIdentifier);
        return $this->syncFolder;
    }
}
