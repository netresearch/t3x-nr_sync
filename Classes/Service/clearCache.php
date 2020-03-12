<?php

namespace Netresearch\NrSync\Service;

use TYPO3;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service clear cache for Netresearch Synchronisation
 *
 * @package    Netresearch/TYPO3/Sync
 * @author     Alexander Opitz <alexander.opitz@netresearch.de>
 * @license    https://www.gnu.org/licenses/agpl AGPL v3
 * @link       http://www.netresearch.de
 */
class clearCache extends \TYPO3\CMS\Core\Service\AbstractService
{
    public $prefixId = 'Netresearch\NrSync\Service\clearCache';// Same as class name
    public $scriptRelPath = 'sv/clearCache.php';
    public $extKey = 'nr_sync';    // The extension key.

    /**
     * Calls the clear cache function of t3lib_TCEmain for every array entry
     *
     * @param array $arData Array for elements to clear cache from as "table:uid"
     *
     * @return void
     */
    public function clearCaches(array $arData)
    {
        /* @var $tce TYPO3\CMS\Core\DataHandling\DataHandler */
        $tce = GeneralUtility::makeInstance('TYPO3\CMS\Core\DataHandling\DataHandler');
        $tce->start(array(), array());

        foreach ($arData as $strData) {
            list($strTable, $uid) = explode(':', $strData);

            GeneralUtility::devLog(
                'Clear cache table: ' . $strTable . '; uid: ' . $uid, 'nr_sync',
                GeneralUtility::SYSLOG_SEVERITY_INFO
            );

            $tce->clear_cacheCmd((int)$uid);
        }
    }
}
