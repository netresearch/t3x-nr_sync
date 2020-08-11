<?php

namespace Netresearch\NrSync;

use Netresearch\NrSync\Traits\StorageTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class Table
 *
 * @package   Netresearch\NrSync
 * @author    Sebastian Mendel <sebastian.mendel@netresearch.de>
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class Table
{
    use StorageTrait;

    /** @var string $strTableName Name of table * */
    protected $strTableName = null;

    /** @var string $strDumpFile Name of dump file * */
    protected $strDumpFile = null;

    /** @var boolean $bForceFullSync force a complete sync * */
    protected $bForceFullSync = false;

    /**
     * add the --no-create-info option to the dump
     *
     * @var boolean
     */
    protected $bNoCreateInfo = true;

    /**
     * delete rows which are not used on live system
     * (delete,disabled,endtime), default is true
     *
     * @var boolean
     */
    protected $bDeleteObsoleteRows = true;

    /**
     * @var FileInterface
     */
    private $dumpFile;

    /**
     * @var Folder
     */
    private $tempFolder;

    /**
     * @var string
     */
    private $statTable = 'tx_nrsync_syncstat';

    /**
     * Constructor.
     *
     * @param string $strTableName Name of table.
     * @param string $strDumpFile  Name of target dump file.
     * @param string $statTable    Table holding the sync states
     * @param array  $arOptions    Additional options.
     *
     * @throws Exception
     */
    public function __construct(
        $strTableName, $strDumpFile, string $statTable = 'tx_nrsync_syncstat', ?array $arOptions = null
    )
    {
        $this->statTable = $statTable;
        if (empty($strTableName)) {
            throw new Exception('Table name cannot be empty.');
        }

        if (empty($strDumpFile)) {
            throw new Exception('Dump file name cannot be empty.');
        }

        $this->strTableName = (string) $strTableName;
        $this->strDumpFile = (string) $strDumpFile;

        if (is_array($arOptions)) {
            if (isset($arOptions['bForceFullSync'])) {
                $this->bForceFullSync = (bool)$arOptions['bForceFullSync'];
            }

            if (isset($arOptions['bDeleteObsoleteRows'])) {
                $this->bDeleteObsoleteRows
                    = (bool)$arOptions['bDeleteObsoleteRows'];
            }

            if (isset($arOptions['bNoCreateInfo'])) {
                $this->setNoCreateInfo($arOptions['bNoCreateInfo']);
            }
        }
    }

    /**
     * Returns the dumpfile
     *
     * @return FileInterface
     */
    private function getDumpFile()
    {
        if ($this->dumpFile instanceof FileInterface) {
            return $this->dumpFile;
        }

        $this->dumpFile = $this->getTempFolder()->getStorage()->getFile($this->strDumpFile);
        return $this->dumpFile;
    }

    /**
     * Append Content to Dumpfile
     *
     * @param string $content Content to add to teh dump file
     *
     * @return void
     */
    private function appendToDumpFile(string $content): void
    {
        $this->getDumpFile()->setContents(
            $this->getDumpFile()->getContents() .
            PHP_EOL . $content . PHP_EOL
        );
    }

    /**
     * Returns true if REPLACE INTO instead fo INSERT INTO should be used.
     *
     * Currently for only one database REPLACE INTO is needed therefore the tablename
     * is hardcoded.
     *
     * @return bool
     */
    protected function useReplace()
    {
        if ($this->strTableName === 'sys_file_metadata') {
            return true;
        }

        return false;
    }



    /**
     * Write tables data to dump file.
     *
     * Options:
     *
     * bForceFullSync: ignore last sync time and always do a full sync and
     *     no incremental sync
     *
     * @param string[] $arTables    Tables to dump.
     * @param string   $strDumpFile Target file for dump data.
     * @param array    $arOptions   Additional options.
     *
     * @return void
     */
    public static function writeDumps(
        array $arTables, $strDumpFile, array $arOptions = null
    )
    {
        /** @var Table[] $arInstances */
        $arInstances = array();
        foreach ($arTables as $strTable) {
            $table = new static($strTable, $strDumpFile, $arOptions['stateTable'], $arOptions);
            $arInstances[] = $table;
            $table->writeDump();
        }

        foreach ($arInstances as $table) {
            if ($table->bDeleteObsoleteRows) {
                $table->appendDeleteObsoleteRowsToFile();
            }
        }

    }



    /**
     * Write table data to dump file.
     *
     * @return void
     */
    public function writeDump()
    {
        if (false === $this->bForceFullSync && $this->hasTstampField()) {
            if ($this->hasUpdatedRows()) {
                $this->appendUpdateToFile();
                $this->setLastDumpTime();
            } else {
                static::notifySkippedEmptyTable();
            }
        } else {
            $this->appendDumpToFile();
            $this->setLastDumpTime(null, false);
        }
    }



    /**
     * Adds flash message about skipped tables in sync.
     *
     * @return void
     */
    protected function notifySkippedEmptyTable()
    {
        /** @var FlashMessage $message */
        $message = GeneralUtility::makeInstance(
            FlashMessage::class,
            'Table "' . $this->strTableName . '" skipped - no changes since last sync.',
            'Skipped table',
            FlashMessage::INFO
        );


        /* @var $messageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
        $messageService = GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Messaging\FlashMessageService::class
        );

        $messageService->getMessageQueueByIdentifier()->addMessage($message);
    }



    /**
     * Returns row count affected for sync/dump.
     *
     * @return integer|false
     * @throws Exception
     */
    protected function hasUpdatedRows()
    {
        /* @var $connectionPool \TYPO3\CMS\Core\Database\ConnectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($this->strTableName);

        $strWhere = $this->getDumpWhereCondition();

        if (empty($strWhere)) {
            throw new Exception(
                'Could not get WHERE condition for tstamp field for table "'
                . $this->strTableName . '".'
            );
        }

        $queryBuilder = $connection->createQueryBuilder();

        $tstampFieldName = $this->getTstampFieldName();

        return $queryBuilder->count($tstampFieldName)->from($this->strTableName)->where($strWhere)->execute()->fetchColumn(0);
    }



    /**
     * Appends table dump data to file.
     *
     * Uses TRUNCATE TABLE instead of DROP
     *
     * @return void
     * @throws Exception
     */
    protected function appendDumpToFile()
    {
        /* @var $connectionPool \TYPO3\CMS\Core\Database\ConnectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($this->strTableName);

        $this->appendToDumpFile("\n\n" . 'TRUNCATE TABLE ' . $this->strTableName . ";\n\n");

        $strExec = 'mysqldump -h' . $connection->getHost() . ' -u' . $connection->getUsername()
            . ' -p' . $connection->getPassword()
            // do not drop tables here, we truncated them already
            . ' --skip-add-drop-table';

        if ($this->bNoCreateInfo) {
            // do not add CREATE TABLE
            $strExec .= ' --no-create-info';
        }

        $bUseReplace = $this->useReplace();

        if ($bUseReplace) {
            $strExec .= ' --replace';
        }

        // use INSERT with column names
        // - prevent errors due to differences in tables on live system
        $strExec .= ' --complete-insert'
            // use more ROWS with every INSERT command
            // why was this set to FALSE?
            . ' --extended-insert'
            // Performance
            . ' --disable-keys'
            // export blobs as hex
            . ' --hex-blob'
            . ' ' . $connection->getDatabase() . ' ' . $this->strTableName ;

        $this->appendToDumpFile(shell_exec($strExec));
    }



    /**
     * Appends table dump data updated since last dump/sync to file.
     *
     * Does not add DROP TABLE.
     * Uses REPLACE instead of INSERT.
     *
     * @return void
     * @throws Exception
     */
    protected function appendUpdateToFile()
    {
        /* @var $connectionPool \TYPO3\CMS\Core\Database\ConnectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($this->strTableName);

        $strWhere = $this->getDumpWhereCondition();

        if (empty($strWhere)) {
            throw new Exception(
                'Could not get WHERE condition for tstamp field for table "'
                . $this->strTableName . '".'
            );
        }

        $strExec = 'mysqldump -h' . $connection->getHost() . ' -u' . $connection->getUsername()
            . ' -p' . $connection->getPassword()
            // do not drop tables here, we truncated them already
            . ' --skip-add-drop-table';

        if ($this->bNoCreateInfo) {
            // do not add CREATE TABLE
            $strExec .= ' --no-create-info';
        }
        // use INSERT with column names
        // - prevent errors due to differences in tables on live system
        $strExec .= ' --complete-insert'
            // use more ROWS with every INSERT command
            // why was this set to FALSE?
            . ' --extended-insert'
            // Performance
            . ' --disable-keys'
            //
            . ' --replace'
            // export blobs as hex
            . ' --hex-blob'
            . ' --where="' . $strWhere . '"'
            . ' ' . $connection->getDatabase() . ' ' . $this->strTableName;

        $this->appendToDumpFile(shell_exec($strExec));
    }



    /**
     * Appends the Delete statement for obsolete rows to the
     * current temporary file of the table
     *
     * @return void
     */
    public function appendDeleteObsoleteRowsToFile()
    {
        $strSqlObsoleteRows = $this->getSqlDroppingObsoleteRows();

        if (true === empty($strSqlObsoleteRows)) {
            return;
        }

        $this->appendToDumpFile("\n\n-- Delete obsolete Rows on live \n" . $strSqlObsoleteRows);
    }



    /**
     * Returns WHERE condition for table tstamp field or false.
     *
     * @return string|false
     */
    protected function getDumpWhereCondition()
    {
        // load TCA and check for tstamp field
        $strTableTstampField = $this->getTstampField();

        if (false === $strTableTstampField) {
            return false;
        }

        $nTime = $this->getLastDumpTime();

        if ($nTime) {
            return $strTableTstampField . ' > ' . $nTime;
        }

        return false;
    }

    /**
     * Returns table tstamp field - if defined, otherwise false.
     *
     * @return string|false
     */
    protected function getTstampField()
    {
        /** @var array $TCA * */
        global $TCA;

        if (!empty($TCA[$this->strTableName]['ctrl']['tstamp'])) {
            return $TCA[$this->strTableName]['ctrl']['tstamp'];
        }

        return false;
    }

    /**
     * Get the name of the tstamp field.
     *
     * @return string
     */
    protected function getTstampFieldName()
    {
        if (false !== $this->getTstampField()) {
            global $TCA;

            return $this->getTstampField();
        }

        return '';
    }



    /**
     * Returns whether a table has tstamp field or not.
     *
     * @return boolean
     */
    protected function hasTstampField()
    {
        return false !== $this->getTstampField();
    }



    /**
     * Returns time stamp for last sync/dump of this table
     *
     * @return integer
     * @throws Exception
     */
    protected function getLastDumpTime()
    {
        /* @var $connectionPool \TYPO3\CMS\Core\Database\ConnectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($this->statTable);

        $queryBuilder = $connection->createQueryBuilder();

        $arRow = $queryBuilder
            ->selectLiteral(
                'max(' . $queryBuilder->quoteIdentifier('incr') . ') AS ' . $queryBuilder->quoteIdentifier('incr'),
                'max(' . $queryBuilder->quoteIdentifier('full') . ') AS ' . $queryBuilder->quoteIdentifier('full')
            )
            ->from($this->statTable)
            ->where(
                $queryBuilder->expr()->in('tab', [$queryBuilder->quote('*'), $queryBuilder->quote($this->strTableName)])
            )
            ->execute()
            ->fetch(\PDO::FETCH_ASSOC);

        // DEFAULT: date of last full dump - facelift 2013
        $nTime = mktime(0, 0, 0, 2, 1, 2013);

        if (empty($arRow)) {
            return $nTime;
        }

        $nTimeMaxRow = max($arRow['incr'], $arRow['full']);

        if ($nTimeMaxRow) {
            $nTime = $nTimeMaxRow;
        }

        return $nTime;
    }



    /**
     * Sets time of last dump/sync for this table.
     *
     * @param integer $nTime Time of last table dump/sync.
     * @param boolean $bIncr Set time for last incremental or full dump/sync.
     *
     * @return void
     * @throws Exception
     */
    protected function setLastDumpTime($nTime = null, $bIncr = true)
    {
        /* @var $connectionPool \TYPO3\CMS\Core\Database\ConnectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($this->statTable);

        /* @var $BE_USER \TYPO3\CMS\Core\Authentication\BackendUserAuthentication */
        global $BE_USER;

        if (null === $nTime) {
            $nTime = $GLOBALS['EXEC_TIME'];
        }

        if (empty($nTime)) {
            $nTime = time();
        }

        if ($bIncr) {
            $strUpdateField = 'incr';
        } else {
            $strUpdateField = 'full';
        }

        $nUserId = intval($BE_USER->user['uid']);
        $nTime = intval($nTime);

        $connection->exec(
            'INSERT INTO ' . $this->statTable . ' '
            . '(tab, ' . $strUpdateField . ', cruser_id) VALUES '
            . '('
            . $connection->quote($this->strTableName)
            . ', ' . $connection->quote($nTime) . ', ' . $connection->quote($nUserId) . ') '
            . 'ON DUPLICATE KEY UPDATE'
            . ' cruser_id = ' . $connection->quote($nUserId) . ', '
            . $strUpdateField . ' = ' . $connection->quote($nTime)
        );
    }


    /**
     * Return a sql statement to drop rows from the table which are useless
     * in context of there control fields (hidden,deleted,endtime)
     *
     * @return string
     */
    public function getSqlDroppingObsoleteRows()
    {
        /* @var $connectionPool \TYPO3\CMS\Core\Database\ConnectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($this->statTable);

        $arControlFields = $this->getControlFieldsFromTcaByTableName();

        if (0 === count($arControlFields)) {
            return null;
        }

        // date to compare end timestamp with
        $nToday = strtotime(
            date('Y-m-d')
        );

        $strStatement = 'DELETE FROM '
            . $connection->quoteIdentifier($this->strTableName);
        $arWhereClauseParts = array();
        if (isset($arControlFields['delete'])) {
            $arWhereClauseParts[] = $arControlFields['delete'] . ' = 1';
        }

        if (isset($arControlFields['disabled'])) {
            $arWhereClauseParts[] = $arControlFields['disabled'] . ' = 1';
        }

        if (isset($arControlFields['endtime'])) {
            $arWhereClauseParts[] = '('
                . $arControlFields['endtime'] . ' < ' . $nToday
                . ' AND '
                . $arControlFields['endtime'] . ' <> 0'
                . ')';
        }

        if (0 === count($arWhereClauseParts)) {
            return null;
        }

        $strStatement .= ' WHERE ' . implode(' OR ', $arWhereClauseParts);
        $strStatement .= ';';

        return $strStatement;
    }



    /**
     * Returns an array of key-values where the key is the key-name of the
     * controlfield and the value is the name of the controlfield in the
     * current table object.
     *
     * @return array An array with controlfield key and the name of the keyfield
     *               in the current table
     */
    public function getControlFieldsFromTcaByTableName()
    {
        global $TCA;

        if (!isset($TCA[$this->strTableName])) {
            return array();
        }

        $arControl = $TCA[$this->strTableName]['ctrl'];
        $arEnableFields = $arControl['enablecolumns'];

        $arReturn = array();

        if (!empty($arControl['delete'])) {
            $arReturn['delete'] = $arControl['delete'];
        }

        if (!empty($arEnableFields['disabled'])) {
            $arReturn['disabled'] = $arEnableFields['disabled'];
        }

        if (!empty($arEnableFields['endtime'])) {
            $arReturn['endtime'] = $arEnableFields['endtime'];
        }

        return $arReturn;
    }



    /**
     * Setter for bNoCreateInfo
     *
     * @param boolean $bNoCreateInfo True if do not add CREATE TABLE
     *
     * @return void
     */
    public function setNoCreateInfo($bNoCreateInfo)
    {
        $this->bNoCreateInfo = $bNoCreateInfo;
    }
}
