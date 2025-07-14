<?php
declare(strict_types=1);

namespace OCA\RenameUploads\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITags;
use OCP\ITagManager;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\Mount\IMountManager;
use OCP\IDBConnection;

use OCA\RenameUploads\Listener\FileCreatedListener;
use OCA\RenameUploads\BackgroundJobs\RenameFileJob;
use OCA\RenameUploads\BackgroundJobs\DeferredRenameQueueJob; // <-- Import the new job class
use OCA\RenameUploads\Service\RenameService;
use OCA\RenameUploads\Service\TagCleanupService;


class Application extends App implements IBootstrap {
    public const APP_ID = 'renameuploads';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $container = $this->getContainer();

        // Register the DeferredRenameQueueJob service
        $container->registerService('DeferredRenameQueueJob', function (IAppContainer $c) {
            return new DeferredRenameQueueJob(
                $c->get(ITimeFactory::class),
                $c->get(IJobList::class),
                $c->get(LoggerInterface::class),
                $c->get(IRootFolder::class),
                $c->get(ITags::class)
            );
        });

        // Register the RenameFileJob service (updated constructor)
        $container->registerService('RenameUploadsJob', function (IAppContainer $c) {
            return new RenameFileJob(
                $c->get(IRootFolder::class),
                $c->get(LoggerInterface::class),
                $c->get(ILockingProvider::class),
                $c->get(IJobList::class) // Pass IJobList, removed IDBConnection
            );
        });

        $container->registerService(RenameService::class, function ($c) {
                return new RenameService(
			$c->get(LoggerInterface::class),
			$c->get(ISystemTagManager::class),
			$c->get(ISystemTagObjectMapper::class),
        		$c->get(IUserSession::class),
			$c->get(IUserManager::class),
			$c->get(TagCleanupService::class)
		);
        });

	$container->registerService(TagCleanupService::class, function ($c) {                   
                return new TagCleanupService(                                                   
                        $c->get(ISystemTagObjectMapper::class),                                    
                        $c->get(IDBConnection::class),                                 
                        $c->get(LoggerInterface::class)                             
                );                                                              
        });

        // Register the event listener. Nextcloud will automatically inject
        // dependencies into FileCreatedListener's constructor if it's type-hinted.
        $context->registerEventListener(
            NodeWrittenEvent::class,
            FileCreatedListener::class
        );
    }

    public function boot(IBootContext $context): void {
        // Any boot logic for your app
    }
}
