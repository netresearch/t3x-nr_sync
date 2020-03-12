<?php

namespace Netresearch\NrSync;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Sync list manager.
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Thomas Schöne <thomas.schoene@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class SyncListManager implements SingletonInterface
{

    /**
     * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     * @inject
     */
    protected $objectManager;

    /**
     * @var SyncList[]
     */
    protected $syncLists = [];


    /**
     * @param $syncListId
     * @return SyncList
     */
    public function getSyncList($syncListId)
    {
        if (null === $this->syncLists[$syncListId]) {
            $this->syncLists[$syncListId] = $this->objectManager->get(SyncList::class);

            $this->syncLists[$syncListId]->load($syncListId);
        }

        return $this->syncLists[$syncListId];
    }

}
