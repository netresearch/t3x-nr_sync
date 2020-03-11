<?php


namespace Netresearch\NrSync\Scheduler;

use Netresearch\NrSync\Scheduler\Fields\AbstractField;
use Netresearch\NrSync\Scheduler\Fields\SelectField;
use Netresearch\NrSync\Scheduler\Validators\AbstractValidator;
use Netresearch\NrSync\Traits\FlashMessageTrait;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Abstract AdditionalField Provider
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
abstract class AbstractAdditionalFieldsProvider implements AdditionalFieldProviderInterface
{
    use FlashMessageTrait;

    /**
     * Constants for field names
     */
    public const FIELD_ENABLE_REPORTING  = "enable_reporting";
    public const FIELD_REPORTING_EMAILS  = "reporting_emails";
    public const FIELD_REPORTING_SUBJECT = "reporting_subject";
    public const FIELD_REPORTING_MESSAGE = "reporting_message";
    public const FIELD_ENVIRONMENT       = "environment";

    /**
     * Defaults
     */
    private const DEFAULT_LANGUAGE_FILE  = "LLL:EXT:nr_sync/Resources/Private/Language/locallang_scheduler.xlf";

    /**
     * Contains the errors as key value pair. The key represents the field name and the value is the error message.
     *
     * @var array
     */
    private $errors = [];

    /**
     * @var array Array which contains the additional field definitions
     */
    private $definedFields = [];

    /**
     * @var array containing the submitted data
     */
    private $submittedData = [];

    /**
     * AbstractAdditionalFieldsProvider constructor.
     */
    public function __construct()
    {
        $this->definedFields = $this->getBasicFieldConfiguration() + $this->getFieldConfiguration();
    }

    /**
     * Returns the field configuration
     *
     * @return array
     */
    abstract public function getFieldConfiguration(): array;

    /**
     * Returns the task prefix
     *
     * @return string
     */
    abstract public function getTaskPrefix(): string;

    /**
     * Return the basic fields config
     *
     * @return array
     */
    private function getBasicFieldConfiguration(): array
    {
        return [
            self::FIELD_ENABLE_REPORTING => [
                'default' => false,
                'type' => Fields\CheckBoxField::class,
                'translationFile' => self::DEFAULT_LANGUAGE_FILE,
                'validators' => [],
            ],
            self::FIELD_REPORTING_EMAILS => [
                'default' => false,
                'type' => Fields\TextField::class,
                'translationFile' => '',
                'validators' => [],
            ],
            self::FIELD_REPORTING_SUBJECT => [
                'default' => "",
                'type' => Fields\TextField::class,
                'validators' => [],
                'translationFile' => '',
            ],
            self::FIELD_REPORTING_MESSAGE => [
                'default' => "",
                'type' => Fields\TextAreaField::class,
                'validators' => [],
                'translationFile' => '',
            ],
            self::FIELD_ENVIRONMENT => [
                'default' => "",
                'type' => Fields\TextField::class,
                'validators' => [],
                'translationFile' => '',
            ],
        ];
    }

    /**
     * Build the additional form fields
     *
     * @param array                     $taskInfo     Array with the Task information
     * @param AbstractTask              $task         The task object
     * @param SchedulerModuleController $parentObject Parent object context
     *
     * @todo Refactor Select Field. Currently no options are passed to the fields.
     *
     * @return array
     */
    final public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $parentObject):array
    {
        $additionalFields = [];

        foreach ($this->definedFields as $key => $config) {
            /** @var AbstractField $field */
            $identifier = $this->getFieldKey($key);
            $field = GeneralUtility::makeInstance(
                $config['type'],
                $identifier,
                $this->getLabel($key, $config['translationFile']),
                $this->getFieldValue($key, $taskInfo, $task, $parentObject)
            );

            if ($field instanceof SelectField) {
                $field->setOptions($config['options']);
            }

            $additionalFields[] = $field->getAdditionalField();
        }

        return $additionalFields;
    }

    /**
     * Validates the Additional fields
     *
     * @param array                     $submittedData Array with Submitted data
     * @param SchedulerModuleController $parentObject  Parent object context
     *
     * @return bool
     */
    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $parentObject): bool
    {
        $this->submittedData = $submittedData;

        foreach ($this->definedFields as $key => $field) {
            if (empty($field['validators'])) {
                continue;
            }

            /** @var AbstractValidator $validator */
            foreach ($field['validators'] as $validatorClass) {
                $validator = GeneralUtility::makeInstance($validatorClass, $this->getValue($key), $key);
                if (false === $validator->validate()) {
                    $this->errors[$key] = $validator->getErrorMessage();
                }
            }
        }

        if (count($this->errors) === 0 ) {
            return true;
        }

        foreach ($this->errors as $key => $error) {
            $this->addErrorMessage($error);
        }

        return false;
    }

    /**
     * Saves the data of additional field to the
     *
     * @param array        $submittedData Data submitted by the from
     * @param AbstractTask $task          TaskObject to save the data to
     *
     * @return void
     */
    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        $this->submittedData = $submittedData;

        foreach ($this->definedFields as $key => $field) {
            $task->{$key} = $this->getValue($key);
        }
    }

    /**
     * Get the value for a field
     *
     * @param string                    $name         Field identifier
     * @param array                     $taskInfo     Array with the Task information
     * @param AbstractTask              $task         The task object
     * @param SchedulerModuleController $parentObject Parent object context
     *
     * @return mixed
     */
    private function getFieldValue(string $name, &$taskInfo, $task, $parentObject)
    {
        $fieldIdentifier = $this->getFieldKey($name);

        if (empty($taskInfo[$fieldIdentifier])) {
            if ($parentObject->getCurrentAction()->equals('edit')) {
                $taskInfo[$fieldIdentifier] = $task->{$name};
            } else {
                $taskInfo[$fieldIdentifier] = $this->definedFields[$name]['default'];
            }
        }

        return $taskInfo[$fieldIdentifier];
    }

    /**
     * Return a value from submitted data
     *
     * @param string $name Name of value to get
     *
     * @return mixed
     */
    private function getValue(string $name)
    {
        if ($value = $this->submittedData[$this->getFieldKey($name)]) {
            return trim(strip_tags($value));
        }

        return null;
    }

    /**
     * Returns the identifier for a field
     *
     * @param string $fieldName Name of field
     *
     * @return string
     */
    private function getFieldKey(string $fieldName): string
    {
        return $this->getTaskPrefix() . '_' . $fieldName;
    }

    /**
     * Returns a instance of the language service.
     *
     * @return LanguageService
     */
    private function getLanguageService(): LanguageService
    {
        return  $GLOBALS['LANG'];
    }

    /**
     * Return the translated label
     *
     * @param string $name         Name of label or field
     * @param string $languageFile Path of the language file
     *
     * @return string
     */
    final protected function getLabel(string $name, string $languageFile = ''): string
    {
        if (empty($languageFile)) {
            $languageFile = self::DEFAULT_LANGUAGE_FILE;
        }

        return $this->getLanguageService()->sL(
            $languageFile . ":" . $name
        );
    }
}
