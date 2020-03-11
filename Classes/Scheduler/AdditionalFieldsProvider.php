<?php


namespace Netresearch\NrSync\Scheduler;

/**
 * Additional Fields Provider for the importer task
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class AdditionalFieldsProvider extends AbstractAdditionalFieldsProvider
{
    /**
     * @var string
     */
    public const FIELD_NAME_PATH = 'sync_storage_path';
    public const FIELD_NAME_URLS = 'sync_urls_path';

    /**
     * @var string
     */
    private const TRANSLATION_FILE = "LLL:EXT:nr_sync/Resources/Private/Language/locallang_scheduler.xlf";

    /**
     * Returns the task prefix
     *
     * @return string
     */
    public function getTaskPrefix(): string
    {
        return "tx_nrsync";
    }

    /**
     * Returns the array with the field configuration
     *
     * @return array
     */
    public function getFieldConfiguration(): array
    {
        return [
            self::FIELD_NAME_PATH => [
                'default' => false,
                'type' => Fields\TextField::class,
                'translationFile' => self::TRANSLATION_FILE,
                'validators' => [],
            ],
            self::FIELD_NAME_URLS => [
                'default' => false,
                'type' => Fields\TextField::class,
                'translationFile' => self::TRANSLATION_FILE,
                'validators' => [],
            ],
        ];
    }
}
