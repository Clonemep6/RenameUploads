<?php

declare(strict_types=1);

namespace OCA\RenameUploads\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->logger = $logger;
	}

	/**
	 * An example API endpoint
	 *
	 * @return DataResponse<Http::STATUS_OK, array{message: string}, array{}>
	 *
	 * 200: Data returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api')]
	public function index(): DataResponse {
		$this->logger->debug('ApiController@index was called', ['app' => 'renameuploads']);
		return new DataResponse(['message' => 'Hello world!']);
	}
}
