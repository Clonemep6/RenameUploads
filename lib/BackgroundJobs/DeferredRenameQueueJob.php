<?php
declare(strict_types=1);

namespace OCA\RenameUploads\BackgroundJobs;

use OCP\BackgroundJob\Job;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OC\SystemTag\SystemTagManager;
use OC\SystemTag\SystemTagObjectMapper;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

class DeferredRenameQueueJob extends Job {

    public function __construct() {
 	$timeFactory = \OC::$server->query(ITimeFactory::class);
	parent::__construct($timeFactory);

    }

    protected function run($argument): void {
	// --- Acquire all necessary services inside the run() method ---
        /** @var LoggerInterface $logger */
        $logger = \OC::$server->get(LoggerInterface::class);
        /** @var IRootFolder $rootFolder */
        $rootFolder = \OC::$server->get(IRootFolder::class);
        /** @var IJobList $jobList */
        $jobList = \OC::$server->get(IJobList::class);
	/** @var ISystemTagManager $tagManager */
        $tagManager = \OC::$server->get(ISystemTagManager::class);
        /** @var ISystemTagObjectMapper $tagMapper */
        $tagMapper = \OC::$server->get(ISystemTagObjectMapper::class);

        $decodedArgument = json_decode($argument, true);
        $fileId = $decodedArgument['fileId'] ?? null;
        $originalPath = $decodedArgument['path'] ?? null;

        if ($fileId === null || $originalPath === null) {
            return; // Already logged by listener if needed
        }

        $logger->info("RenameUploads: Deferred job running for fileId: {$fileId}. Checking for 'needs_rename' tag.", ['app' => 'renameuploads']);

        $pathParts = explode('/', trim($originalPath, '/'));
        if (count($pathParts) < 3) {
            return; // Invalid path
        }
        $userId = $pathParts[0];

        try {
            $userFolder = $rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);
            
            if (empty($nodes) || !$nodes[0] instanceof File) {
                return; // Not a file or not found
            }
            $fileToRename = $nodes[0];

            // --- THIS IS THE NEW TAG CHECK ---
	    $tagIdsMap = $tagMapper->getTagIdsForObjects([$fileId], 'files');
            $tagIds = $tagIdsMap[$fileId] ?? [];

            if (empty($tagIds)) {
                $logger->debug("RenameUploads: File {$fileId} has no tags. Skipping.", ['app' => 'renameuploads']);
                return;
            }

            // Get tag details
            $tags = $tagManager->getTagsByIds($tagIds);
            $hasRenameTag = false;
            foreach ($tags as $tag) {
                if ($tag->getName() === 'needs_rename') {
                    $hasRenameTag = true;
                    break;
                }
            }

            if (!$hasRenameTag) {
                $logger->debug("RenameUploads: File {$fileId} does not have 'needs_rename' tag. Skipping.", ['app' => 'renameuploads']);
		$jobList->remove(self::class, $argument);
                return;
            }
            // --- END TAG CHECK ---

            $logger->info("RenameUploads: File {$fileId} has 'needs_rename' tag. Queuing final rename job.", ['app' => 'renameuploads']);

            $renameJobArgument = json_encode([
                'fileId' => $fileId,
                'userId' => $userId,
                'currentPath' => $fileToRename->getPath()
            ]);
            
            $jobList->add('OCA\RenameUploads\BackgroundJobs\RenameFileJob', $renameJobArgument);
	    $jobList->remove(self::class, $argument);	
	    
	    $logger->info("RenameUploads: Deferred Job has Sent Request to Start RenameFileJob");
        } catch (NotFoundException $e) {
            $logger->warning("RenameUploads: Deferred job: User folder or file not found for user '{$userId}', fileId {$fileId}. Skipping.", ['app' => 'renameuploads']);
        } catch (\Throwable $e) {
            $logger->error('RenameUploads: Deferred job failed: ' . $e->getMessage(), ['app' => 'renameuploads', 'exception' => $e]);
        }
    }
}
