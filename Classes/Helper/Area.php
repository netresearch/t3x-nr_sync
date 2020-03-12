<?php

namespace Netresearch\NrSync\Helper;

use Netresearch\NrSync\Traits\TranslationTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Methods to work with synchronization areas
 *
 * @package    Netresearch/TYPO3/Sync
 * @author     Christian Weiske <christian.weiske@netresearch.de>
 * @copyright  2020 Netresearch DTT GmbH
 * @license    https://www.gnu.org/licenses/agpl AGPL v3
 * @link       http://www.netresearch.de
 */
class Area
{
    use TranslationTrait;

    var $areas = [
        0 => [
            'name'                 => 'Alle',
            'description'          => 'Sync to live server',
            'not_doctype'          => [],
            'system'               => [
                'Production' => [
                    'name'      => 'Production',
                    'directory' => 'production',
                    'url-path'  => 'production/url',
                    'notify'    => [
                        'type'     => 'none',
                    ],
                ],
                'Integration'  => [
                    'name'      => 'Integration',
                    'directory' => 'integration',
                    'url-path'  => 'integration/url',
                    'notify'    => [
                        'type'     => 'none',
                    ],
                ],
                'archive'  => [
                    'name'      => 'Archive',
                    'directory' => 'archive',
                    'url-path'  => 'archive/url',
                    'notify'    => [
                        'type'     => 'none',
                    ],
                    'hide'      => true,
                ],
            ],
            'sync_fe_groups'       => true,
            'sync_be_groups'       => true,
            'sync_tables'          => true,
        ],
    ];

    /**
     * @var array active area configuration
     */
    protected $area = [
        'id'             => 0,
        'name'           => '',
        'description'    => '',
        'not_doctype'    => [],
        'system'         => [],
        'sync_fe_groups' => true,
        'sync_be_groups' => true,
        'sync_tables'    => true,
    ];

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

    protected $selectedTarget;


    /**
     * Area constructor.
     *
     * @param integer $pId    Page ID
     * @param string  $target System which is selected for sync
     */
    public function __construct(int $pId, $target = 'all')
    {
        $this->selectedTarget = $target;
        if (isset($this->areas[$pId])) {
            $this->area = $this->areas[$pId];
            $this->area['id'] = $pId;
            $this->removeUnwantedSystems();
        } else {
            $rootLine = BackendUtility::BEgetRootLine($pId);
            foreach ($rootLine as $element) {
                if (isset($this->areas[$element['uid']])) {
                    $this->area = $this->areas[$element['uid']];
                    $this->area['id'] = $element['uid'];
                    $this->removeUnwantedSystems();
                    break;
                }
            }
        }
    }

    private function removeUnwantedSystems()
    {
        if ($this->selectedTarget === 'all') {
            return;
        }

        foreach ($this->area['system'] as $key => $system) {
            if ($this->selectedTarget !== $key && $key !== 'archive') {
                unset($this->area['system'][$key]);
            }
        }
    }


