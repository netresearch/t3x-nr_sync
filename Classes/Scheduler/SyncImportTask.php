<?php


namespace Netresearch\NrSync\Scheduler;

use Netresearch\NrSync\Traits\StorageTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Scheduler task to import the sync MyQSL Files
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class SyncImportTask extends AbstractTask
{
    use StorageTrait;

    /**
     * Executes the task
     *
     * @return bool
     * @throws Exception
     * @throws \Doctrine\DBAL\DBALException
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    public function executeTask(): bool
    {
        return $this->importSqlFiles()
               && $this->clearCaches();
    }

    /**
     * Import the sql files
     *
     * @return bool
     *
     * @throws Exception
     * @throws \Doctrine\DBAL\DBALException
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    private function importSqlFiles(): bool
    {
        $sqlFiles= $this->findFilesToImport();

        if (empty($sqlFiles)) {
            $this->getLogger()->info('Nothing to import');
        }

        /** @var Connection $databaseConnection */
        $databaseConnection = $this->getDataBaseConnection();

        /** @var File $file */
        foreach ($sqlFiles as $name => $file) {
            $this->getLogger()->info("Start import of file $name");
            $tmpFile = '/tmp/' . $name;
            file_put_contents($tmpFile, gzdecode($file->getContents()));
            $command = sprintf(
                "mysql -h\"%s\" -u\"%s\" -p\"%s\" \"%s\" < %s 2>&1",
                $databaseConnection->getHost(),
                $databaseConnection->getUsername(),
                $databaseConnection->getPassword(),
                $databaseConnection->getDatabase(),
                $tmpFile
            );
            $output = [];
            $return = "";
            exec($command, $output, $return);
            unlink($tmpFile);

            if ($return > 0) {
                $this->getLogger()->error("Something went wrong on importing $name. Please check further logs and the file.");
                throw new Exception(implode(PHP_EOL, $output));
            }

            $this->getLogger()->info("Import $name is finished. Delete File.");
            $this->deleteFile($file);
            $this->getLogger()->info("Import was done successful.");
        }

        return true;

    }

    /**
     * Runs the clear cache command to flush the page caches
     *
     * @return bool
     *
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    private function clearCaches(): bool
    {
        $urlFiles = $this->findUrlFiles();

        $tce = GeneralUtility::makeInstance(DataHandler::class);
        $tce->start([], []);

        foreach ($urlFiles as $name => $file) {
            $this->getLogger()->info("start processing $name");
            $matches = [];
            preg_match('/([a-zA-Z]+\:[0-9]+)/', $file->getContents(), $matches);

            foreach ($matches as $match) {
                list($table, $uid) = explode(':', $match);
                $tce->clear_cacheCmd((int) $uid);
            }

            $this->deleteFile($file);
            $this->getLogger()->info("finish processing $name");
        }

        return true;
    }

    /**
     * Returns all files in a folder in the default storage
     *
     * @param string $folderPath Path to folder
     *
     * @return array
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    private function findFilesInFolder(string $folderPath): array
    {
        $storage = $this->getDefaultStorage();
        $folder  = $storage->getFolder($folderPath);
        return $storage->getFilesInFolder($folder);
    }

    /**
     * Deletes a file in the default storage
     *
     * @param File $file File object of file to delte
     *
     * @return void
     *
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException
     */
    private function deleteFile(File $file): void
    {
        $this->getDefaultStorage()->deleteFile($file);
    }

    /**
     * Returns a array with sql.qz files to import
     *
     * @return File[]
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    private function findFilesToImport(): array
    {
        $files   = $this->findFilesInFolder($this->{AdditionalFieldsProvider::FIELD_NAME_PATH});

        foreach ($files as $fileName => $file) {
            if ($file->getMimeType() !== 'application/gzip') {
                unset($files[$fileName]);
            }
        }

        return $files;
    }

    /**
     * Returns a array with files containing urls
     *
     * @return File[]
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    private function findUrlFiles(): array
    {
        $files   = $this->findFilesInFolder($this->{AdditionalFieldsProvider::FIELD_NAME_URLS});

        foreach ($files as $name => $file) {
            if (false === (bool) preg_match('/once\.txt$/', $name)) {
                unset($files[$name]);
            }
        }

        return $files;
    }

    /**
     * Returns the database connection
     *
     * @return Connection
     * @throws \Doctrine\DBAL\DBALException
     */
    private function getDataBaseConnection(): Connection
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        return $connectionPool->getConnectionByName('Default');
    }

    /**
     * Returns a logger instance
     *
     * @return \TYPO3\CMS\Core\Log\Logger
     */
    private function getLogger(): Logger
    {
        return GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }
}
