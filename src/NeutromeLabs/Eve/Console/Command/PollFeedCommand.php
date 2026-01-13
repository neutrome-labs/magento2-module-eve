<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Console\Command;

use NeutromeLabs\Eve\Service\FeedProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to poll Eve RSS feed
 * 
 * Usage: bin/magento eve:feed:poll [--limit=50]
 */
class PollFeedCommand extends Command
{
    public function __construct(
        private readonly FeedProcessor $feedProcessor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('eve:feed:poll')
            ->setDescription('Poll Eve RSS feed for new entries')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum entries to fetch',
                50
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        
        $output->writeln('<info>Polling Eve feed...</info>');
        
        $result = $this->feedProcessor->poll($limit);
        
        if ($result['success']) {
            $fetched = $result['fetched'] ?? 0;
            $processed = $result['processed'] ?? 0;
            
            if ($fetched === 0) {
                $output->writeln('<comment>No new feed entries</comment>');
            } else {
                $output->writeln("<info>Fetched: {$fetched}, Processed: {$processed}</info>");
                
                if (!empty($result['results']) && $output->isVerbose()) {
                    foreach ($result['results'] as $r) {
                        $status = $r['success'] ? '<info>✓</info>' : '<error>✗</error>';
                        $message = $r['message'] ?? $r['action'] ?? $r['error'] ?? 'processed';
                        $output->writeln("  {$status} {$message}");
                    }
                }
            }
            
            return Command::SUCCESS;
        } else {
            $error = $result['error'] ?? $result['message'] ?? 'Unknown error';
            $output->writeln("<error>Feed poll failed: {$error}</error>");
            return Command::FAILURE;
        }
    }
}
