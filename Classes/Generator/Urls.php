<?php

namespace Netresearch\NrSync\Generator;

use Netresearch\NrSync\Helper\Area;
use Netresearch\NrSync\Controller\SyncModuleController;
use Netresearch\NrSync\Traits\StorageTrait;
use Netresearch\NrSync\Traits\TranslationTrait;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * Generate files with the list of URLs that have to be called
 * after importing the data.
 *
 * @package    Netresearch/TYPO3/Sync
 * @author     Christian Weiske <christian.weiske@netresearch.de>
 * @copyright  2020 Netresearch DTT GmbH
 * @license    https://www.gnu.org/licenses/agpl AGPL v3
 * @link       http://www.netresearch.de
 */
class Urls
{
    use StorageTrait;
    use TranslationTrait;

    /**
     * @var string Filename-format for once files
     */
    const FILE_FORMAT_ONCE = '%s-once.txt';

    /**
     * @var string Filename-format for per-machine files
     */
    const FILE_FORMAT_PERMACHINE = '%s-per-machine.txt';

    /**
     * Called after the sync button has been pressed.
     * We generate the URL files here.
     *
     * @param array  $arParams Information about what to sync.
     * @param SyncModuleController $sync     Main sync module object
     *
     * @return void
     */
    public function postProcessSync(array $arParams, SyncModuleController $sync)
    {
        if ($arParams['bProcess'] == false || $arParams['bSyncResult'] == false) {
            return;
        }

        if (count($arParams['arUrlsOnce']) == 0
            && count($arParams['arUrlsPerMachine']) == 0
        ) {
            return;
        }

        $arMatchingAreas = Area::getMatchingAreas(
            $sync->MOD_SETTINGS['target'], $arParams['arAreas'], $arParams['strTableType']
        );

        $arFolders = $this->getFolders($arMatchingAreas, $sync);

        $nCount = 0;

        if (isset($arParams['arUrlsOnce'])) {
            $nCount = $this->generateUrlFile(
                $arParams['arUrlsOnce'], $arFolders, self::FILE_FORMAT_ONCE
            );
        }
        if (isset($arParams['arUrlsPerMachine'])) {
            $nCount += $this->generateUrlFile(
                $arParams['arUrlsPerMachine'], $arFolders, self::FILE_FORMAT_PERMACHINE
            );
        }

        $sync->addSuccess(
            $this->getLabel('message.hook_files', ['{number}' => $nCount])
        );
    }

    /**
     * Generates the url files for a given format
     *
     * @param array  $urls    Array with urls to write onto file
     * @param array  $folders Folders in which the files should be stored
     * @param string $format  Format of filename
     *
     * @return int
     */
    private function generateUrlFile(array $urls, array $folders, $format)
    {
        list($strContent, $strPath) = $this->prepareFile($urls, $format);
        return $this->saveFile($strContent, $strPath, $folders);
    }

    /**
     * Prepares file content and file name for an url list file
     *
     * @param array  $arUrls              URLs
     * @param string $strFileNameTemplate Template for file name.
     *                                    Date will be put into it
     *
     * @return array First value is the file content, second the file name
     */
    protected function prepareFile(array $arUrls, $strFileNameTemplate)
    {
        if (count($arUrls) == 0) {
            return array(null, null);
        }

        return array(
            implode("\n", $arUrls) . "\n",
            sprintf($strFileNameTemplate, date('YmdHis')),
        );
    }

    /**
     * Saves the given file into different folders
     *
     * @param string $strContent  File content to save
     * @param string $strFileName File name to use
     * @param array  $arFolders   Folders to save file into
     *
     * @return integer Number of created files
     */
    protected function saveFile($strContent, $strFileName, array $arFolders)
    {
        if ($strContent === null || $strFileName == '' || !count($arFolders)) {
            return 0;
        }

        foreach ($arFolders as $strFolder) {
            $folder = $this->getSyncFolder()->getSubfolder($strFolder);
            $file = $this->getDefaultStorage()->createFile($strFileName, $folder);
            $file->setContents($strContent);
        }

        return count($arFolders);
    }



    /**
     * Returns full folder paths. Creates folders if necessary.
     * The URL files have to be put in each of the folders.
     *
     * @param Area[]               $arAreas Areas to sync to
     * @param SyncModuleController $sync    Main sync module object
     *
     * @return array Array of full paths with trailing slashes
     */
    protected function getFolders(array $arAreas, SyncModuleController $sync)
    {
        $arPaths = [];
        /** @var Area $area */
        foreach ($arAreas as $area) {
            foreach ($area->getSystems() as $system) {
                if ($sync->isSystemLocked($system['directory'])) {
                    $sync->addWarning(
                        $this->getLabel('warning.urls_system_locked', ['{target}' => $system['directory']])
                    );
                    continue;
                }
                $arPaths[] = $system['url-path'];
            }
        }
        $arPaths = array_unique($arPaths);

        return $arPaths;
    }
}
