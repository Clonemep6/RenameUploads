<?php
declare(strict_types=1);

namespace OCA\RenameUploads\BackgroundJobs;

use OCP\BackgroundJob\Job;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use OCA\RenameUploads\Service\RenameService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;

class RenameFileJob extends Job {

    private IRootFolder $rootFolder;
    private LoggerInterface $logger;
    private RenameService $renameService;

    public function __construct(
        IRootFolder $rootFolder,
        LoggerInterface $logger,
        RenameService $renameService,
        ITimeFactory $timeFactory
    ) {
        parent::__construct($timeFactory);
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
        $this->renameService = $renameService;
    }

    public function run($argument): void {
        $decodedArgument = json_decode($argument, true);
        if (!is_array($decodedArgument)) {
            $this->logger->warning("RenameUploads: RenameFileJob: Received invalid JSON argument, skipping job.", ['app' => 'renameuploads']);
            return;
        }
        $argument = $decodedArgument;

        $fileId = $argument['fileId'] ?? null;
        $userId = $argument['userId'] ?? null;
        $currentPath = $argument['currentPath'] ?? null;

        if (!$fileId || !$userId || !$currentPath) {
            $this->logger->warning("RenameUploads: RenameFileJob: Missing fileId, userId, or currentPath. Skipping.", ['app' => 'renameuploads']);
            return;
        }

        $this->logger->info("RenameUploads: RenameFileJob: Attempting to rename fileId: {$fileId} for user: {$userId}", ['app' => 'renameuploads']);

        try {
            // This is the critical fix: Get the user's folder first!
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $files = $userFolder->getById($fileId);

            if (empty($files)) {
                $this->logger->warning("RenameUploads: RenameFileJob: File with ID {$fileId} not found in user folder '{$userId}', skipping rename.", ['app' => 'renameuploads']);
                return;
            }

            $file = $files[0];

            if (!$file instanceof \OCP\Files\File) {
                 $this->logger->warning("RenameUploads: RenameFileJob: Node with ID {$fileId} is not a file, skipping rename.", ['app' => 'renameuploads']);
                return;
            }

            $this->renameService->renameUploadedFile($file, $userId);
	    
	    /** @var IJobList $jobList */
  	    $jobList = \OC::$server->get(IJobList::class);
	    $jobList->remove(self::class, json_encode($argument));

        } catch (NotFoundException $e) {
            $this->logger->warning("RenameUploads: RenameFileJob: User folder or file not found during rename for fileId {$fileId} (Path: {$currentPath}): " . $e->getMessage(), ['app' => 'renameuploads', 'exception' => $e]);
        } catch (\Exception $e) {
            $this->logger->error("RenameUploads: RenameFileJob: Error renaming fileId {$fileId} (Path: {$currentPath}): " . $e->getMessage(), ['app' => 'renameuploads', 'exception' => $e]);
        }
    }
}
