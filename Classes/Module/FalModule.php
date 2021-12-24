<?php

namespace Netresearch\NrSync\Module;

use Netresearch\NrSync\Helper\Area;
use Netresearch\NrSync\Traits\TranslationTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Methods to work with synchronization areas
 *
 * @package    Netresearch/TYPO3/Sync
 * @author     Christian Weiske <christian.weiske@netresearch.de>
 * @copyright  2020 Netresearch DTT GmbH
 * @license    https://www.gnu.org/licenses/agpl AGPL v3
 * @link       http://www.netresearch.de
 */
class FalModule extends BaseModule
{
    use TranslationTrait;

    protected $tables = [
        'sys_file',
        'sys_category',
        'sys_filemounts',
        'sys_category_record_mm',
        'sys_file_reference',
        'sys_collection',
        'sys_file_metadata',
    ];

    protected $name = 'FAL';
    protected $type = 'sync_tables';
    protected $target = '';
    protected $dumpFileName = 'fal.sql';
    protected $accessLevel = 0;


    public function run(Area $area = null)
    {
        if (isset($_POST['data']['dam_cleanup'])) {
            $this->cleanUpDAM();
        }

        // DAM Test
        $this->testDAMForErrors();

        if ($this->hasError()) {
            $this->content =
                '<input type="Submit" name="data[dam_cleanup]" value="clean up FAL">';
        }
    }



    /**
     * Clean up DAM
     *
     * @return void
     */
    protected function cleanUpDAM()
    {
        echo $this->getLabel('label.clean_fal');
        flush();

        /* @var $connectionPool ConnectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable('sys_file_reference');

        $connection->delete('sys_file_reference', array('uid_foreign' => 0));

        echo $this->getLabel('label.done');
    }



    /**
     * Test DAM for errors.
     *
     * @return void
     */
    protected function testDAMForErrors()
    {
        /* @var $connectionPool ConnectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_file_reference');

        $count = $queryBuilder->count('*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', 0)
            )
            ->execute()
            ->fetchColumn(0);

        if ($count > 0) {
            $this->error .= $this->getLabel('error.fal_dirty');
        }
    }
}