    /**
     * Return all areas that shall get synced for the given table type
     *
     * @param string $target       Target to syncto
     * @param array  $arAreas      Area configurations
     * @param string $strTableType Type of tables to sync, e.g. "sync_tables",
     *                             "sync_fe_groups", "sync_be_groups", "backsync_tables"
     *
     * @return self[]
     */
    public static function getMatchingAreas($target = 'all', array $arAreas = null, $strTableType = '')
    {
        /* @var $objectManager ObjectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);

        return array(
            $objectManager->get(self::class, 0, $target),
        );
    }



    public function isDocTypeAllowed(array $record)
    {
        if (false === $record) {
            return false;
        }

        if ((isset($this->area['doctype']) && !in_array($record['doktype'], $this->area['doctype']))
            && (isset($this->area['not_doctype']) && in_array($record['doktype'], $this->area['not_doctype']))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Returns the name of AREA
     *
     * @return mixed
     */
    public function getName()
    {
        return $this->area['name'];
    }

    /**
     * Returns the ID of the area
     *
     * @return mixed
     */
    public function getId()
    {
        return $this->area['id'];
    }

    /**
     * Returns the description of the area
     *
     * @return mixed
     */
    public function getDescription()
    {
        return $this->area['description'];
    }

    /**
     * Returns the files which should be synced
     *
     * @return mixed
     */
    public function getFilesToSync()
    {
        return $this->area['files_to_sync'];
    }

    /**
     * Returns a array with the directories where the syncfiles are stored
     *
     * @return array
     */
    public function getDirectories()
    {
        $arPaths = array();

        foreach ($this->area['system'] as $arSystem) {
            if (empty($arSystem['directory'])) {
                continue;
            }
            array_push($arPaths, $arSystem['directory']);
        }

        return $arPaths;
    }

    /**
     * Returns a array with the directories where the url files should be stored
     *
     * @return array
     */
    public function getUrlDirectories()
    {
        $arPaths = [];

        foreach ($this->area['system'] as  $arSystem) {
            if (empty($arSystem['url-path'])) {
                continue;
            }
            array_push($arPaths, $arSystem['url-path']);
        }

        return $arPaths;
    }

    /**
     * Returns the doctypes wich should be ignored for sync
     *
     * @return array
     */
    public function getNotDocType()
    {
        return (array) $this->area['not_doctype'];
    }

    /**
     * Returns the syncabel docktypes
     *
     * @return array
     */
    public function getDocType()
    {
        return (array) $this->area['doctype'];
    }

    /**
     * Returns the systems
     *
     * @return array
     */
    public function getSystems()
    {
        return (array) $this->area['system'];
    }



    /**
     * Informiert Master(LIVE) Server per zb. FTP
     *
     * @return boolean True if all went well, false otherwise
     */
    public function notifyMaster()
    {
        foreach ($this->getSystems() as $arSystem) {
            if ($this->systemIsNotifyEnabled($arSystem)) {
                switch ($arSystem['notify']['type']) {
                    case 'ftp':
                        $this->notifyMasterViaFtp($arSystem['notify']);
                        $this->addMessage(
                            $this->getLabel('message.notify_success', ['{target}' => $arSystem['name']])
                        );
                        break;
                    default:
                        $this->addMessage(
                            $this->getLabel(
                                'message.notify_unknown',
                                [
                                    '{target}' => $arSystem['name'],
                                    '{notify_type}' => $arSystem['notify']['type']
                                ]
                            )
                        );
                }
            }
        }

        return true;
    }



    /**
     * Returns true if current TYPO3_CONTEXT fits with context whitelist for system/target.
     *
     * given system.contexts = ['Production/Stage', 'Production/Foo']
     *
     * TYPO3_CONTEXT = Production/Live
     * returns false
     *
     * TYPO3_CONTEXT = Production
     * returns false
     *
     * TYPO3_CONTEXT = Production/Stage
     * returns true
     *
     * TYPO3_CONTEXT = Production/Stage/Instance01
     * returns true
     *
     * @param array $system
     * @return bool
     */
    protected function systemIsNotifyEnabled(array $system)
    {
        if (empty($system['notify']['contexts'])) {
            $this->addMessage(
                $this->getLabel('message.notify_disabled',['{target}' => $system['name']])
            );
            return false;
        }

        foreach ($system['notify']['contexts'] as $context) {
            $configuredContext = $this->objectManager->get(ApplicationContext::class, $context);

            $contextCheck = strpos(
                (string) GeneralUtility::getApplicationContext(),
                (string) $configuredContext
            );

            if (0 === $contextCheck) {
                return true;
            }
        }

        $this->addMessage(
            $this->getLabel(
                'message.notify_skipped_context',
                [
                    '{target}' => $system['name'],
                    '{allowed_contexts}' => implode(', ', $system['notify']['contexts'])
                ]
            )
        );

        return false;
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
     * Inform the Master(LIVE) Server per FTP
     *
     * @param string[] $arFtpConfig Config of the ftp connection
     * @throws \Exception
     */
    protected function notifyMasterViaFtp(array $arFtpConfig)
    {
        $conn_id = ftp_connect($arFtpConfig['host']);

        if (!$conn_id) {
            throw new \Exception('Signal: FTP connection failed.');
        }

        $login_result = ftp_login($conn_id, $arFtpConfig['user'], $arFtpConfig['password']);

        if (!$login_result) {
            throw new \Exception('Signal: FTP auth failed.');
        }

        // enforce passive mode
        ftp_pasv($conn_id, true);

        // create trigger file
        $source_file = tempnam(sys_get_temp_dir(), 'prefix');

        if (false === ftp_put($conn_id, 'db.txt', $source_file, FTP_BINARY)) {
            ftp_quit($conn_id);
            throw new \Exception('Signal: FTP put db.txt failed.');
        }

        if (false === ftp_put($conn_id, 'files.txt', $source_file, FTP_BINARY)) {
            ftp_quit($conn_id);
            throw new \Exception('Signal: FTP put files.txt failed.');
        }

        ftp_quit($conn_id);
    }
}
