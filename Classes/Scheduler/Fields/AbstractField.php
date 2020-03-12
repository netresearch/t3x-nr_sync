<?php


namespace Netresearch\NrSync\Scheduler\Fields;

/**
 * Abstract field
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
abstract class AbstractField
{
    /**
     * @var string
     */
    private $identifier;

    /**
     * @var string
     */
    private $label;

    /**
     * @var mixed
     */
    private $value;

    /**
     * AbstractField constructor.
     *
     * @param string $identifier Field identifier
     * @param string $label      Label of the field
     * @param null   $value      Value of the field
     */
    public function __construct(string $identifier, string $label, $value = null)
    {
        $this->identifier = $identifier;
        $this->label      = $label;
        $this->value      = $value;
    }

    /**
     * Returns the field HTML
     *
     * @return string
     */
    abstract public function getFieldHtml(): string;

    /**
     * Returns the identifier
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Returns the label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Returns the value
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Returns the array for the additional field
     *
     * @return array
     */
    public function getAdditionalField(): array
    {
        return [
            'code' => $this->getFieldHtml(),
            'label' => $this->getLabel()
        ];
    }
}
