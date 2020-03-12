<?php

namespace Netresearch\NrSync\Module;

use Netresearch\NrSync\Helper\Area;
use Netresearch\NrSync\Traits\StorageTrait;
use Netresearch\NrSync\Traits\TranslationTrait;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * Methods to work with synchronization areas
 *
 * @package    Netresearch/TYPO3/Sync
 * @author     Christian Weiske <christian.weiske@netresearch.de>
 * @copyright  2020 Netresearch DTT GmbH
 * @license    https://www.gnu.org/licenses/agpl AGPL v3
 * @link       http://www.netresearch.de
 */
class StateModule extends BaseModule
{
    use StorageTrait;
    use TranslationTrait;

    protected $name = 'Table state';
    protected $type = 'sync_tables';
    protected $target = 'local';
    protected $dumpFileName = '';
    protected $accessLevel = 100;

    public function run(Area $area = null)
    {
        parent::run();

        if (isset($_POST['data']['submit'])) {
            if ($this->createNewDefinitions()) {
                $this->addMessage(
                    $this->getLabel('message.table_state_success'), FlashMessage::OK
                );
            }
        } else {
            $this->testAllTablesForDifferences();
        }

        return true;
    }



    /**
     * Tests if the tables of db differs from saved file.
     *
     * @return void
     */
    protected function testAllTablesForDifferences()
    {
        $arTableNames = $this->connectionPool->getConnectionForTable('pages')
            ->getSchemaManager()
            ->listTableNames();

        $this->testTablesForDifferences($arTableNames);
    }



    /**
     * Writes the table definition of database into an file.
     *
     * @return boolean True if file was written else false.
     */
    protected function createNewDefinitions()
    {
        $arTableNames = $this->connectionPool->getConnectionForTable('pages')
            ->getSchemaManager()
            ->listTableNames();

        $arTables = [];
        foreach ($arTableNames as $strTableName) {
            $arColumns = $this->connectionPool->getConnectionForTable($strTableName)
                ->getSchemaManager()
                ->listTableColumns($strTableName);

            $arColumnNames = [];
            foreach ($arColumns as $column) {
                $arColumnNames[] = $column->getName();
            }
            $arTables[$strTableName] = $arColumnNames;
        }

        $strTables = serialize($arTables);

        if (false === $this->getDefaultStorage()->hasFile($this->getStateFile())) {
            $this->getDefaultStorage()->createFile(
                $this->strTableSerializedFile,
                $this->getDefaultStorage()->getFolder($this->baseFolderIdentifier)
            );
        }


        $tableStateFile = $this->getDefaultStorage()->getFile($this->getStateFile());


        if (false === $this->getDefaultStorage()->checkFileActionPermission('write', $tableStateFile)) {
            $this->addMessage(
                $this->getLabel('error.could_not_write_tablestate'). ' ' . $tableStateFile->getName(),
                FlashMessage::ERROR
            );
            return false;
        }

        $tableStateFile->setContents($strTables);

        return true;
    }
}
