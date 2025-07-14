<?php
declare(strict_types=1);

namespace OCA\RenameUploads\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\IFile;
use OCP\Files\IRootFolder;

use OCA\RenameUploads\Service\TagCleanupService;

class RenameService {

    private LoggerInterface $logger;
    private ISystemTagManager $tagManager; // Keep for constructor injection if desired, but we'll re-fetch
    private ISystemTagObjectMapper $tagMapper; // Keep for constructor injection if desired, but we'll re-fetch
    private ?\OCP\IUser $user;
    private IUserManager $userManager;
    private TagCleanupService $tagCleanupService;

    public function __construct(
        LoggerInterface $logger,
        ISystemTagManager $tagManager,
        ISystemTagObjectMapper $tagMapper,
        IUserSession $userSession,
        IUserManager $userManager,
	TagCleanupService $tagCleanupService
    ) {
        $this->logger = $logger;
        // Store for initial checks or if not re-fetching
        $this->tagManager = $tagManager; 
        $this->tagMapper = $tagMapper;
        $this->user = $userSession->getUser();
        $this->userManager = $userManager;
        $this->tagCleanupService = $tagCleanupService;
    }

    public function renameUploadedFile(File $file, string $userId): void {
	date_default_timezone_set('America/Denver');

        $this->user = $this->userManager->get($userId);
        if (!$this->user) {
            $this->logger->error("RenameService: Could not find user with ID '{$userId}'. Aborting rename.", ['app' => 'renameuploads']);
            return;
        }

        // --- Important: Setup FS first ---
        \OC_Util::tearDownFS(); // Ensure clean slate
        \OC_Util::setupFS($userId); // Set up FS for the specific user

        // --- Re-fetch Tag Manager and Mapper AFTER setupFS ---
        // This is crucial to ensure they operate within the correct user context
        /** @var ISystemTagManager $currentTagManager */
        $currentTagManager = \OC::$server->get(ISystemTagManager::class);
        /** @var ISystemTagObjectMapper $currentTagMapper */
        $currentTagMapper = \OC::$server->get(ISystemTagObjectMapper::class);

        if (!$currentTagManager instanceof ISystemTagManager || !$currentTagMapper instanceof ISystemTagObjectMapper) {
            $this->logger->error("RenameService: Failed to re-obtain Tag Manager or Mapper from server container after FS setup. Aborting.", ['app' => 'renameuploads']);
            \OC_Util::tearDownFS();
            return;
        }

        $originalName = $file->getName();
        $originalFileId = $file->getId();

        try {
            // --- Step 1: Preliminary checks ---
            $originalNameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);

	    if (strpos($originalNameWithoutExt, 'IMG_') === 0 || strpos($originalNameWithoutExt, 'MVI_') === 0) {
    		$cleanName = substr($originalNameWithoutExt, 4); // Remove first 4 characters
	    } else {
    		$cleanName = $originalNameWithoutExt;
	    }

            if (preg_match('/^\d{2}-\d{2}-\d{2} \d{2}-\d{2}-\d{2}/', $originalNameWithoutExt)) {
                $this->logger->info("RenameService: File '{$originalName}' already appears to have a timestamp prefix. Skipping rename.", ['app' => 'renameuploads']);
                return;
            }
            
            $needsRenameTagId = null;
            // Use currentTagMapper for initial check
            $tagIdsMap = $currentTagMapper->getTagIdsForObjects([(int)$originalFileId], 'files');
            $tagIds = $tagIdsMap[$originalFileId] ?? [];
            if (!empty($tagIds)) {
                // Use currentTagManager for initial check
                $tags = $currentTagManager->getTagsByIds($tagIds);
                foreach ($tags as $tag) {
                    if ($tag->getName() === 'needs_rename') {
                        $needsRenameTagId = $tag->getId();
                        $this->logger->info("RenameService: Found 'needs_rename' tag (ID: {$needsRenameTagId}) on original file {$originalFileId}.", ['app' => 'renameuploads']);
                        break;
                    }
                }
            }
            
            if ($needsRenameTagId === null) {
                $this->logger->info("RenameService: File '{$originalName}' (ID: {$originalFileId}) does not have the 'needs_rename' tag. Skipping rename process.", ['app' => 'renameuploads']);
                return;
            }

            // --- Step 2: Proceed with the file renaming logic ---
            $this->logger->info("RenameService: Starting rename process for '{$originalName}'.", ['app' => 'renameuploads']);

            $parent = $file->getParent();
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            $timestamp = $this->getTimestampForFile($file);
            $dateSource = $timestamp['source'];
            $datePart = date('y-m-d H-i-s', $timestamp['time']);

            $newNameBase = $datePart . ' ' . $cleanName;
            $newName = empty($extension) ? $newNameBase : $newNameBase . '.' . $extension;

            $counter = 1;
            $destinationPath = $parent->getPath() . '/' . $newName;

            while ($parent->nodeExists(pathinfo($destinationPath, PATHINFO_BASENAME))) {
                $newName = empty($extension) ? $newNameBase . '_' . $counter : $newNameBase . '_' . $counter . '.' . $extension;
                $destinationPath = $parent->getPath() . '/' . $newName;
                $counter++;
            }
            
            $file->move($destinationPath); 

            $this->logger->info("RenameService: Renamed file '{$originalName}' to '{$newName}' (Date from: {$dateSource})", ['app' => 'renameuploads']);

            // --- Step 3: Get the NEW file ID after the move using the absolute path from IRootFolder ---
            /** @var \OCP\Files\IRootFolder $rootFolder */
            $rootFolder = \OC::$server->get(\OCP\Files\IRootFolder::class);

            if ($rootFolder === null) {
                $this->logger->error("RenameService: Could not get the IRootFolder service from OC::\$server. Aborting tag removal.", ['app' => 'renameuploads']);
                return;
            }
            
            $newFileNode = $rootFolder->get($destinationPath); 

            $this->logger->info("RenameService: Debugging newFileNode from rootFolder. Type: " . gettype($newFileNode), ['app' => 'renameuploads']);
            if (is_object($newFileNode)) {
                $this->logger->info("RenameService: newFileNode class from rootFolder: " . get_class($newFileNode), ['app' => 'renameuploads']);
            }
            
            // Removed the problematic 'instanceof IFile' check.
            $newFileId = (string)$newFileNode->getId();

            // --- Step 4: Remove the tag from the file's NEW ID ---
            if ($needsRenameTagId !== null) {
                $this->logger->info("Calling unassignTags with: objectType='files', objectId='{$newFileId}', tagIds=[{$needsRenameTagId}]", ['app' => 'renameuploads']);
                
                // USE THE RE-FETCHED currentTagMapper HERE
                $this->tagCleanupService->forceRemoveSystemTagFromFile((int)$newFileId, (int)$needsRenameTagId);
                $this->logger->info("unassignTags call executed for new file ID.", ['app' => 'renameuploads']);

                // Verify removal by re-querying tags using re-fetched tagMapper
                $remainingTags = $currentTagMapper->getTagIdsForObjects([(int)$newFileId], 'files')[$newFileId] ?? [];
                if (in_array($needsRenameTagId, $remainingTags)) {
                    $this->logger->warning("RenameService: 'needs_rename' tag still present on NEW file {$newFileId} after attempted removal. This is unexpected.", ['app' => 'renameuploads']);
                } else {
                    $this->logger->info("RenameService: Confirmed 'needs_rename' tag was removed from NEW file {$newFileId}.", ['app' => 'renameuploads']);
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error("RenameService: FATAL ERROR during file rename or tag removal for '{$originalName}': " . $e->getMessage(), [
                'app' => 'renameuploads',
                'exception' => $e->getTraceAsString(),
                'file_id_at_error' => $originalFileId
            ]);
        } finally {
            // Always tear down FS
            \OC_Util::tearDownFS();
        }
    }

    private function getTimestampForFile(File $file): array {
        $timestamp = $file->getMTime();
        $dateSource = 'ModificationTime';

        if (function_exists('exif_read_data')) {
            try {
                if ($localFile = $file->getStorage()->getLocalFile($file->getInternalPath())) {
                    $exif = @exif_read_data($localFile);
                    if ($exif && isset($exif['DateTimeOriginal'])) {
                        $timestamp = strtotime($exif['DateTimeOriginal']);
                        $dateSource = 'EXIF';
                    }
                }
            } catch (\Exception $e) {
                $this->logger->info("RenameService: Could not get local file for EXIF reading: " . $e->getMessage(), ['app' => 'renameuploads']);
            }
        }
        return ['time' => $timestamp, 'source' => $dateSource];
    }
    // The unused removeRenameTag method has been omitted as per previous discussions.
}
