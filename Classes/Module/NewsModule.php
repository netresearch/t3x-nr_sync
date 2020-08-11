<?php
namespace Netresearch\NrSync\Module;

use Doctrine\DBAL\FetchMode;
use Netresearch\NrSync\Traits\TranslationTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Methods to work with synchronization areas
 *
 * @package    Netresearch/TYPO3/Sync
 * @author     Axel Seemann <axel.seemann@netresearch.de>
 * @copyright  2020 Netresearch DTT GmbH
 * @license    https://www.gnu.org/licenses/agpl AGPL v3
 * @link       http://www.netresearch.de
 */
class NewsModule extends BaseModule
{
    use TranslationTrait;

    /**
     * @var string[] Tables which should be synchronized
     */
    protected $tables = [
        'tx_news_domain_model_link',
        'tx_news_domain_model_news',
        'tx_news_domain_model_news_related_mm',
        'tx_news_domain_model_news_tag_mm',
        'tx_news_domain_model_news_ttcontent_mm',
        'tx_news_domain_model_tag',
        'sys_file_reference'
    ];

    /**
     * @var string Name of the sync displayed in Backend
     */
    protected $name = 'News';

    /**
     * @var string Type Of Sync
     */
    protected $type = 'sync_tables';

    /**
     * @var string Sync Target
     */
    protected $target = '';

    /**
     * @var string Base name of the syncfile
     */
    protected $dumpFileName = 'news.sql';

    /**
     * @var int Level who is allowed to access
     */
    protected $accessLevel = 0;

    /**
     * Returns the PageID to clear the cache for.
     *
     * @return array
     */
    public function getPagesToClearCache(): array
    {
        $connection  = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');

        $queryBuilder = $connection->createQueryBuilder();

        $queryBuilder->select('pid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->like('list_type', $queryBuilder->createNamedParameter('%news%'))
            )->groupBy('pid');

        try {
            return $queryBuilder->execute()->fetchAll(FetchMode::COLUMN);
        } catch (\Exception $exception) {
            return [];
        }
    }
}
