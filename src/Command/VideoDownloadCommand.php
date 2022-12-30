<?php

declare(strict_types=1);

namespace xorik\YtUpload\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use xorik\YtUpload\Model\VideoState;
use xorik\YtUpload\Service\QueueManager;

#[AsCommand(name: 'yt:download')]
class VideoDownloadCommand extends Command
{
    public function __construct(
        readonly private QueueManager $queueManager,
        readonly private string $cachePath,
    ) {
        parent::__construct();

        $this->addArgument('id', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $video = $this->queueManager->getWithLock(Uuid::fromString($input->getArgument('id')));

        // Check if status is queued/downloading or file was downloaded and removed
        $correctStatus = \in_array($video->state, [VideoState::QUEUED, VideoState::DOWNLOADING], true);
        $fileMissing = $video->state === VideoState::DOWNLOADED && !is_file($video->downloadedPath);
        if (!$correctStatus && !$fileMissing) {
            throw new \RuntimeException(sprintf('Incorrect status for video %s: %s', $video->id, $video->state->value));
        }

        // Update status and save to queue
        $downloadPath = sprintf('%s/%s.mp4', $this->cachePath, $video->id);
        $logPath = str_replace('.mp4', '.log', $downloadPath);
        $video = $video->download($downloadPath);
        $this->queueManager->save($video);

        // Start downloading
        $command = ['yt-dlp', $video->sourceUrl, '-o', $downloadPath];
        if ($video->range !== null) {
            $command[] = '--download-sections';
            $command[] = '*' . $video->range;
        }

        $process = new Process($command);
        $process->setTimeout(null);
        $process->mustRun(function (string $type, string $buffer) use ($logPath) {
            file_put_contents($logPath, $buffer, \FILE_APPEND);
        });

        $this->queueManager->saveAndUnlock($video->downloaded());

        return Command::SUCCESS;
    }
}
