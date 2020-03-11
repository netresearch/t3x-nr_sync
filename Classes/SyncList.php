<?php

namespace Netresearch\NrSync;

use Netresearch\NrSync\Helper\Area;
use Netresearch\NrSync\Traits\TranslationTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Sync list.
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Thomas Schöne <thomas.schoene@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class SyncList
{
    use TranslationTrait;


    /**
     * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     * @inject
     */
    protected $objectManager;

    /**
     * @var \TYPO3\CMS\Core\Messaging\FlashMessageService
     * @inject
     */
    protected $messageService;

    protected $arSyncList = [];

    protected $id = '';
    private $content = '';


    /**
     * @param string $syncListId
     */
    public function load($syncListId)
    {
        $this->arSyncList = (array) $this->getBackendUser()->getSessionData('nr_sync_synclist' . $syncListId);
        $this->id = (string) $syncListId;
    }



    /**
     * Saves the sync list to user session.
     */
    public function saveSyncList()
    {
        $this->getBackendUser()->setAndSaveSessionData(
            'nr_sync_synclist' . $this->id, $this->arSyncList
        );
    }



    /**
     * Adds given data to sync list, if pageId doesn't already exists.
     *
     * @param array $arData Data to add to sync list.
     *
     * @return void
     */
    public function addToSyncList(array $arData)
    {
        $arData['removeable'] = true;

        // TODO: Nur Prüfen ob gleiche PageID schon drin liegt
        if (!$this->isInTree($this->arSyncList[$arData['areaID']], $arData['pageID'])) {
            $this->arSyncList[$arData['areaID']][] = $arData;
        } else {
            $this->addMessage(
                $this->getLabel('error.page_is_marked'),
                FlashMessage::ERROR
            );
        }
    }



    /**
     * Adds error message to message queue.
     *
     * message types are defined as class constants self::STYLE_*
     *
     * @param string $strMessage message
     * @param integer $type message type
     *
     * @return void
     */
    public function addMessage($strMessage, $type = FlashMessage::INFO)
    {
        /* @var $message FlashMessage */
        $message = $this->objectManager->get(
            FlashMessage::class, $strMessage, '', $type, true
        );

        $this->messageService->getMessageQueueByIdentifier()->addMessage($message);
    }



    /**
     * Adds given data to sync list, if pageId does not already exists.
     *
     * @param array $arData Data to add to sync list.
     *
     * @return void
     */
    public function deleteFromSyncList(array $arData)
    {
        $arDeleteArea = array_keys($arData['delete']);
        $arDeletePageID = array_keys(
            $arData['delete'][$arDeleteArea[0]]
        );
        foreach ($this->arSyncList[$arDeleteArea[0]] as $key => $value) {
            if ($value['removeable']
                && $value['pageID'] == $arDeletePageID[0]
            ) {
                unset($this->arSyncList[$arDeleteArea[0]][$key]);
                if (0 === count($this->arSyncList[$arDeleteArea[0]])) {
                    unset($this->arSyncList[$arDeleteArea[0]]);
                }
                break;
            }
        }
    }



    /**
     * Schaut nach ob eine $pid bereits in der Synliste liegt
     *
     * @param array   $arSynclist List of page IDs
     * @param integer $pid        Page ID
     *
     * @return boolean
     */
    protected function isInTree(array $arSynclist = null, $pid)
    {
        if (is_array($arSynclist)) {
            foreach ($arSynclist as $value) {
                if ($value['pageID'] == $pid) {
                    return true;
                }
            }
        }

        return false;
    }



    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        /* @var $BE_USER BackendUserAuthentication */
        global $BE_USER;

        return $BE_USER;
    }


    /**
     * @return bool
     */
    public function isEmpty()
    {
        return (bool) (count($this->arSyncList) < 1);
    }



    public function getAsArray()
    {
        return $this->arSyncList;
    }



    /**
     * Gibt alle PageIDs zurück die durch eine Syncliste definiert wurden.
     * Und Editiert werden dürfen
     *
     * @param integer $areaID Area
     *
     * @return array
     */
    public function getAllPageIDs($areaID)
    {
        $arSyncList = $this->arSyncList[$areaID];

        $arPageIDs = array();
        foreach ($arSyncList as $arSyncPage) {
            // Prüfen ob User Seite Bearbeiten darf
            $arPage = BackendUtility::getRecord('pages', $arSyncPage['pageID']);
            if ($this->getBackendUser()->doesUserHaveAccess($arPage, 2)) {
                array_push($arPageIDs, $arSyncPage['pageID']);
            }

            // Wenn der ganze Baum syncronisiert werden soll
            // getSubpagesAndCount liefert nur Pages zurück die Editiert werden
            // dürfen
            // @TODO
            if ($arSyncPage['type'] == 'tree') {
                /* @var $area Area */
                $area = GeneralUtility::makeInstance(Area::class, $arSyncPage['areaID']);
                $arCount = $this->getSubpagesAndCount(
                    $arSyncPage['pageID'], $dummy, 0, $arSyncPage['levelmax'],
                    $area->getNotDocType(),
                    $area->getDocType()
                );
                $a = $this->getPageIDsFromTree($arCount);
                $arPageIDs = array_merge($arPageIDs, $a);

            }
        }
        $arPageIDs = array_unique($arPageIDs);
        return $arPageIDs;
    }



    public function emptyArea($areaID)
    {
        unset($this->arSyncList[$areaID]);
    }





    /**
     * Adds the elements from the sync list as section into the content of the
     * template of backend module.
     *
     * @return string
     */
    public function showSyncList()
    {
        $this->content .= '<h2>' . $this->getLabel('headline.sync_list') . '</h2>';

        foreach ($this->arSyncList as $nAreaId => $arList) {
            /* @var $area Area */
            $area = GeneralUtility::makeInstance(Area::class, $nAreaId);
            $this->content .= '<h3>' . $area->getName() . ' ' . $area->getDescription() . '</h3>';
            $this->showSyncListArea($nAreaId, $arList);
        }

        return $this->content;
    }



    /**
     * Adds the elements of an Netresearch\NrSync\Helper\Area from the synclist as section into the content
     * of the template of backend module.
     *
     * @param integer $nAreaId Id of the area this list is from.
     * @param array   $arList  Sync list of an Netresearch\NrSync\Helper\Area.
     *
     * @return void
     */
    protected function showSyncListArea($nAreaId, array $arList)
    {
        $this->content .= '<div class="table-fit">';
        $this->content .= '<table class="table table-striped table-hover" id="ts-overview">';
        $this->content .= '<thead>';
        $this->content .= '<tr><th>' . $this->getLabel('column.item'). '</th><th>' . $this->getLabel('column.action') . '</th></tr>';
        $this->content .= '</thead>';
        $this->content .= '<tbody>';

        foreach ($arList as $syncItem) {
            if ($syncItem['removeable']) {
                $strLinkLoeschen = $this->getRemoveLink(
                    $nAreaId, $syncItem['pageID']
                );
            } else {
                $strLinkLoeschen = '';
            }

            $this->content .= '<tr class="bgColor4">';
            $this->content .= '<td>';
            $this->content .= '"' . htmlspecialchars(
                    BackendUtility::getRecordTitle(
                        'pages',
                        BackendUtility::getRecord('pages', $syncItem['pageID'])
                    ))
                . '" ' . $this->getLabel('label.page', ['{id}' => intval($syncItem['pageID'])]);
            if ($syncItem['type'] == 'tree' && $syncItem['count'] > 0) {
                $this->content .= " " . $this->getLabel(
                    'label.list_with_subs',
                        [
                            '{pages}' => $syncItem['count'],
                            '{deleted}' => $syncItem['deleted'],
                            '{noaccess}' => $syncItem['noaccess'],
                        ]
                    );
            }
            $this->content .= '</td>';

            $this->content .= '<td>';
            $this->content .= $strLinkLoeschen;
            $this->content .= '</td>';

            $this->content .= '</tr>';
        }

        $this->content .= '</tbody>';
        $this->content .= '</table>';
        $this->content .= '</div>';
    }



    /**
     * Generates HTML of removal button.
     *
     * @param integer $nAreaId    Id of the area to remove from list.
     * @param integer $nElementId Id of the element to remove from list.
     *
     * @return string HTML button.
     */
    protected function getRemoveLink($nAreaId, $nElementId)
    {
        return '<button class="btn btn-default" type="submit" name="data[delete][' . $nAreaId . ']'
            . '[' . $nElementId . ']"'
            . ' value="Remove from sync list">'
            . $this->getIconFactory()->getIcon('actions-selection-delete', Icon::SIZE_SMALL)->render()
            . '</button>';
    }



    /**
     * @return ObjectManager
     */
    protected function getObjectManager()
    {
        /* @var $objectManager ObjectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);

        return $objectManager;
    }



    /**
     * @return IconFactory
     */
    protected function getIconFactory()
    {
        /* @var $iconFactory IconFactory */
        $iconFactory =  $this->getObjectManager()->get(IconFactory::class);

        return $iconFactory;
    }



    /**
     * Gibt die Seite, deren Unterseiten und ihre Zählung zu einer PageID zurück,
     * wenn sie vom User editierbar ist.
     *
     * @param integer $pid               The page id to count on.
     * @param array   &$arCount          Information about the count data.
     * @param integer $nLevel            Depth on which we are.
     * @param integer $nLevelMax         Maximum depth to search for.
     * @param array   $arDocTypesExclude TYPO3 doc types to exclude.
     * @param array   $arDocTypesOnly    TYPO3 doc types to count only.
     * @param array   $arTables          Tables this task manages.
     *
     * @return array
     */
    protected function getSubpagesAndCount(
        $pid, &$arCount, $nLevel = 0, $nLevelMax = 1, array $arDocTypesExclude = null,
        array $arDocTypesOnly = null, array $arTables = null
    ) {
        $arCountDefault = array(
            'count'      => 0,
            'deleted'    => 0,
            'noaccess'   => 0,
            'falses'     => 0,
            'other_area' => 0,
        );

        if (!is_array($arCount)) {
            $arCount = $arCountDefault;
        }

        $return = array();

        if ($pid < 0 || ($nLevel >= $nLevelMax && $nLevelMax !== 0)) {
            return $return;
        }

        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');

        $result = $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', intval($pid))
            )
            ->execute();

        while ($arPage = $result->fetch()) {
            if (is_array($arDocTypesExclude) && in_array($arPage['doktype'], $arDocTypesExclude)) {
                continue;
            }

            if (isset($this->areas[$arPage['uid']])) {
                $arCount['other_area']++;
                continue;
            }

            if (count($arDocTypesOnly)
                && !in_array($arPage['doktype'], $arDocTypesOnly)
            ) {
                $arCount['falses']++;
                continue;
            }

            $arSub = $this->getSubpagesAndCount(
                $arPage['uid'], $arCount, $nLevel + 1, $nLevelMax,
                $arDocTypesExclude, $arDocTypesOnly, $arTables
            );

            if ($this->getBackendUser()->doesUserHaveAccess($arPage, 2)) {
                $return[] = array(
                    'page' => $arPage,
                    'sub'  => $arSub,
                );
            } else {
                $return[] = array(
                    'sub' => $arSub,
                );
                $arCount['noaccess']++;
            }

            // Die Zaehlung fuer die eigene Seite
            if ($this->pageContainsData($arPage['uid'], $arTables)) {
                $arCount['count']++;
                if ($arPage['deleted']) {
                    $arCount['deleted']++;
                }
            }
        }

        return $return;
    }



    /**
     * Tests if given tables holds data on given page id.
     * Returns true if "pages" is one of the tables to look for without checking
     * if page exists.
     *
     * @param integer $nId      The page id to look for.
     * @param array   $arTables Tables this task manages.
     *
     * @return boolean True if data exists otherwise false.
     */
    protected function pageContainsData($nId, array $arTables = null)
    {
        global $TCA;

        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);


        if (null === $arTables) {
            return false;
        } elseif (false !== array_search('pages', $arTables)) {
            return true;
        } else {
            foreach ($arTables as $strTableName) {
                if (isset($TCA[$strTableName])) {
                    $queryBuilder = $connectionPool->getQueryBuilderForTable($strTableName);

                    $nCount = $queryBuilder->count('pid')
                        ->from($strTableName)
                        ->where($queryBuilder->expr()->eq('pid', intval($nId)))
                        ->execute()
                        ->fetchColumn(0);

                    if ($nCount > 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }



    /**
     * Gibt alle ID's aus einem Pagetree zurück.
     *
     * @param array $arTree The pagetree to get IDs from.
     *
     * @return array
     */
    protected function getPageIDsFromTree(array $arTree)
    {
        $arPageIDs = array();
        foreach ($arTree as $value) {
            // Schauen ob es eine Seite auf dem Ast gibt (kann wegen
            // editierrechten fehlen)
            if (isset($value['page'])) {
                array_push($arPageIDs, $value['page']['uid']);
            }

            // Schauen ob es unter liegende Seiten gibt
            if (is_array($value['sub'])) {
                $arPageIDs = array_merge(
                    $arPageIDs, $this->getPageIDsFromTree($value['sub'])
                );
            }
        }
        return $arPageIDs;
    }
}
