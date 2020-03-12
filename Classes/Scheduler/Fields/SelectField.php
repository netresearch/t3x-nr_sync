<?php


namespace Netresearch\NrSync\Scheduler\Fields;

/**
 * Select field
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class SelectField extends AbstractField
{
    /**
     * @param array $options
     *
     * @return SelectField
     */
    public function setOptions(array $options): SelectField
    {
        $this->options = $options;
        return $this;
    }

    /**
     * @var array
     */
    private $options = [];

    /**
     * Return the options code
     *
     * @return string
     */
    private function getOptionsCode(): string
    {
        $selected = $this->getValue();

        $options = [];

        foreach ($this->options as $value => $label) {
            $option = "<option value=\"%s\"%s>%s</option>";
            $options[] = sprintf(
                $option,
                $value,
                ((string) $value === (string) $selected) ? 'selected="selected"' : '',
                $label
            );
        }

        return implode(PHP_EOL, $options);
    }

    /**
     * Returns the field HTML
     *
     * @return string
     */
    public function getFieldHtml(): string
    {
        $fieldCode = "<select class=\"form-control tceforms-select\"  id=\"%s\" name=\"tx_scheduler[%s]\">%s</select>";

        return sprintf(
            $fieldCode,
            $this->getIdentifier(),
            $this->getIdentifier(),
            $this->getOptionsCode()
        );
    }
}
