<?php
declare(strict_types=1);

namespace OCA\RenameUploads\Service;

use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class TagCleanupService {
    private ISystemTagObjectMapper $tagMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        ISystemTagObjectMapper $tagMapper,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->tagMapper = $tagMapper;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Attempt to remove a system tag from a file using the official API,
     * then fall back to direct DB deletion if needed.
     *
     * @param int $fileId The file's internal numeric ID
     * @param int $tagId The system tag ID to remove
     */
    public function forceRemoveSystemTagFromFile(int $fileId, int $tagId): void {
        try {
            $this->logger->info("Trying to unassign tag ID $tagId from file ID $fileId (cleanly)");

            // Try removing via system tag API
            $this->tagMapper->unassignTags('files', (string)$fileId, [$tagId]);

            // Check if tag still assigned
            $remainingTags = $this->tagMapper->getTagIdsForObjects([$fileId], 'files')[$fileId] ?? [];

            if (in_array($tagId, $remainingTags, true)) {
                $this->logger->warning("Standard unassign failed. Falling back to DB-level tag removal for file ID $fileId, tag ID $tagId");
                $this->forceDeleteTagAssignmentFromDB($fileId, $tagId);
            } else {
                $this->logger->info("Tag successfully removed via standard API.");
            }
        } catch (\Throwable $e) {
            $this->logger->error("Tag removal error: " . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Remove a system tag assignment from the database directly.
     *
     * @param int $fileId
     * @param int $tagId
     */
    private function forceDeleteTagAssignmentFromDB(int $fileId, int $tagId): void {
	$objectType = 'files';
    	$objectId = (string)$fileId;
    	$systemTagId = (string)$tagId;

	$prefix = \OC::$server->getConfig()->getSystemValue('dbtableprefix', 'oc_');
    	$table = '`' . $prefix . 'systemtag_object_mapping`';

        $sql = "DELETE FROM $table WHERE objecttype = ? AND objectid = ? AND systemtagid = ?";
        $count = $this->db->executeStatement($sql, ['files', $objectId, $systemTagId]);

        $this->logger->info("Direct DB delete of tag ID $tagId for file ID $fileId complete. Rows affected: $count");
    }
}
