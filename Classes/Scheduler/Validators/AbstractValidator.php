<?php


namespace Netresearch\NrSync\Scheduler\Validators;

/**
 * Abstract validator field
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
abstract class AbstractValidator
{
    /**
     * @var mixed
     */
    private $value;

    /**
     * @var string
     */
    private $fieldName;

    /**
     * @var string
     */
    private $message;

    /**
     * AbstractValidator constructor.
     *
     * @param mixed  $value     Value to check
     * @param string $fieldName Name of field
     * @param string $message   Error message
     */
    public function __construct($value, string $fieldName, string $message = '')
    {
        $this->value = $value;
        $this->fieldName = $fieldName;
        $this->message = $message;
    }

    /**
     * Validates the value and return the result of validation as bool
     *
     * @return bool
     */
    abstract public function validate(): bool;

    /**
     * Returns the error message
     *
     * @return string
     */
    abstract public function getErrorMessage(): string;
}
