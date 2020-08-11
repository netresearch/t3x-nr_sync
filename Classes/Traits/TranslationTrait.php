<?php

namespace Netresearch\NrSync\Traits;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Class Translation Trait
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
trait TranslationTrait
{
    private $defaultLanguageFile = 'LLL:EXT:nr_sync/Resources/Private/Language/locallang_module.xlf';

    /**
     * Returns a instance of the language service.
     *
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return  $GLOBALS['LANG'];
    }

    /**
     * Return the translated label
     *
     * @param string $name         Name of label or field
     * @param array  $data         Array with data to replace in the message.
     * @param string $languageFile Path of the language file
     *
     * @return string
     */
    private function getLabel(string $name, array $data = [], string $languageFile = ''): string
    {
        if (empty($languageFile)) {
            $languageFile = $this->defaultLanguageFile;
        }

        $translation = $this->getLanguageService()->sL(
            $languageFile . ":" . $name
        );

        if (empty($translation)) {
            return $name;
        }

        if (empty($data)) {
            return $translation;
        }

        return str_replace(array_keys($data), $data, $translation);
    }
}
