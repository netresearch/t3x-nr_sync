<?php


namespace Netresearch\NrSync\Scheduler\Fields;


/**
 * Checkbox field
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class CheckBoxField extends AbstractField
{
    /**
     * Returns the field HTML
     *
     * @return string
     */
    public function getFieldHtml(): string
    {
        $fieldCode = "<input type=\"checkbox\" name=\"tx_scheduler[%s]\" id=\"%s\" value=\"%s\" %s/>";

        return sprintf(
            $fieldCode,
            $this->getIdentifier(),
            $this->getIdentifier(),
            "1",
            ("1" === $this->getValue()) ? "checked=\"checked\"" : ""
        );
    }
}
