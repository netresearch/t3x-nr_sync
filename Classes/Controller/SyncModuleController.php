<?php

namespace Netresearch\NrSync\Controller;

use Netresearch\NrSync\Exception;
use Netresearch\NrSync\ExtensionConfiguration;
use Netresearch\NrSync\Generator\Urls;
use Netresearch\NrSync\Helper\Area;
use Netresearch\NrSync\Module\AssetModule;
use Netresearch\NrSync\Module\BaseModule;
use Netresearch\NrSync\Module\FalModule;
use Netresearch\NrSync\Module\NewsModule;
use Netresearch\NrSync\Module\StateModule;
use Netresearch\NrSync\SyncList;
use Netresearch\NrSync\SyncListManager;
use Netresearch\NrSync\SyncLock;
use Netresearch\NrSync\SyncStats;
use Netresearch\NrSync\Table;
use Netresearch\NrSync\Traits\TranslationTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Messaging\Renderer\BootstrapRenderer;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Fluid\View\StandaloneView;
use Netresearch\NrSync\Traits\StorageTrait;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Module 'Netresearch Sync' for the 'nr_sync' extension.
 *
 * Diese Modul sorgt für die Synchronisation zwischen Staging und
 * Live. Es wird je nach Benutzergruppe eine Auswahl zu synchronisierender
 * Tabellen erscheinen. Diese werden gedumpt, gezippt und in ein bestimmtes
 * Verzeichnis geschrieben. Danach wird per FTP der Hauptserver
 * benachrichtigt. Dieser holt sich dann per RSync die Daten ab und spielt
 * sie in die DB ein. Der Cache wird ebenfalls gelöscht. Aktuell werden
 * immer auch die Files (fileadmin/ & statisch/) mitsynchronisiert.
 *
 * @todo      doc
 * @todo      Logfile in DB wo Syncs hineingeschrieben werden
 * @package   Netresearch/TYPO3/Sync
 * @author    Michael Ablass <ma@netresearch.de>
 * @author    Alexander Opitz <alexander.opitz@netresearch.de>
 * @author    Tobias Hein <tobias.hein@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class SyncModuleController extends \TYPO3\CMS\Backend\Module\BaseScriptClass
{
    use StorageTrait;
    use TranslationTrait;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var ExtensionConfiguration
     */
    private $extensionConfiguration;

    const FUNC_SINGLE_PAGE = 46;

    /**
     * key to use for storing insert statements
     * in $arGlobalSqlLinesStoarage
     */
    const STATEMENT_TYPE_INSERT = 'insert';

    /**
     * key to use for storing delete statements
     * in $arGlobalSqlLinesStoarage
     */
    const STATEMENT_TYPE_DELETE = 'delete';

    var $nDumpTableRecursion = 0;

    /**
     * @var array backend page information
     */
    var $pageinfo;

    /**
     * @var string clearCache url format
     */
    public $strClearCacheUrl = '?eID=nr_sync&task=clearCache&data=%s&v8=true';

    /**
     * @var string path to temp folder
     */
    protected $strTempFolder = 'tmp/';

    /**
     * @var int
     */
    protected $nRecursion = 1;

    /**
     * @var array
     */
    var $arObsoleteRows = array();

    /**
     * @var array
     */
    var $arReferenceTables = array();

    /**
     * Multidimensional array to save the lines put to the
     * current sync file for the current sync process
     * Structure
     * $arGlobalSqlLineStorage[<statementtype>][<tablename>][<identifier>] = <statement>;
     *
     * statementtypes: delete, insert
     * tablename:      name of the table the records belong to
     * identifier:     unique identifier like uid or a uique string
     *
     * @var array
     */
    protected $arGlobalSqlLineStorage = array();

    /**
     * ModuleTemplate Container
     *
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * The name of the module
     *
     * @var string
     */
    protected $moduleName = 'web_txnrsyncM1';

    /**
     * @var IconFactory
     */
    protected $iconFactory;

    /**
     * @var StandaloneView
     */
    protected $view;

    /**
     * @var Area
     */
    protected $area = null;

    protected $arFunctions = [
        0 => BaseModule::class,
        31 => [
            'name'         => 'Domain Records',
            'tables'       => [
                'sys_domain',
            ],
            'dumpFileName' => 'sys_domain.sql',
        ],
        46 => [
            'name'         => 'Single pages with content',
            'tables'       => [
                'pages',
                'pages_language_overlay',
                'tt_content',
                'sys_template',
                'sys_file_reference',
            ],
            'dumpFileName' => 'partly-pages.sql',
        ],
        8 => FalModule::class,
        9 => [
            'name'         => 'FE groups',
            'type'         => 'sync_fe_groups',
            'tables'       => [
                'fe_groups',
            ],
            'dumpFileName' => 'fe_groups.sql',
        ],
        35 => AssetModule::class,
        10 => [
            'name'         => 'BE users and groups',
            'type'         => 'sync_be_groups',
            'tables'       => [
                'be_users',
                'be_groups',
            ],
            'dumpFileName' => 'be_users_groups.sql',
            'accessLevel'  => 100,
        ],
        17 => StateModule::class,
        40 => [
            'name'         => 'Scheduler',
            'tables'       => [
                'tx_scheduler_task',
            ],
            'dumpFileName' => 'scheduler.sql',
            'accessLevel'  => 100,
        ],
        47 => [
            'name'         => 'TYPO3 Redirects',
            'tables'       => [
                'sys_redirect',
            ],
            'dumpFileName' => 'sys_redirect.sql',
            'accessLevel'  => 50,
        ],
    ];

    /**
     * @var BaseModule
     */
    protected $function;

    /**
     * @var SyncListManager
     */
    private $syncListManager;

    /**
     * @var Urls;
     */
    private $urlGenerator;

    /**
     * @var string
     */
    private $statTable = 'tx_nrsync_syncstat';

    /**
     * SyncModuleController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->initializeDependencies();

        if($this->extensionConfiguration->isNewerTypo3()) {
            $this->arFunctions[46] = [
                'name'         => 'Single pages with content',
                'tables'       => [
                    'pages',
                    'tt_content',
                    'sys_template',
                    'sys_file_reference',
                ],
                'dumpFileName' => 'partly-pages.sql',
            ];
        }

        if(ExtensionManagementUtility::isLoaded('nr_textdb')) {
            $this->arFunctions[20] = [
                'name'         => 'TextDB',
                'tables'       => [
                    'tx_nrtextdb_domain_model_environment',
                    'tx_nrtextdb_domain_model_component',
                    'tx_nrtextdb_domain_model_type',
                    'tx_nrtextdb_domain_model_translation',
                ],
                'dumpFileName' => 'text-db.sql',
                'accessLevel'  => 100,
            ];
        }

        if (ExtensionManagementUtility::isLoaded('news')) {
            $this->arFunctions[48] = NewsModule::class;
        }

        $this->getLanguageService()->includeLLFile('EXT:nr_sync/Resources/Private/Language/locallang.xlf');
        $this->MCONF = [
            'name' => $this->moduleName,
        ];

        $pageRenderer = $this->getObjectManager()->get(PageRenderer::class);
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/Modal');
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/SplitButtons');
    }

    /**
     * Initalizes the depnedencies
     *
     * @return void
     */
    private function initializeDependencies(): void
    {
        $this->objectManager          = GeneralUtility::makeInstance(ObjectManager::class);
        $this->extensionConfiguration = $this->objectManager->get(ExtensionConfiguration::class);
        $this->moduleTemplate         = $this->getObjectManager()->get(ModuleTemplate::class);
        $this->urlGenerator           = $this->getObjectManager()->get(Urls::class);
    }

    /**
     * Init sync module.
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        $this->initFolders();
    }

    /**
     * Initalizes all needed directories
     *
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    private function initFolders(): void
    {
        foreach ($this->getArea()->getSystems() as $system) {
            if (false === $this->getSyncFolder()->hasFolder($system['directory'] . '/')) {
                $this->getSyncFolder()->createFolder($system['directory'] . '/');
            }
            if (false === $this->getSyncFolder()->hasFolder($system['url-path'] . '/')) {
                $this->getSyncFolder()->createFolder($system['url-path'] . '/');
            }
        }
    }

    /**
     * Returns a TYPO3 QueryBuilder instance for a given table, without any restrcition.
     *
     * @param $tableName
     *
     * @return \TYPO3\CMS\Core\Database\Query\QueryBuilder
     */
    private function getQueryBuilderForTable($tableName)
    {
        /**
         * @var ConnectionPool $connectionPool
         */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        $queryBuilder = $connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    /**
     * Adds items to the ->MOD_MENU array. Used for the function menu selector.
     *
     * @return void
     */
    function menuConfig()
    {
        $this->MOD_MENU = array('function' => array(), 'target' => array());

        $nAccessLevel = 50;
        if ($this->getBackendUser()->isAdmin()) {
            $nAccessLevel = 100;
        }

        foreach ($this->arFunctions as $functionKey => $function) {
            $function = $this->getFunctionObject($functionKey);
            if ($nAccessLevel >= $function->getAccessLevel()) {
                $this->MOD_MENU['function'][$functionKey] = $function->getName();
            }
        }

        natcasesort($this->MOD_MENU['function']);
        $this->MOD_MENU['function'] = array('0' => 'Please select') + $this->MOD_MENU['function'];
        $this->MOD_MENU['target'] = array('Integration' => 'Integration', 'Production' => 'Production'); # 'all' => 'All' - all is support but we disabled it for now.
        parent::menuConfig();
    }



    /**
     * @param int $functionKey
     * @return BaseModule
     */
    protected function getFunctionObject($functionKey)
    {
        /* @var $function BaseModule */
        if (is_string($this->arFunctions[(int) $functionKey])) {
            $function = $this->getObjectManager()->get($this->arFunctions[(int) $functionKey]);
        } else {
            $function = $this->getObjectManager()->get(
                BaseModule::class,
                $this->arFunctions[(int) $functionKey]
            );
        }

        return $function;
    }



    /**
     * Injects the request object for the current request or subrequest
     * Simply calls main() and init() and outputs the content
     *
     * @param ServerRequestInterface $request the current request
     * @param ResponseInterface      $response
     * @return ResponseInterface the response with the content
     */
    public function mainAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $GLOBALS['SOBE'] = $this;
        $this->init();
        $this->main();

        $this->view = $this->getFluidTemplateObject('nrsync', 'nrsync');
        $this->view->assign('moduleName', BackendUtility::getModuleUrl($this->moduleName));
        $this->view->assign('id', $this->id);
        //$this->view->assign('functionMenuModuleContent', $this->getExtObjContent());
        $this->view->assign('functionMenuModuleContent', $this->content);
        // Setting up the buttons and markers for docheader

        $this->getButtons();
        $this->generateMenu();

        //$this->content .= $this->view->render();

        $this->moduleTemplate->setContent(
            $this->content
            . '</form>'
        );
        $response->getBody()->write($this->moduleTemplate->renderContent());
        return $response;
    }



    /**
     * returns a new standalone view, shorthand function
     *
     * @param string $extensionName
     * @param string $controllerExtensionName
     * @param string $templateName
     * @return StandaloneView
     */
    protected function getFluidTemplateObject($extensionName, $controllerExtensionName, $templateName = 'Main')
    {
        /** @var StandaloneView $view */
        $view = $this->getObjectManager()->get(StandaloneView::class);
        $view->setLayoutRootPaths([GeneralUtility::getFileAbsFileName('EXT:' . $extensionName . '/Resources/Private/Layouts')]);
        $view->setPartialRootPaths([GeneralUtility::getFileAbsFileName('EXT:' . $extensionName . '/Resources/Private/Partials')]);
        $view->setTemplateRootPaths([GeneralUtility::getFileAbsFileName('EXT:' . $extensionName . '/Resources/Private/Templates')]);

        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName('EXT:' . $extensionName . '/Resources/Private/Templates/' . $templateName . '.html'));

        $view->getRequest()->setControllerExtensionName($controllerExtensionName);

        return $view;
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

        if (null === $arTables) {
            return false;
        } elseif (false !== array_search('pages', $arTables)) {
            return true;
        } else {
            foreach ($arTables as $strTableName) {
                if (isset($TCA[$strTableName])) {
                    $queryBuilder = $this->getQueryBuilderForTable($strTableName);

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
     * Shows the page selection, depending on selected id and tables to look at.
     *
     * @param string $strName  Name of this page selection (Depends on task).
     * @param array  $arTables Tables this task manages.
     *
     * @return string HTML Output for the selection box.
     */
    protected function showPageSelection(
        $strName, array $arTables
    ) {
        $strPreOutput = '';

        if ($this->id == 0) {
            $this->addError($this->getLabel('error.no_page'));
            return $strPreOutput;
        }

        $record = BackendUtility::getRecord('pages', $this->id);

        if (null === $record) {
            $this->addError($this->getLabel('error.page_not_load', ['{page_selected}' => $this->id]));
            return $strPreOutput;
        }

        if (false === $this->getArea()->isDocTypeAllowed($record)) {
            $this->addError($this->getLabel('error.page_type_not_allowed'));
            return $strPreOutput;
        }

        $bShowButton = false;

        $this->nRecursion = (int) $this->getBackendUser()->getSessionData(
            'nr_sync_synclist_levelmax' . $strName
        );
        if (isset($_POST['data']['rekursion'])) {
            $this->nRecursion = (int) $_POST['data']['levelmax'];
            $this->getBackendUser()->setAndSaveSessionData(
                'nr_sync_synclist_levelmax' . $strName, $this->nRecursion
            );
        }
        if ($this->nRecursion < 1) {
            $this->nRecursion = 1;
        }

        $this->getSubpagesAndCount(
            $this->id, $arCount, 0, $this->nRecursion,
            $this->getArea()->getNotDocType(), $this->getArea()->getDocType(),
            $arTables
        );

        $strTitle = $this->getArea()->getName() . ' - ' . $record['uid'] . ' - ' . $record['title'];
        if ($record['doktype'] == 4) {
            $strTitle .= ' - LINK';
        }

        $strPreOutput .= '<div class="form-section">';
        $strPreOutput .= '<input type="hidden" name="data[pageID]" value="' . $this->id . '">';
        $strPreOutput .= '<input type="hidden" name="data[count]" value="' . $arCount['count'] . '">';
        $strPreOutput .= '<input type="hidden" name="data[deleted]" value="' . $arCount['deleted'] . '">';
        $strPreOutput .= '<input type="hidden" name="data[noaccess]" value="' . $arCount['noaccess'] . '">';
        $strPreOutput .= '<input type="hidden" name="data[areaID]" value="' . $this->getArea()->getId() . '">';


        $strPreOutput .= '<h3>' . $strTitle . '</h3>';
        $strPreOutput .= '<div class="form-group">';
        if ($this->pageContainsData($this->id, $arTables)) {
            $strPreOutput .= '<div class="checkbox">';
            $strPreOutput .= '<label for="data_type_alone">'
                . '<input type="radio" name="data[type]" value="alone" id="data_type_alone" '
                . (($arCount['count'] === 0) ? 'checked':'') . '> '
                . $this->getLabel('label.page_only')
                . '</label>';
            $strPreOutput .= '</div>';
            $bShowButton = true;
        }

        if ($arCount['count'] > 0) {
            $strPreOutput .= '<div class="checkbox">';
            $strPreOutput .= '<label for="data_type_tree">'
                . '<input type="radio" name="data[type]" value="tree" id="data_type_tree"> '
                . $this->getLabel('label.page_and_subpages', ['{pages}' => $arCount['count']])
                . ' </label> <small>'
                . $this->getLabel(
                    'label.subpages_including',
                    [
                        '{deleted}' => $arCount['deleted'],
                        '{noaccess}' => $arCount['noaccess'],
                        '{falses}' => $arCount['falses']
                    ]
                )
                . '</small>';
            $strPreOutput .= '</div>';
            $bShowButton = true;
            if ($arCount['other_area'] > 0) {
                $strPreOutput .= '<br><b>' . $this->getLabel('label.excluded_pages') . '</b>';
            }
        }
        $strPreOutput .= '</div>';

        if (!$bShowButton) {
            $this->addError($this->getLabel('error.select_mark_type'));
        } else {
            $strPreOutput .= '<div class="form-group">';
            $strPreOutput .= '<div class="row">';

            $strPreOutput .= '<div class="form-group col-xs-6">';
            $strPreOutput .= '<button class="btn btn-default" type="submit" name="data[add]" value="Add to sync list">';
            $strPreOutput .= $this->getIconFactory()->getIcon('actions-add', Icon::SIZE_SMALL)->render();
            $strPreOutput .= $this->getLabel('button.add_to_list');
            $strPreOutput .= '</button>
                </div>';

            $strPreOutput .= '<div class="form-group col-xs-1">
            <input class="form-control" type="number" name="data[levelmax]" value="'
                . $this->nRecursion . '">'
                . ' </div>
            <div class="form-group col-xs-4 form">
            <input class="btn btn-default" type="submit" name="data[rekursion]" value="' .  $this->getLabel('button.recursion_depth'). '">
            </div>
            </div>';

            $strPreOutput .= '</div>';
        }


        $strPreOutput .= '</div>';

        return $strPreOutput;
    }



    /**
     * Manages adding and deleting of pages/trees to the sync list.
     *
     * @return SyncList
     */
    protected function manageSyncList()
    {
        // ID hinzufügen
        if (isset($_POST['data']['add'])) {
            if (isset($_POST['data']['type'])) {
                $this->getSyncList()->addToSyncList($_POST['data']);
            } else {
                $this->addError($this->getLabel('error.select_mark_type'));
            }
        }

        // ID entfernen
        if (isset($_POST['data']['delete'])) {
            $this->getSyncList()->deleteFromSyncList($_POST['data']);
        }

        $this->getSyncList()->saveSyncList();

        return $this->getSyncList();
    }



    /**
     * @param mixed $syncListId
     *
     * @return SyncList
     */
    protected function getSyncList($syncListId = null)
    {
        if (null === $syncListId) {
            $syncListId = $this->MOD_SETTINGS['function'];
        }
        return $this->getSyncListManager()->getSyncList($syncListId);
    }



    /**
     * @return Area
     */
    protected function getArea()
    {
        if (null === $this->area) {
            $this->area = $this->getObjectManager()->get(
                Area::class, $this->id, $this->MOD_SETTINGS['target']
            );
        }

        return $this->area;
    }

    /**
     * Returns true if the system is locked
     *
     * @param string $system System Dump directory
     *
     * @return bool
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException
     */
    public function isSystemLocked(string $system = '')
    {
        if (empty($system)) {
            return false;
        }

        $systemDirectory = $this->getSyncFolder()->getSubfolder($system);
        return $this->getDefaultStorage()->hasFile($systemDirectory->getIdentifier() . '.lock');
    }

    /**
     * Returns true if the target is locked
     *
     * @return bool
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException
     */
    private function isTargetLocked()
    {
        $systems = $this->getArea()->getSystems();
        if (false === isset($systems[$this->MOD_SETTINGS['target']])) {
            return false;
        }

        return $this->isSystemLocked($systems[$this->MOD_SETTINGS['target']]['directory']);
    }

    /**
     * Main function of the module. Write the content to $this->content
     *
     * If you chose 'web' as main module, you will need to consider the $this->id
     * parameter which will contain the uid-number of the page clicked in the page
     * tree
     *
     * @return void
     */
    public function main()
    {
        if (empty($this->MOD_SETTINGS['target'])) {
            $this->MOD_SETTINGS['target'] = 'all';
        }

        if ($this->MOD_SETTINGS['target'] !== 'all') {
            $this->statTable = $this->statTable . '_' . strtolower($this->MOD_SETTINGS['target']);
        }

        $color = "#fff";

        if ($this->MOD_SETTINGS['target'] === 'Integration') {
            $color = "#d1e2bd";
        }

        if ($this->MOD_SETTINGS['target'] === 'Production') {
            $color = "#efc7c7";
        }

        $this->content .= "<style type=\"text/css\">.module-body {background: $color} </style>";

        // Access check!
        // The page will show only if there is a valid page and if this page may
        // be viewed by the user
        $this->pageinfo = BackendUtility::readPageAccess(
            $this->id, $this->perms_clause
        );
        if ($this->pageinfo) {
            $this->moduleTemplate->getDocHeaderComponent()->setMetaInformation($this->pageinfo);
        }

        /* @var $syncLock SyncLock */
        $syncLock = $this->getObjectManager()->get(SyncLock::class);

        if ($this->getBackendUser()->isAdmin()) {
            $syncLock->handleLockRequest();
        }

        if (isset($_REQUEST['lock'])) {
            foreach ($_REQUEST['lock'] as $systemName => $lockState) {
                $systems = $this->getArea()->getSystems();
                $system = $systems[$systemName];
                $systemDirectory = $this->getSyncFolder()->getSubfolder($system['directory']);
                if ($lockState) {
                    $lockFile = $systemDirectory->getStorage()->createFile('.lock', $systemDirectory);
                    $lockFile->setContents('lock');
                    $this->addSuccess($this->getLabel('message.target_locked', ['{target}' => $systemName]));
                } else {
                    if ($this->getDefaultStorage()->hasFile($systemDirectory->getIdentifier() . '.lock')) {
                        $this->getDefaultStorage()->deleteFile(
                            $this->getDefaultStorage()->getFile($systemDirectory->getIdentifier() . '.lock')
                        );
                        $this->addSuccess($this->getLabel('message.target_unlocked', ['{target}' => $systemName]));
                    }
                }
            }
        }

        if ($syncLock->isLocked() || $this->isTargetLocked()) {
            $this->content .= '<div class="alert alert-warning">';
            $this->content .= $syncLock->getLockMessage();
            $this->content .= '</div>';
            return;
        }

        $bUseSyncList = false;

        if (! isset($this->arFunctions[(int) $this->MOD_SETTINGS['function']])) {
            $this->MOD_SETTINGS['function'] = 0;
        }

        $this->function = $this->getFunctionObject($this->MOD_SETTINGS['function']);

        $strDumpFile    = $this->function->getDumpFileName();

        $this->content .= '<h1>' . $this->getLabel('headline.crate_sync') . '</h1>';

        if (empty($this->MOD_SETTINGS['function']) ) {
            $this->addError($this->getLabel('error.selectSyncType'));
        }

        $this->content .= '<form action="" method="POST">';

        switch ((int)$this->MOD_SETTINGS['function']) {
            /**
             * Sync einzelner Pages/Pagetrees
             */
            case self::FUNC_SINGLE_PAGE: {
                $this->content .= $this->showPageSelection(
                    $this->MOD_SETTINGS['function'],
                    $this->function->getTableNames()
                );
                $this->manageSyncList();

                $bUseSyncList = true;
                break;
            }
        }

        $this->function->run($this->getArea());

        if ($this->function->hasError()) {
            $this->addError($this->function->getError());
        }

        $this->content .= $this->function->getContent();

        // sync process
        if (isset($_POST['data']['submit']) && $strDumpFile != '') {
            $strDumpFile = $this->addInformationToSyncfileName($strDumpFile);
            //set_time_limit(480);

            if ($bUseSyncList) {
                $syncList = $this->getSyncList();
                if (!$syncList->isEmpty()) {

                    $strDumpFileArea = date('YmdHis_') . $strDumpFile;

                    foreach ($syncList->getAsArray() as $areaID => $arSynclistArea) {

                        /* @var $area Area */
                        $area = $this->getObjectManager()->get(Area::class, $areaID, $this->MOD_SETTINGS['target']);

                        $arPageIDs = $syncList->getAllPageIDs($areaID);

                        $ret = $this->createShortDump(
                            $arPageIDs, $this->function->getTableNames(), $strDumpFileArea,
                            $area->getDirectories()
                        );

                        if ($ret && $this->createClearCacheFile('pages', $arPageIDs)) {
                            if ($area->notifyMaster() == false) {
                                $this->addError($this->getLabel('error.sync_in_progress'));
                                foreach ($area->getDirectories() as $strDirectory) {
                                    $areaFolder = $this->getSyncFolder()->getSubfolder($strDirectory);
                                    $this->getDefaultStorage()->deleteFile(
                                        $this->getDefaultStorage()->getFile($areaFolder->getIdentifier() . $strDumpFileArea)
                                    );
                                    $this->getDefaultStorage()->deleteFile(
                                        $this->getDefaultStorage()->getFile($areaFolder->getIdentifier() . $strDumpFileArea . '.gz')
                                    );
                                }
                            } else {
                                $this->addSuccess(
                                    $this->getLabel('success.sync_in_progress')
                                );
                                $syncList->emptyArea($areaID);
                            }

                            $syncList->saveSyncList();
                        }
                    }
                }
            } else {
                $bSyncResult = $this->createDumpToAreas(
                    $this->function->getTableNames(), $strDumpFile
                );

                if ($bSyncResult && method_exists($this->function, 'getPagesToClearCache')) {
                    $arPageIDs = $this->function->getPagesToClearCache();
                    $this->createClearCacheFile('pages', $arPageIDs);
                }

                if ($bSyncResult) {
                    $this->addSuccess(
                        $this->getLabel('success.sync_initiated')
                    );
                }
            }
        }

        $this->content .= '<div class="form-section">';

        if (empty($bUseSyncList) && !empty($this->function->getTableNames())) {
            /* @var $syncStats SyncStats */
            $syncStats = $this->getObjectManager()->get(SyncStats::class, $this->function->getTableNames(), $this->statTable);
            $syncStats->createTableSyncStats();
            $this->content .= $syncStats->getContent();
        }

        // Syncliste anzeigen
        if ($bUseSyncList) {
            if (! $this->getSyncList()->isEmpty()) {
                $this->content .= $this->getSyncList()->showSyncList();
            }
        }

        if (($bUseSyncList && ! $this->getSyncList()->isEmpty())
            || (false === $bUseSyncList && count($this->function->getTableNames()))
        ) {
            $this->content .= '<div class="form-group">';
            $this->content .= '<div class="checkbox">';
            $this->content .= '<label for="force_full_sync">'
                . '<input type="checkbox" name="data[force_full_sync]" value="1" id="force_full_sync">'
                . $this->getLabel('label.force_full_sync')
                . '</label>';
            $this->content .= '</div>';
            $this->content .= '<div class="checkbox">'
                . '<label for="delete_obsolete_rows">'
                . '<input type="checkbox" checked="checked" name="data[delete_obsolete_rows]" value="1" id="delete_obsolete_rows">'
                . $this->getLabel('label.delete_obsolete_rows')
                . '</label>';
            $this->content .= '</div>';
            $this->content .= '</div>';
        }
        $this->content .= '</div>';

        if (!empty($this->MOD_SETTINGS['function'])) {
            $strDisabled  = '';
            if ($bUseSyncList && $this->getSyncList()->isEmpty()) {
                $strDisabled = ' disabled="disabled"';
            }

            $this->content .= '<div class="form-section">';
            $this->content .= '<div class="form-group">';
            $this->content .= '<input class="btn btn-primary" type="Submit" name="data[submit]" value="' . $this->getLabel('button.create_sync') . '" ' . $strDisabled . '>';
            $this->content .= '</div>';
            $this->content .= '</div>';
        }

        $this->showSyncState();
    }



    /**
     * Shows how many files are waiting for sync and how old the oldest file is.
     *
     * @return void
     */
    protected function showSyncState()
    {
        $this->content .= '<br><h1>' . $this->getLabel('headline.waiting_syncs') . '</h1>';

        foreach ($this->getArea()->getSystems() as $systemKey => $system) {
            if (! empty($system['hide'])) {
                continue;
            }

            $this->content .= '<h2>';

            $systemFolder = $this->getSyncFolder()->getSubfolder($system['directory']);

            if ($systemFolder->hasFile('.lock')) {
                $href =  BackendUtility::getModuleUrl(
                    $this->moduleName,
                    [
                        'lock' => [$systemKey => '0'],
                        'id'   => $this->id,
                    ]
                );
                $icon = $this->getIconFactory()->getIcon('actions-lock', Icon::SIZE_SMALL);
                $this->content .= '<a href="' . $href . '" class="btn btn-warning" title="Sync disabled, click to enable">' . $icon . '</a>';
            } else {
                $href =  BackendUtility::getModuleUrl(
                    $this->moduleName,
                    [
                        'lock' => [$systemKey => '1'],
                        'id'   => $this->id,
                    ]
                );
                $icon = $this->getIconFactory()->getIcon('actions-unlock', Icon::SIZE_SMALL);
                $this->content .= '<a href="' . $href . '" class="btn btn-success" title="Sync enabled, click to disable">' . $icon . '</a>';
            }

            $this->content .= ' ' . $this->getLabel('label.sync_target') . ' "' . htmlspecialchars($system['name']) . '"</h2>';


            $files = $systemFolder->getFiles();

            $nDumpFiles = count($files);
            if ($nDumpFiles < 1) {
                continue;
            }

            $strFiles = '';
            $nSyncSize = 0;

            $strFiles .= '<div class="table-fit">';
            $strFiles .= '<table class="table table-striped table-hover" id="ts-overview">';
            $strFiles .= '<thead>';
            $strFiles .= '<tr><th>' . $this->getLabel('column.file') . '</th><th>' . $this->getLabel('column.size') . '</th></tr>';
            $strFiles .= '</thead>';
            $strFiles .= '<tbody>';

            /** @var FileInterface $file */
            foreach ($files as $file) {
                $nSize = $file->getSize();
                $nSyncSize += $nSize;

                $strFiles .= '<tr class="bgColor4">';
                $strFiles .= '<td>';
                $strFiles .= htmlspecialchars(basename($file->getIdentifier()));
                $strFiles .= '</td>';

                $strFiles .= '<td>';
                $strFiles .= number_format($nSize / 1024 / 1024, 2, '.', ',') . ' MiB';
                $strFiles .= '</td>';

                $strFiles .= '</tr>';
            }

            $strFiles .= '</tbody>';
            $strFiles .= '</table>';
            $strFiles .= '</div>';

            /** @var FileInterface $lastFile */
            $lastFile =  reset($files);
            $nTime = $lastFile->getCreationTime();
            if ($nTime < time() - 60 * 15) {
                // if oldest file time is older than 15 minutes display this in red
                $type = FlashMessage::ERROR;
            } else {
                $type = FlashMessage::INFO;
            }

            $size = number_format($nSyncSize / 1024 / 1024, 2, '.', ',') . ' MiB';
            $message = $this->getObjectManager()->get(
                FlashMessage::class,
                $this->getLabel(
                    'waitingfiles',
                    [   '{files}' => $nDumpFiles,
                        '{size}' => $size,
                        '{oldestFile}' => date('Y-m-d H:i', $nTime),
                        '{minutes}' => ceil((time() - $nTime) / 60)
                    ]
                ),
                '',
                $type
            );

            /* @var $renderer BootstrapRenderer */
            $renderer = $this->getObjectManager()->get(BootstrapRenderer::class);
            $this->content .= $renderer->render([$message]);

            $this->content .= $strFiles;
        }
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

        $queryBuilder = $this->getQueryBuilderForTable('pages');

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
     * Creating dump to areas
     *
     * @param string[]      $arTables    Table names
     * @param string        $strDumpFile Name of the dump file.
     * @param string|null   $targetName  Target to create sync for
     *
     * @return boolean success
     */
    public function createDumpToAreas(
        array $arTables, string $strDumpFile, string $targetName = null
    ) {
        $tempFolder = $this->getTempFolder();
        $filename = date('YmdHis_') . $strDumpFile;
        $tempFileIdentifier = $tempFolder->getIdentifier() . $strDumpFile;
        $target = $targetName ?? $this->MOD_SETTINGS['target'];


        if ( $this->getTempFolder()->getStorage()->hasFile($tempFileIdentifier)
             || $this->getTempFolder()->getStorage()->hasFile($tempFileIdentifier . '.gz')
        ) {
            $this->addError(
                $this->getLabel('error.last_sync_not_finished')
            );
            return false;
        }

        $this->getTempFolder()->getStorage()->createFile(
            $strDumpFile, $tempFolder
        );

        Table::writeDumps(
            $arTables, $tempFileIdentifier, $arOptions = array(
                'bForceFullSync'      => !empty($_POST['data']['force_full_sync']),
                'bDeleteObsoleteRows' => !empty($_POST['data']['delete_obsolete_rows']),
                'stateTable'          => $this->statTable
            )
        );


        try {
            $dumpFile = $this->getTempFolder()->getStorage()->getFile($tempFileIdentifier);
        } catch (\Exception $exception) {
            $this->addInfo(
                $this->getLabel('info.no_data_dumped')
            );
            return false;
        }

        $compressedDumFile = $this->createGZipFile($tempFolder, $dumpFile->getName());

        if (empty($compressedDumFile)) {
            $this->addError($this->getLabel('error.zip_failure', ['{file}' => $dumpFile->getIdentifier()]));
            return false;
        }

        foreach (Area::getMatchingAreas($target) as $area) {
            foreach ($area->getDirectories() as $strPath) {
                if ($this->isSystemLocked($strPath)) {
                    $this->addWarning($this->getLabel('warning.system_locked', ['{system}' => $strPath]));
                    continue;
                }
                $targetFolder = $this->getSyncFolder()->getSubfolder($strPath);
                try{
                    $targetName = $filename . '.gz';
                    $this->getDefaultStorage()->copyFile($compressedDumFile, $targetFolder, $targetName);
                } catch (\Exception $exception) {
                    $this->addError(
                        $this->getLabel(
                            'error.cannot_move_file',
                            [
                                '{file}' => $compressedDumFile->getIdentifier(),
                                '{target}' => $targetFolder->getIdentifier() . $filename . '.gz'
                            ]
                        )
                    );
                    return false;
                }
            }
            if (false === $area->notifyMaster()) {
                return false;
            }
        }
        $this->getTempFolder()->getStorage()->deleteFile($compressedDumFile);
        return true;
    }



    /**
     * Generates the file with the content for the clear cache task.
     *
     * @param string   $strTable    Name of the table which cache should be cleared.
     * @param int[]    $arUids      Array with the uids to clear cache.
     *
     * @return boolean True if file was generateable otherwise false.
     */
    private function createClearCacheFile($strTable, array $arUids)
    {
        $arClearCacheData = array();

        // Create data
        foreach ($arUids as $strUid) {
            $arClearCacheData[] = $strTable . ':' . $strUid;
        }

        $strClearCacheData = implode(',', $arClearCacheData);
        $clearCacheUrl = sprintf($this->strClearCacheUrl, $strClearCacheData);

        $this->urlGenerator->postProcessSync(
            ['arUrlsOnce' => [$clearCacheUrl], 'bProcess' => true, 'bSyncResult' => true],
            $this
        );

        return true;
    }



    /**
     * Baut Speciellen Dump zusammen, der nur die angewählten Pages enthällt.
     * Es werden nur Pages gedumpt, zu denen der Redakteur auch Zugriff hat.
     *
     * @param array  $arPageIDs   List if page IDs to dump
     * @param array  $arTables    List of tables to dump
     * @param string $strDumpFile Name of target dump file
     * @param array  $arPath
     *
     * @return boolean success
     */
    protected function createShortDump(
        $arPageIDs, $arTables, $strDumpFile, $arPath
    ) {
        if (!is_array($arPageIDs) || count($arPageIDs) <= 0) {
            $this->addError($this->getLabel('error.no_pages_marked'));
            return false;
        }


        try {
            $fpDumpFile = $this->openTempDumpFile($strDumpFile, $arPath);
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }

        foreach ($arTables as $value) {
            $this->dumpTableByPageIDs($arPageIDs, $value, $fpDumpFile);
        }

        // Append Statement for Delete unused rows in LIVE environment
        $this->writeToDumpFile(
            array(),
            array(),
            $fpDumpFile,
            $this->getDeleteRowStatements()
        );

        $this->writeInsertLines($fpDumpFile);

        try {
            $this->finalizeDumpFile($strDumpFile, $arPath, true);
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return false;
        }

        return true;
    }


    /**
     * Open the temporary dumpfile
     *
     * @param string $strFileName   Name of file
     * @param array  $arDirectories Array with directories
     *
     * @return FileInterface
     * @throws Exception
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
     * @throws \TYPO3\CMS\Core\Resource\Exception\IllegalFileExtensionException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException
     */
    private function openTempDumpFile(string $strFileName, array $arDirectories): FileInterface
    {
        $tempFolder = $this->getTempFolder();
        $tempDumpFileIdentifier = $tempFolder->getIdentifier() . $strFileName;

        $tempStorage = $tempFolder->getStorage();

        if ($tempStorage->hasFile($tempDumpFileIdentifier)
            || $tempStorage->hasFile($tempDumpFileIdentifier . '.gz')
        ) {
            throw new Exception(
                $this->getLabel('error.last_sync_not_finished')
                . "<br/>\n"
                . $tempDumpFileIdentifier . "(.gz)"
            );
        }
        foreach ($arDirectories as $strPath) {

            $folder = $this->getSyncFolder()->getSubfolder($strPath);
            $fileIdentifier = $folder->getIdentifier() . $strFileName;

            if ($this->getDefaultStorage()->hasFile($fileIdentifier)
                || $this->getDefaultStorage()->hasFile($fileIdentifier . '.gz')
            ) {
                throw new Exception(
                    $this->getLabel('error.last_sync_not_finished')
                );
            }
        }

        return $tempStorage->createFile($strFileName, $tempFolder);
    }



    /**
     * Zips the tmp dump file and copy it to given directories.
     *
     * @param string  $strDumpFile Name of the dump file.
     * @param array   $arDirectories The directories to copy files into.
     * @param boolean $bZipFile The directories to copy files into.
     *
     * @return void
     * @throws Exception If file can't be zipped or copied.
     */
    private function finalizeDumpFile($strDumpFile, array $arDirectories, $bZipFile)
    {
        $tempFolder  = $this->getTempFolder();
        $tempStorage = $tempFolder->getStorage();

        if ($bZipFile) {
            // Dateien komprimieren
            $dumpFile = $this->createGZipFile($tempFolder, $strDumpFile);
            if (empty($dumpFile)) {
                throw new Exception('Could not create ZIP file.');
            }
        }

        // Dateien an richtige Position kopieren
        foreach ($arDirectories as $strPath) {
            if (true === $this->isSystemLocked($strPath)) {
                $this->addWarning($this->getLabel('warning.system_locked', ['{system}' => $strPath]));
                continue;
            }
            $folder = $this->getSyncFolder()->getSubfolder($strPath);
            $this->getDefaultStorage()->copyFile($dumpFile, $folder);
        }
        $tempStorage->deleteFile($dumpFile);
    }



    /**
     * Erzeugt ein Dump durch Seiten IDs.
     *
     * @param array    $arPageIDs    Page ids to dump.
     * @param string   $strTableName Name of table to dump from.
     * @param FileInterface $fpDumpFile   File pointer to the SQL dump file.
     * @param boolean  $bContentIDs  True to interpret pageIDs as content IDs.
     *
     * @return void
     * @throws Exception
     */
    protected function dumpTableByPageIDs(
        array $arPageIDs, $strTableName, FileInterface $fpDumpFile, $bContentIDs = false
    ) {
        if (substr($strTableName, -3) == '_mm') {
            throw new Exception(
                $this->getLabel('error.mm_tables' , ['{tablename}' => $strTableName])
            );
        }

        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        $this->nDumpTableRecursion++;
        $arDeleteLine = array();
        $arInsertLine = array();

        /** @var Connection $connection */
        $connection = $connectionPool->getConnectionForTable($strTableName);
        $arColumns = $connection->getSchemaManager()
            ->listTableColumns($strTableName);

        $arColumnNames = [];
        foreach ($arColumns as $column) {
            $arColumnNames[] = $column->getQuotedName($connection->getDatabasePlatform());
        }

        $queryBuilder = $this->getQueryBuilderForTable($strTableName);

        // In pages und pages_language_overlay entspricht die pageID der uid
        // pid ist ja der Parent (Elternelement) ... so mehr oder weniger *lol*
        if ($strTableName == 'pages' || $bContentIDs) {
            $strWhere = $queryBuilder->expr()->in('uid', $arPageIDs);
        } else {
            $strWhere = $queryBuilder->expr()->in('pid', $arPageIDs);
        }

        $refTableContent = $queryBuilder->select('*')
            ->from($strTableName)
            ->where($strWhere)
            ->execute();

        if ($refTableContent) {
            while ($arContent = $refTableContent->fetch()) {
                $arDeleteLine[$strTableName][$arContent['uid']]
                    = $this->buildDeleteLine($strTableName, $arContent['uid']);
                $arInsertLine[$strTableName][$arContent['uid']]
                    = $this->buildInsertUpdateLine($strTableName, $arColumnNames, $arContent);

                $this->writeMMReferences(
                    $strTableName, $arContent, $fpDumpFile
                );
                if (count($arDeleteLine) > 50) {

                    $this->prepareDump($arDeleteLine, $arInsertLine, $fpDumpFile);
                    $arDeleteLine = array();
                    $arInsertLine = array();
                }
            }
        }

        if (!empty($_POST['data']['delete_obsolete_rows'])) {
            $this->addAsDeleteRowTable($strTableName);
        }

        $this->prepareDump($arDeleteLine, $arInsertLine, $fpDumpFile);

        $this->nDumpTableRecursion--;
    }



    /**
     * Adds the Table and its DeleteObsoleteRows statement to an array
     * if the statement does not exists in the array
     *
     * @param string $strTableName the name of the Table the obsolete rows
     *                             should be added to the $arObsoleteRows array
     *                             for
     *
     * @return void
     */
    public function addAsDeleteRowTable($strTableName)
    {
        $table = new Table($strTableName, 'dummy', $this->statTable);
        if (!isset($this->arObsoleteRows[0])) {
            $this->arObsoleteRows[0] = "-- Delete obsolete Rows on live";
        }
        $strSql = $table->getSqlDroppingObsoleteRows();
        unset($table);

        if (empty($strSql)) {
            return;
        }
        $strSqlKey = md5($strSql);

        if (isset($this->arObsoleteRows[$strSqlKey])) {
            return;
        }

        $this->arObsoleteRows[$strSqlKey] = $strSql;
    }



    /**
     * @return array
     */
    public function getDeleteRowStatements()
    {
        return $this->arObsoleteRows;
    }



    /**
     * Writes the references of a table to the sync data.
     *
     * @param string $strRefTableName Table to reference.
     * @param array $arContent The database row to find MM References.
     * @param FileInterface $fpDumpFile File pointer to the SQL dump file.
     *
     * @return void
     */
    protected function writeMMReferences(
        $strRefTableName, array $arContent, FileInterface $fpDumpFile
    ) {
        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        $arDeleteLine = array();
        $arInsertLine = array();

        $this->arReferenceTables = array();
        $this->addMMReferenceTables($strRefTableName);
        foreach ($this->arReferenceTables as $strMMTableName => $arTableFields) {
            /** @var Connection $connection */
            $connection = $connectionPool->getConnectionForTable($strMMTableName);
            $arColumns = $connection->getSchemaManager()->listTableColumns($strMMTableName);

            $arColumnNames = [];
            foreach ($arColumns as $column) {
                $arColumnNames[] = $column->getQuotedName($connection->getDatabasePlatform());
            }

            foreach ($arTableFields as $arMMConfig) {
                $this->writeMMReference(
                    $strRefTableName, $strMMTableName, $arContent['uid'],
                    $arMMConfig,
                    $arColumnNames,
                    $fpDumpFile
                );
            }
        }
        $this->prepareDump($arDeleteLine, $arInsertLine, $fpDumpFile);
    }



    /**
     * Writes the data of a MM table to the sync data.
     * Calls dumpTableByPageIDs for sys_file_reference if MM Table isn't sys_file. Or
     * calls dumpTableByPageIDs for tx_dam_mm_ref if MM Table isn't tx_dam.
     *
     * MM table structure:
     *
     * - uid_local
     * -- uid from 'local' table, local table ist first part of mm table name
     * -- sys_file_reference -> uid_local points to uid in sys_file
     *    /tx_dam_mm_ref -> uid_local points to uid in tx_dam
     * -- tt_news_cat_mm -> uid_local points to uid in tt_news_cat
     * - uid_foreign
     * -- uid from foreign table, foreign is the table in field 'tablenames'
     * --- tx_Dem_mm_ref -> uid_foreign points to uid in table from 'tablenames'
     * -- or static table name (hidden in code)
     * --- tt_news_cat_mm -> uid_foreign points to uid in tt_news
     * -- or last part of mm table name
     * --- sys_category_record_mm -> uid_foreign points to uid in sys_category
     *     /tx_dam_mm_cat -> uid_foreign points to uid in tx_dam_cat
     * - tablenames
     * -- optional, if present forms unique data with uid_* and ident
     * - ident
     * -- optional, if present forms unique data with uid_* and tablenames
     * -- points to a field in TCA or Flexform
     * - sorting - optional
     * - sorting_foreign - optional
     *
     * @param string   $strRefTableName Table which we get the references from.
     * @param string   $strTableName    Table to get MM data from.
     * @param integer  $uid             The uid of element which references.
     * @param array    $arMMConfig      The configuration of this MM reference.
     * @param array    $arColumnNames   Table columns
     * @param FileInterface $fpDumpFile      File pointer to the SQL dump file.
     *
     * @return void
     */
    protected function writeMMReference(
        $strRefTableName, $strTableName, $uid, array $arMMConfig,
        array $arColumnNames, FileInterface $fpDumpFile
    )
    {
        $arDeleteLine = array();
        $arInsertLine = array();

        $strFieldName = 'uid_foreign';
        if (isset($arMMConfig['foreign_field'])) {
            $strFieldName = $arMMConfig['foreign_field'];
        }

        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($strTableName);

        $strAdditionalWhere = ' AND ' . $connection->quoteIdentifier('tablenames')
            . ' = ' . $connection->quote($strRefTableName);

        $strWhere = $strFieldName . ' = ' . $uid;

        if (isset($arMMConfig['foreign_match_fields'])) {
            foreach ($arMMConfig['foreign_match_fields'] as $strName => $strValue) {
                $strWhere .= ' AND ' . $connection->quoteIdentifier($strName) . ' = ' . $connection->quote($strValue)
                    . $strAdditionalWhere;
            }
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $refTableContent = $queryBuilder->select('*')
            ->from($strTableName)
            ->where($strWhere)
            ->execute();

        $arDeleteLine[$strTableName][$strWhere]
            = 'DELETE FROM ' . $connection->quoteIdentifier($strTableName) . ' WHERE ' . $strWhere . ';';

        if ($refTableContent) {
            while ($arContent = $refTableContent->fetch()) {
                $strContentKey = implode('-', $arContent);
                $arInsertLine[$strTableName][$strContentKey] = $this->buildInsertUpdateLine(
                    $strTableName, $arColumnNames, $arContent
                );

                $strDamTable = 'sys_file';
                $strDamRefTable = 'sys_file_reference';

                if ($strRefTableName !== $strDamTable
                    && $arMMConfig['MM'] === $strDamRefTable
                    && $arMMConfig['form_type'] === 'user'
                ) {
                    $this->dumpTableByPageIDs(
                        array($arContent['uid_local']), $strDamTable, $fpDumpFile,
                        true
                    );
                }
            }
            unset($refTableContent);
        }

        $this->prepareDump($arDeleteLine, $arInsertLine, $fpDumpFile);
    }



    /**
     * Finds MM reference tables and the config of them. Respects flexform fields.
     * Data will be set in arReferenceTables
     *
     * @param string $strTableName Table to find references.
     *
     * @return void
     */
    protected function addMMReferenceTables($strTableName)
    {
        global $TCA;

        if ( ! isset($TCA[$strTableName]['columns'])) {
            return;
        }

        foreach ($TCA[$strTableName]['columns'] as $strFieldName => $arColumn) {
            if (isset($arColumn['config']['type'])) {
                switch ($arColumn['config']['type']) {
                    case 'inline':
                        $this->addForeignTableToReferences($arColumn);
                        break;
                    default:
                        $this->addMMTableToReferences($arColumn);
                }
            }
        }
    }



    /**
     * Adds Column config to references table, if a foreign_table reference config
     * like in inline-fields exists.
     *
     * @param array $arColumn Column config to get foreign_table data from.
     *
     * @return void
     */
    protected function addForeignTableToReferences($arColumn)
    {
        if (isset($arColumn['config']['foreign_table'])) {
            $strForeignTable = $arColumn['config']['foreign_table'];
            $this->arReferenceTables[$strForeignTable][] = $arColumn['config'];
        }
    }



    /**
     * Adds Column config to references table, if a MM reference config exists.
     *
     * @param array $arColumn Column config to get MM data from.
     *
     * @return void
     */
    protected function addMMTableToReferences(array $arColumn)
    {
        if (isset($arColumn['config']['MM'])) {
            $strMMTableName = $arColumn['config']['MM'];
            $this->arReferenceTables[$strMMTableName][] = $arColumn['config'];
        }
    }



    /**
     * Add the passed $arSqlLines to the $arGlobalSqlLineStorage in unique way.
     *
     * @param string $strStatementType the type of the current arSqlLines
     * @param array  $arSqlLines       multidimensional array of sql statements
     *
     * @return void
     */
    protected function addLinesToLineStorage($strStatementType, array $arSqlLines)
    {
        foreach ($arSqlLines as $strTableName => $arLines) {
            if (!is_array($arLines)) {
                return;
            }
            foreach ($arLines as $strIdentifier => $strLine) {
                $this->arGlobalSqlLineStorage[$strStatementType][$strTableName][$strIdentifier] = $strLine;
            }
        }
    }



    /**
     * Removes all entries from $arSqlLines which already exists in $arGlobalSqlLineStorage
     *
     * @param string $strStatementType Type the type of the current arSqlLines
     * @param array  &$arSqlLines      multidimensional array of sql statements
     *
     * @return void
     */
    public function clearDuplicateLines($strStatementType, array &$arSqlLines)
    {
        foreach ($arSqlLines as $strTableName => $arLines) {
            foreach ($arLines as $strIdentifier => $strStatement) {
                if (!empty($this->arGlobalSqlLineStorage[$strStatementType][$strTableName][$strIdentifier])) {
                    unset($arSqlLines[$strTableName][$strIdentifier]);
                }
            }
            // unset tablename key if no statement exists anymore
            if (0 === count($arSqlLines[$strTableName])) {
                unset($arSqlLines[$strTableName]);
            }
        }
    }



    /**
     * Writes the data into dump file. Line per line.
     *
     * @param array $arDeleteLines The lines with the delete statements.
     *                                        Expected structure:
     *                                        $arDeleteLines['table1']['uid1'] = 'STATMENT1'
     *                                        $arDeleteLines['table1']['uid2'] = 'STATMENT2'
     *                                        $arDeleteLines['table2']['uid2'] = 'STATMENT3'
     * @param array $arInsertLines The lines with the insert statements.
     *                                        Expected structure:
     *                                        $arInsertLines['table1']['uid1'] = 'STATMENT1'
     *                                        $arInsertLines['table1']['uid2'] = 'STATMENT2'
     *                                        $arInsertLines['table2']['uid2'] = 'STATMENT3'
     * @param FileInterface $fpDumpFile File pointer to the SQL dump file.
     * @param array $arDeleteObsoleteRows the lines with delete obsolete
     *                                        rows statement
     *
     * @throws Exception
     * @return void
     */
    protected function writeToDumpFile(
        array $arDeleteLines,
        array $arInsertLines,
        FileInterface $fpDumpFile,
        $arDeleteObsoleteRows = array()
    ) {

        $fileContent = $fpDumpFile->getContents();

        // Keep the current lines in mind
        $this->addLinesToLineStorage(
            self::STATEMENT_TYPE_DELETE,
            $arDeleteLines
        );
        // Keep the current lines in mind
        $this->addLinesToLineStorage(
            self::STATEMENT_TYPE_INSERT,
            $arInsertLines
        );

        // Foreach Table in DeleteArray
        foreach ($arDeleteLines as $arDelLines) {
            if (count($arDelLines)) {
                $strDeleteLines = implode("\n", $arDelLines);
                $fileContent .= $strDeleteLines . "\n\n";
            }
        }

        // do not write the inserts here, we want to add them
        // at the end of the file see $this->writeInsertLines

        if (count($arDeleteObsoleteRows)) {
            $strDeleteObsoleteRows = implode("\n", $arDeleteObsoleteRows);
            $fileContent .= $strDeleteObsoleteRows . "\n\n";
        }

        $fpDumpFile->setContents($fileContent);

        foreach ($arInsertLines as $strTable => $arInsertStatements) {
            foreach ($arInsertStatements as $nUid => $strStatement) {
                $this->setLastDumpTimeForElement($strTable, $nUid);
            }
        }
    }



    /**
     * Writes all SQL Lines from arGlobalSqlLineStorage[self::STATEMENT_TYPE_INSERT]
     * to the passed file stream.
     *
     * @param File $fpDumpFile the file to write the lines to
     *
     * @return void
     */
    protected function writeInsertLines(File $fpDumpFile)
    {
        if (!is_array(
            $this->arGlobalSqlLineStorage[self::STATEMENT_TYPE_INSERT]
        )) {
            return;
        }

        $arInsertLines = $this->arGlobalSqlLineStorage[self::STATEMENT_TYPE_INSERT];
        // Foreach Table in InsertArray
        $content = $fpDumpFile->getContents();
        foreach ($arInsertLines as $strTable => $arTableInsLines) {
            if (count($arTableInsLines)) {
                $strInsertLines
                    = '-- Insert lines for Table: '
                    . $strTable
                    . "\n";
                $strInsertLines .= implode("\n", $arTableInsLines);
                $content .= $strInsertLines . "\n\n";
            }
        }
        $fpDumpFile->setContents($content);
        return;
    }



    /**
     * Removes all delete statements from $arDeleteLines where an insert statement
     * exists in $arInsertLines.
     *
     * @param array &$arDeleteLines referenced array with delete statements
     *                              structure should be
     *                              $arDeleteLines['table1']['uid1'] = 'STATMENT1'
     *                              $arDeleteLines['table1']['uid2'] = 'STATMENT2'
     *                              $arDeleteLines['table2']['uid2'] = 'STATMENT3'
     * @param array &$arInsertLines referenced array with insert statements
     *                              structure should be
     *                              $arDeleteLines['table1']['uid1'] = 'STATMENT1'
     *                              $arDeleteLines['table1']['uid2'] = 'STATMENT2'
     *                              $arDeleteLines['table2']['uid2'] = 'STATMENT3'
     *
     * @return void
     */
    protected function diffDeleteLinesAgainstInsertLines(
        array &$arDeleteLines, array &$arInsertLines
    ) {
        foreach ($arInsertLines as $strTableName => $arElements) {
            // no modification for arrays with old flat structure
            if (!is_array($arElements)) {
                return;
            }
            // UNSET each delete line where an insert exists
            foreach ($arElements as $strUid => $strStatement) {
                if (!empty($arDeleteLines[$strTableName][$strUid])) {
                    unset($arDeleteLines[$strTableName][$strUid]);
                }
            }

            if (0 === count($arDeleteLines[$strTableName])) {
                unset($arDeleteLines[$strTableName]);
            }
        }
    }



    /**
     * Returns SQL DELETE query.
     *
     * @param string $strTableName name of table to delete from
     * @param integer $uid uid of row to delete
     *
     * @return string
     */
    protected function buildDeleteLine($strTableName, $uid)
    {
        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($strTableName);

        return 'DELETE FROM '
            . $connection->quoteIdentifier($strTableName)
            . ' WHERE uid = ' . (int) $uid . ';';
    }



    /**
     * Returns SQL INSERT .. UPDATE ON DUPLICATE KEY query.
     *
     * @param string $strTableName name of table to insert into
     * @param array  $arColumnNames
     * @param array  $arContent
     *
     * @return string
     */
    protected function buildInsertUpdateLine($strTableName, array $arColumnNames, array $arContent)
    {
        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($strTableName);

        $arUpdateParts = array();
        foreach ($arContent as $key => $value) {
            if (!is_numeric($value)) {
                $arContent[$key] = $connection->quote($value);
            }
            // Match the column to its update value
            $arUpdateParts[$key] = '`' . $key . '` = VALUES(`' . $key . '`)';
        }

        $strStatement = 'INSERT INTO '
            . $connection->quoteIdentifier($strTableName)
            . ' (' . implode(', ', $arColumnNames) . ') VALUES ('
            . implode(', ', $arContent) . ')' . "\n"
            . ' ON DUPLICATE KEY UPDATE '
            . implode(', ', $arUpdateParts) . ';';

        return $strStatement;
    }



    /**
     * Returns SQL INSERT query.
     *
     * @param string $strTableName name of table to insert into
     * @param array  $arTableStructure
     * @param array  $arContent
     *
     * @return string
     */
    protected function buildInsertLine($strTableName, $arTableStructure, $arContent)
    {
        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($strTableName);

        foreach ($arContent as $key => $value) {
            if (!is_numeric($value)) {
                $arContent[$key] = $connection->quote($value);
            }
        }

        $arColumnNames = array_keys($arTableStructure);
        $str = 'REPLACE INTO '
            . $connection->quoteIdentifier($strTableName)
            . ' (' . implode(', ', $arColumnNames) . ') VALUES ('
            . implode(', ', $arContent) . ');';

        return $str;
    }


    /**
     * Creates an Gzip File from a Dumpfile
     *
     * @param Folder $folder   Folder where the file si stored
     * @param string $fileName Name of File
     *
     * @return FileInterface
     */
    protected function createGZipFile(Folder $folder, string $fileName): ?FileInterface
    {
        $tempStorage = $this->getTempFolder()->getStorage();

        try {
            $fileIdentifier = $folder->getIdentifier() . $fileName;
            $dumpFile = $tempStorage->getFile($fileIdentifier);
            $compressedDumpFile = $tempStorage->createFile(
                $fileName . '.gz', $folder
            );
            $compressedDumpFile->setContents(gzencode($dumpFile->getContents(), 9));
            $tempStorage->deleteFile($dumpFile);
        } catch (\Exception $exception) {
            $this->addError($this->getLabel('error.zip_failure', ['{file}' => $dumpFile->getIdentifier()]));
            return null;
        }

        return $compressedDumpFile;
    }



    /**
     * Generates the menu based on $this->MOD_MENU
     *
     */
    protected function generateMenu()
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('WebFuncJumpMenu');
        $menu->setLabel(
            $this->getLabel('label.sync_type')
        );

        foreach ($this->MOD_MENU['function'] as $controller => $title) {
            $item = $menu
                ->makeMenuItem()
                ->setHref(
                    BackendUtility::getModuleUrl(
                        $this->moduleName,
                        [
                            'id' => $this->id,
                            'SET' => [
                                'function' => $controller,
                                'target' => $this->MOD_SETTINGS['target']
                            ],
                        ]
                    )
                )
                ->setTitle($this->getLabel($title));
            if ($controller === (int) $this->MOD_SETTINGS['function']) {
                $item->setActive(true);
            }
            $menu->addMenuItem($item);
        }
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('WebFuncJumpMenuTarget');
        $menu->setLabel(
            $this->getLabel('label.sync_target')
        );
        foreach ($this->MOD_MENU['target'] as $target => $title) {
            $targetItem = $menu
                ->makeMenuItem()
                ->setHref(
                    BackendUtility::getModuleUrl(
                        $this->moduleName,
                        [
                            'id' => $this->id,
                            'SET' => [
                                'function' =>  $this->MOD_SETTINGS['function'],
                                'target' => $target,
                            ],
                        ]
                    )
                )
                ->setTitle($this->getLabel($title));
            if ($target === $this->MOD_SETTINGS['target']) {
                $targetItem->setActive(true);
            }
            $menu->addMenuItem($targetItem);
        }
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }



    /**
     * Create the panel of buttons for submitting the form or otherwise perform operations.
     */
    protected function getButtons()
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();

        // CSH
        $cshButton = $buttonBar->makeHelpButton()
            ->setModuleName($this->moduleName)
            ->setFieldName('');
        $buttonBar->addButton($cshButton);

        if ($this->getBackendUser()->isAdmin()) {
            // Lock
            $this->addButtonBarLockButton();
            $this->addButtonBarAreaLockButtons();
        }

        if ($this->id && is_array($this->pageinfo)) {
            // Shortcut
            $shortcutButton = $buttonBar->makeShortcutButton()
                ->setModuleName($this->moduleName)
                ->setGetVariables(['id', 'edit_record', 'pointer', 'new_unique_uid', 'search_field', 'search_levels', 'showLimit'])
                ->setSetVariables(array_keys($this->MOD_MENU));
            $buttonBar->addButton($shortcutButton);
        }
    }

    protected function addButtonBarAreaLockButtons()
    {
        foreach ($this->getArea()->getSystems() as $systemName => $system) {
            if (! empty($system['hide'])) {
                continue;
            }
            $this->addButtonBarAreaLockButton($systemName, $system);
        }
    }

    protected function addButtonBarAreaLockButton($systemName, array $system)
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $lockButton = $buttonBar->makeLinkButton();

        $systemDirectory = $this->getSyncFolder()->getSubfolder($system['directory']);

        if ($systemDirectory->hasFile('.lock')) {
            $lockButton->setHref(
                BackendUtility::getModuleUrl(
                    $this->moduleName,
                    [
                        'lock' => [$systemName => '0'],
                        'id'   => $this->id,
                    ]
                )
            );
            $lockButton->setTitle($this->getLabel('label.unlock_target', ['{system}' => $system['name']]));
            $lockButton->setIcon($this->getIconFactory()->getIcon('actions-lock', Icon::SIZE_SMALL));
            $lockButton->setClasses('btn btn-warning');
        } else {
            $lockButton->setHref(
                BackendUtility::getModuleUrl(
                    $this->moduleName,
                    [
                        'lock' => [$systemName => '1'],
                        'id'   => $this->id,
                    ]
                )
            );
            $lockButton->setTitle($this->getLabel('label.lock_target', ['{system}' => $system['name']]));
            $lockButton->setIcon($this->getIconFactory()->getIcon('actions-unlock', Icon::SIZE_SMALL));
        }

        $lockButton->setShowLabelText(true);

        $buttonBar->addButton($lockButton);
    }



    /**
     * @return void
     */
    protected function addButtonBarLockButton()
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $lockButton = $buttonBar->makeLinkButton();

        /* @var $syncLock SyncLock */
        $syncLock = $this->getObjectManager()->get(SyncLock::class);

        if ($syncLock->isLocked()) {
            $lockButton->setHref(
                BackendUtility::getModuleUrl(
                    $this->moduleName,
                    [
                        'data' => ['lock' => '0'],
                        'id'   => $this->id,
                    ]
                )
            );
            $lockButton->setTitle($this->getLabel('label.unlock_module'));
            $lockButton->setIcon($this->getIconFactory()->getIcon('actions-lock', Icon::SIZE_SMALL));
            $lockButton->setClasses('btn-warning');
            $lockButton->setShowLabelText(true);
        } else {
            $lockButton->setHref(
                BackendUtility::getModuleUrl(
                    $this->moduleName,
                    [
                        'data' => ['lock' => '1'],
                        'id'   => $this->id,
                    ]
                )
            );
            $lockButton->setTitle($this->getLabel('label.lock_module'));
            $lockButton->setIcon($this->getIconFactory()->getIcon('actions-unlock', Icon::SIZE_SMALL));
            $lockButton->setShowLabelText(true);
        }

        $buttonBar->addButton($lockButton, ButtonBar::BUTTON_POSITION_LEFT, 0);
    }



    /**
     * @return ObjectManager
     */
    protected function getObjectManager()
    {
        return $this->objectManager;
    }



    protected function getSyncListManager()
    {
        if (null === $this->syncListManager) {
            $this->syncListManager = $this->getObjectManager()->get(SyncListManager::class);
        }

        return $this->syncListManager;
    }



    /**
     * @return IconFactory
     */
    protected function getIconFactory()
    {
        if (null === $this->iconFactory) {
            $this->iconFactory = $this->getObjectManager()->get(IconFactory::class);
        }

        return $this->iconFactory;
    }



    /**
     * Adds error message to message queue.
     *
     * @param string $strMessage error message
     *
     * @return void
     */
    public function addError($strMessage)
    {
        $this->addMessage($strMessage, FlashMessage::ERROR);
    }

    /**
     * Adds warning message to message queue.
     *
     * @param string $strMessage success message
     *
     * @return void
     */
    public function addWarning($strMessage)
    {
        $this->addMessage($strMessage, FlashMessage::WARNING);
    }

    /**
     * Adds success message to message queue.
     *
     * @param string $strMessage success message
     *
     * @return void
     */
    public function addSuccess($strMessage)
    {
        $this->addMessage($strMessage, FlashMessage::OK);
    }



    /**
     * Adds error message to message queue.
     *
     * @param string $strMessage info message
     *
     * @return void
     */
    public function addInfo($strMessage)
    {
        $this->addMessage($strMessage, FlashMessage::INFO);
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
    public function addMessage($strMessage, $type)
    {
        /* @var $message FlashMessage */
        $message = $this->getObjectManager()->get(
            FlashMessage::class, $strMessage, '', $type, true
        );

        /* @var $messageService FlashMessageService */
        $messageService = $this->getObjectManager()->get(
            FlashMessageService::class
        );

        $messageService->getMessageQueueByIdentifier()->addMessage($message);
    }



    /**
     * Remove entries not needed for the sync.
     *
     * @param array $arLines lines with data to sync
     *
     * @return array
     */
    protected function removeNotSyncableEntries(array $arLines)
    {
        $arResult = $arLines;
        foreach ($arLines as $strTable => $arStatements) {
            foreach ($arStatements as $nUid => $strStatement) {
                if (!$this->isElementSyncable($strTable, $nUid)) {
                    unset($arResult[$strTable][$nUid]);
                }
            }
        }
        return $arResult;
    }



    /**
     * Sets time of last dump/sync for this element.
     *
     * @param string  $strTable the table, the element contains to
     * @param integer $nUid     the uid for the element
     *
     * @return void
     * @throws Exception
     */
    protected function setLastDumpTimeForElement($strTable, $nUid)
    {
        if (strpos($nUid, '-')) {
            // CRAP - we get something like: 47-18527-0-0-0--0-0-0-0-0-0-1503315964-1500542276-…
            // happens in writeMMReference() before createupdateinsertline()
            // take second number as ID:
            $nUid = explode('-', $nUid)[1];
        }

        $nTime = time();
        $nUserId = intval($this->getBackendUser()->user['uid']);
        $strUpdateField = ($this->getForcedFullSync()) ? 'full' : 'incr';

        /* @var $connectionPool ConnectionPool */
        $connectionPool = $this->getObjectManager()->get(ConnectionPool::class);

        /* @var $connection \TYPO3\CMS\Core\Database\Connection */
        $connection = $connectionPool->getConnectionForTable($this->statTable);

        $connection->exec(
            'INSERT INTO ' . $this->statTable
            . ' (tab, ' . $strUpdateField . ', cruser_id, uid_foreign) VALUES '
            . ' ('
            . $connection->quote($strTable)
            . ', ' . $connection->quote($nTime)
            . ', ' . $connection->quote($nUserId)
            . ', ' . $connection->quote($nUid) . ')'
            . ' ON DUPLICATE KEY UPDATE'
            . ' cruser_id = ' . $connection->quote($nUserId) . ', '
            . $strUpdateField . ' = ' . $connection->quote($nTime)
        );
    }



    /**
     * Fetches syncstats for an element from db.
     *
     * @param string $strTable the table, the element belongs to
     * @param integer $nUid the uid for the element
     *
     * @return array|boolean syncstats for an element or false if stats don't exist
     * @throws Exception
     */
    protected function getSyncStatsForElement($strTable, $nUid)
    {
        $queryBuilder = $this->getQueryBuilderForTable($strTable);

        $arRow = $queryBuilder->select('*')
            ->from($this->statTable)
            ->where(
                $queryBuilder->expr()->eq('tab', $queryBuilder->quote($strTable)),
                $queryBuilder->expr()->eq('uid_foreign', intval($nUid))
            )
            ->execute()
            ->fetch();

        return $arRow;
    }


    /**
     * Returns time stamp of this element.
     *
     * @param string $strTable The table, the elements belongs to
     * @param integer $nUid The uid of the element.
     *
     * @return integer
     * @throws Exception
     */
    protected function getTimestampOfElement($strTable, $nUid)
    {
        $queryBuilder = $this->getQueryBuilderForTable($strTable);

        $arRow = $queryBuilder->select('tstamp')
            ->from($strTable)
            ->where(
                $queryBuilder->expr()->eq('uid', intval($nUid))
            )
            ->execute()
            ->fetch();

        return $arRow['tstamp'];
    }


    /**
     * Clean up statements and prepare dump file.
     *
     * @param array         $arDeleteLine Delete statements
     * @param array         $arInsertLine Insert statements
     * @param FileInterface $fpDumpFile   dump file
     *
     * @return void
     */
    protected function prepareDump(array $arDeleteLine, array $arInsertLine, FileInterface $fpDumpFile)
    {
        if (!$this->getForcedFullSync()) {
            $arDeleteLine = $this->removeNotSyncableEntries($arDeleteLine);
            $arInsertLine = $this->removeNotSyncableEntries($arInsertLine);
        }

        // Remove Deletes which has a corresponding Insert statement
        $this->diffDeleteLinesAgainstInsertLines(
            $arDeleteLine,
            $arInsertLine
        );

        // Remove all DELETE Lines which already has been put to file
        $this->clearDuplicateLines(
            self::STATEMENT_TYPE_DELETE,
            $arDeleteLine
        );
        // Remove all INSERT Lines which already has been put to file
        $this->clearDuplicateLines(
            self::STATEMENT_TYPE_INSERT,
            $arInsertLine
        );
        $this->writeToDumpFile($arDeleteLine, $arInsertLine, $fpDumpFile);

        $this->writeStats($arInsertLine);
    }



    /**
     * Write stats for the sync.
     *
     * @param array $arInsertLines insert array ofstatements for elements to sync
     *
     * @return void
     */
    protected function writeStats(array $arInsertLines)
    {
        foreach ($arInsertLines as $strTable => $arInstertStatements) {
            if (strpos($strTable, '_mm') !== false) {
                continue;
            }
            foreach ($arInstertStatements as $nUid => $strStatement) {
                $this->setLastDumpTimeForElement($strTable, $nUid);
            }
        }

    }



    /**
     * Return true if a full sync should be forced.
     *
     * @return boolean
     */
    protected function getForcedFullSync()
    {
        return isset($_POST['data']['force_full_sync'])
            && !empty($_POST['data']['force_full_sync']);
    }



    /**
     * Return true if an element, given by tablename and uid is syncable.
     *
     * @param string $strTable the table, the element belongs to
     * @param integer $nUid the uid of the element
     *
     * @return boolean
     */
    protected function isElementSyncable($strTable, $nUid)
    {
        if (strpos($strTable, '_mm') !== false) {
            return true;
        }

        $arSyncStats = $this->getSyncStatsForElement($strTable, $nUid);
        $nTimeStamp = $this->getTimestampOfElement($strTable, $nUid);
        if (!$nTimeStamp) {
            return false;
        }
        if (isset($arSyncStats['full']) && $arSyncStats['full'] > $nTimeStamp) {
            return false;
        }

        return true;
    }



    /**
     * Adds information about full or inc sync to syncfile
     *
     * @param string $strDumpFile the name of the file
     *
     * @return string
     */
    protected function addInformationToSyncfileName($strDumpFile)
    {
        $bIsFullSync = !empty($_POST['data']['force_full_sync']);
        $strPrefix = 'inc_';
        if ($bIsFullSync) {
            $strPrefix = 'full_';
        }
        return $strPrefix . strtolower($this->MOD_SETTINGS['target']) . '_' . $strDumpFile;
    }
}
