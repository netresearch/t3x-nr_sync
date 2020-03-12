<?php


namespace Netresearch\NrSync\Scheduler\Fields;

/**
 * Textarea field
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
class TextAreaField extends AbstractField
{
    /**
     * Returns the field HTML
     *
     * @return string
     */
    public function getFieldHtml(): string
    {
        $fieldCode = "<textarea class=\"form-control\" name=\"tx_scheduler[%s]\" id=\"%s\" />%s</textarea>";

        return sprintf(
            $fieldCode,
            $this->getIdentifier(),
            $this->getIdentifier(),
            $this->getValue()
        );
    }
}
