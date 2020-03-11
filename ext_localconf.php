<?php
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

defined('TYPO3_MODE') or die('Access denied.');

$TYPO3_CONF_VARS['FE']['eID_include'][$_EXTKEY]
    = 'EXT:' . $_EXTKEY . '/eid/nr_sync.php';

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addService(
    $_EXTKEY,
    'nrClearCache' /* sv type */,
    'tx_nrsync_clearcache' /* sv key */,
    array(

        'title' => 'NrSync Cache clear',
        'description' => 'Clears the cache of given tables',

        'subtype' => '',

        'available' => true,
        'priority' => 50,
        'quality' => 50,

        'os' => '',
        'exec' => '',

        'className' => Netresearch\NrSync\Service\clearCache::class,
    )
);

// Add caching framework garbage collection task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Netresearch\NrSync\Scheduler\SyncImportTask::class] = array(
    'extension' => $_EXTKEY,
    'title' => 'LLL:EXT:' . $_EXTKEY . '/Resources/Private/Language/locallang_scheduler.xlf:tx_nrsnyc_scheduler.name',
    'description' => 'LLL:EXT:' . $_EXTKEY . '/Resources/Private/Language/locallang_scheduler.xlf:tx_nrsync_scheduler.description',
    'additionalFields' => \Netresearch\NrSync\Scheduler\AdditionalFieldsProvider::class
);
