<?php

namespace Netresearch\NrSync\Module;

use Netresearch\NrSync\Helper\Area;
use Netresearch\NrSync\Traits\TranslationTrait;

/**
 * Methods to work with synchronization areas
 *
 * @package    Netresearch/TYPO3/Sync
 * @author     Christian Weiske <christian.weiske@netresearch.de>
 * @copyright  2020 Netresearch DTT GmbH
 * @license    https://www.gnu.org/licenses/agpl AGPL v3
 * @link       http://www.netresearch.de
 */
class AssetModule extends BaseModule
{
    use TranslationTrait;

    protected $name = 'Assets';
    protected $type = '';
    protected $target = 'sync server';
    protected $dumpFileName = '';
    protected $accessLevel = 100;

    public function run(Area $area = null)
    {
        parent::run();

        if (isset($_POST['data']['submit'])) {
            if ($area->notifyMaster()) {
                $this->addMessage(
                    $this->getLabel('success.sync_assests_init')
                );
            }
        }

        return true;
    }
}
