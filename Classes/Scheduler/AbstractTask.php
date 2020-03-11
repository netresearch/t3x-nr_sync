<?php


namespace Netresearch\NrSync\Scheduler;

use Netresearch\NrSync\Traits\FlashMessageTrait;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MailUtility;

/**
 * Abstract scheduler task
 *
 * @package   Netresearch/TYPO3/Sync
 * @author    Axel Seemann <axel.seemann@netresearch.de>
 * @company   Netresearch DTT GmbH
 * @copyright 2020 Netresearch DTT GmbH
 * @license   https://www.gnu.org/licenses/agpl AGPL v3
 * @link      http://www.netresearch.de
 */
abstract class AbstractTask extends \TYPO3\CMS\Scheduler\Task\AbstractTask
{
    use FlashMessageTrait;

    /**
     * Executes the task
     *
     * @return bool
     */
    abstract public function executeTask(): bool;

    /**
     * Executes the task and send reporting if the execution fails and the reporting is enabled.
     *
     * @return bool
     * @throws \Exception
     */
    public function execute()
    {
        if (false === $this->isRunnableInContext()) {
            $this->addInfoMessage('Run skipped context mismatch');
            return true;
        }

        try {
            $result =  $this->executeTask();
            if (false === $result) {
                $this->sendReporting('The execution fails without specific error.');
            }
        } catch (\Exception $exception) {
            $this->sendReporting($exception->getMessage());

            throw $exception;
        }

        return $result;
    }

    /**
     * Send the reporting
     *
     * @param string $message Message to send
     *
     * @throws Exception
     */
    private function sendReporting(string $message): void
    {
        if (false === $this->isReportingEnabled()) {
            return;
        }

        try {
            $sentMails = $this->sendEmail(
                $this->getReportingEmails(),
                $this->getReportingContent($message)
            );

            if ($sentMails === 0) {
                throw new Exception(
                    'The reporting could not be sent!'
                );
            }
        } catch (\Exception $exception) {
            throw new Exception(
                'The reporting could not be sent due to the mail api throws the ' .
                'following error: ' . $exception->getMessage()
            );
        }
    }

    /**
     * Send the reporting emails
     *
     * @param array  $recipients Array with email addresses the mail should send to
     * @param string $email      The email content which will be sent
     *
     * @return int
     */
    private function sendEmail(array $recipients, string $email): int
    {
        /** @var MailMessage $mailApi */
        $mailApi = GeneralUtility::makeInstance(MailMessage::class);

        $from = MailUtility::getSystemFrom();

        $mailApi->setTo($recipients)
            ->setSubject($this->getReportingSubject())
            ->setBody($email, 'text/plain', 'utf-8')
            ->setFrom($from);

        return $mailApi->send();
    }


    /**
     * Returns true if mail reporting is enabled
     *
     * @return bool
     */
    private function isReportingEnabled(): bool
    {
        return (bool) $this->{AbstractAdditionalFieldsProvider::FIELD_ENABLE_REPORTING};
    }

    /**
     * Returns the email-addresses where the reporting should be sent to
     *
     * @return array
     */
    private function getReportingEmails(): array
    {
        if (empty($this->{AbstractAdditionalFieldsProvider::FIELD_REPORTING_EMAILS})) {
            return [];
        }

        return explode(',', $this->{AbstractAdditionalFieldsProvider::FIELD_REPORTING_EMAILS});
    }

    /**
     * Returns the subject of the reporting email
     *
     * @return string
     */
    private function getReportingSubject(): string
    {
        if (empty($this->{AbstractAdditionalFieldsProvider::FIELD_REPORTING_SUBJECT})) {
            return 'The execution of task ' . $this->taskUid . '(' . get_class($this). ') has failed!';
        }

        return $this->{AbstractAdditionalFieldsProvider::FIELD_REPORTING_SUBJECT};
    }

    /**
     * Returns the email content to send
     *
     * @param string $message Message
     *
     * @return string
     */
    private function getReportingContent(string $message): string
    {
        $content = "";
        $newLine = "\r\n";

        $content .= $message . $newLine . $newLine;

        if (!empty($this->{$strProperty})) {
            $content .= $this->{AbstractAdditionalFieldsProvider::FIELD_REPORTING_MESSAGE};
            $content .= $newLine . $newLine;
        }

        $content .= "----------------" . $newLine;
        $content .= "Task info" . $newLine;
        $content .= "----------------" . $newLine;
        $content .= "Task ID: " . $this->getTaskUid() . $newLine;
        $content .= "Task execuiton: " . date('Y-m-d H:i:s') . $newLine;
        $content .= "Task error: " . $message . $newLine;

        return $content;
    }

    /**
     * Returns true if the task should run in the current application context
     *
     * @return bool
     */
    private function isRunnableInContext(): bool
    {
        if (empty($this->{AbstractAdditionalFieldsProvider::FIELD_ENVIRONMENT})) {
            return true;
        }


        $currentContext = GeneralUtility::getApplicationContext();
        $contexts = explode(',', $this->{AbstractAdditionalFieldsProvider::FIELD_ENVIRONMENT});

        if (in_array((string) $currentContext, $contexts)) {
            return true;
        }

        return false;
    }
}
