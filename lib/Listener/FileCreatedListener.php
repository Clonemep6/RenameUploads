<?php
declare(strict_types=1);

namespace OCA\RenameUploads\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use OCA\RenameUploads\BackgroundJobs\DeferredRenameQueueJob;
use OCA\RenameUploads\BackgroundJobs\RenameFileJob;
use OCA\RenameUploads\Service\RenameService;

class FileCreatedListener implements IEventListener {

    private IJobList $jobList;
    private LoggerInterface $logger;

    public function __construct(IJobList $jobList, LoggerInterface $logger) {
        $this->jobList = $jobList;
        $this->logger = $logger;
	$this->logger->debug("RenameUploads: File Event Listener Constructor called");
    }

    public function handle(Event $event): void {
	$this->logger->debug("RenameUploads: Handler Method Called");
        if (!$event instanceof NodeWrittenEvent) {
            return;
        }
	$this->logger->debug("RenameUploads: NodeWritten Event Detected");
        $node = $event->getNode();
        if (!$node instanceof File) {
            return;
        }
	
	$path = $node->getPath();
        $fileId = $node->getId();
        $fileName = $node->getName();
	$this->logger->debug("RenameUploads: Extracted filename as {$fileName}");
        // 1. Exclude .whiteboard files
        if (str_ends_with(strtolower($fileName), '.whiteboard')) {
            $this->logger->debug("RenameUploads: Ignoring .whiteboard file: {$fileName}", ['app' => 'renameuploads']);
            return;
        }
	

        // 2. Exclude files that already appear to be renamed by this app to prevent loops
        if (preg_match('/^\d{2}-\d{2}-\d{2} \d{2}-\d{2}-\d{2}/', $fileName)) {
            $this->logger->debug("RenameUploads: Ignoring already renamed file: {$fileName}", ['app' => 'renameuploads']);
            return;
        }
        
        $this->logger->debug("RenameUploads: File event detected for {$fileName}. Queuing deferred job to check for tags.", ['app' => 'renameuploads']);

        $argument = json_encode([
            'fileId' => $fileId,
            'path' => $path,
        ]);

	$argumentC = [
    		'fileId' => $fileId,
    		'path' => $path,
	];

	$existingJobsD = $this->jobList->has(DeferredRenameQueueJob::class, $argumentC);
	$existingJobsR = $this->jobList->has(RenameFileJob::class, $argumentC);

	if ($existingJobsD || $existingJobsR) {
    		$this->logger->info("FileUploadedListener: Not creating new DeferredRenameQueueJob because duplicate job detected.");
		return;	
	}	

	try{
        	$this->jobList->add(DeferredRenameQueueJob::class, $argument);
		$this->logger->info('RenameUploads: Successfully dispatched DeferredRenameQueueJob for fileId: ' . $fileId . ' (Path: ' . $path . ')', ['app' => 'renameuploads']);
	}catch (\Throwable $e) {
		$this->logger->error(
                    'RenameUploads: Failed to add DeferredRenameQueueJob for fileId: ' . $fileId . ' (Path: ' . $path . '). Error: ' . $e->getMessage(),
                    [
                        'app' => 'renameuploads',
                        'exception' => $e
                    ]
                );
	}

    }
}
