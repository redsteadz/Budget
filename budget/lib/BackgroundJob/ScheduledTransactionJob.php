<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to transition scheduled transactions to cleared
 * when their date has arrived.
 *
 * Runs every 6 hours as a cleanup mechanism. Reports are already
 * self-correcting (they include scheduled transactions whose date
 * has passed), so this job just ensures data consistency.
 */
class ScheduledTransactionJob extends TimedJob {
    public function __construct(ITimeFactory $time) {
        parent::__construct($time);

        // Run every 6 hours
        $this->setInterval(6 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $mapper = Server::get(TransactionMapper::class);
        $logger = Server::get(LoggerInterface::class);

        try {
            $transactions = $mapper->findScheduledDueForTransition();
            $count = 0;
            $now = date('Y-m-d H:i:s');

            foreach ($transactions as $transaction) {
                try {
                    $transaction->setStatus('cleared');
                    $transaction->setUpdatedAt($now);
                    $mapper->update($transaction);
                    $count++;
                } catch (\Exception $e) {
                    $logger->warning('Failed to transition scheduled transaction {id}: {error}', [
                        'id' => $transaction->getId(),
                        'error' => $e->getMessage(),
                        'app' => 'budget',
                    ]);
                }
            }

            if ($count > 0) {
                $logger->info('Transitioned {count} scheduled transaction(s) to cleared', [
                    'count' => $count,
                    'app' => 'budget',
                ]);
            }
        } catch (\Exception $e) {
            $logger->error('ScheduledTransactionJob failed: {error}', [
                'error' => $e->getMessage(),
                'app' => 'budget',
            ]);
        }
    }
}
