<?php
/**
 * Class SyncLock
 *
 * @package   Netresearch\NrSync
 * @author    Sebastian Mendel <sebastian.mendel@netresearch.de>
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */

defined('TYPO3_MODE') or die('Access denied.');

call_user_func(function () {

    if (TYPO3_MODE === 'BE') {
        // Add module
        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
            'web',
            'txnrsyncM1',
            '',
            '',
            [
                'routeTarget' => \Netresearch\NrSync\Controller\SyncModuleController::class . '::mainAction',
                'access' => 'user,group',
                'name' => 'web_txnrsyncM1',
                'icon' => 'EXT:nr_sync/Resources/Public/Icons/Extension.svg',
                'labels' => 'LLL:EXT:nr_sync/Resources/Private/Language/locallang.xlf',
            ]
        );

        // Add context sensitive help (csh) to the backend module
        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addLLrefForTCAdescr(
            '_MOD_web_txnrsyncM1',
            'EXT:nr_sync/Resources/Private/Language/locallang.xlf'
        );
    }

});
