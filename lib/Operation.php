<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WorkflowScript;

use OC\User\NoUserException;
use OCA\Files_Sharing\SharedStorage;
use OCA\GroupFolders\Mount\GroupFolderStorage;
use OCA\WorkflowEngine\Entity\File;
use OCA\WorkflowScript\AppInfo\Application;
use OCA\WorkflowScript\BackgroundJobs\Launcher;
use OCA\WorkflowScript\Exception\PlaceholderNotSubstituted;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File as FileNode;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @psalm-api
 */
class Operation implements ISpecificOperation {

	public function __construct(
		private IJobList $jobList,
		private IL10N $l,
		private IUserSession $session,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private IURLGenerator $urlGenerator,
		private RequesterContext $requesterContext,
	) {
	}

	/**
	 * @throws UnexpectedValueException
	 * @since 9.1
	 */
	#[\Override]
	public function validateOperation(string $name, array $checks, string $operation): void {
		if (empty($operation)) {
			throw new UnexpectedValueException($this->l->t('Please provide a script name'));
		}

		$scriptName = explode(' ', $operation, 2)[0];
		if (!$this->isScriptValid($scriptName)) {
			throw new UnexpectedValueException($this->l->t('The script does not seem to be executable'));
		}
	}

	protected function isScriptValid(string $scriptName): bool {
		$which = shell_exec('command -v ' . escapeshellarg($scriptName));
		if (!empty($which)) {
			return true;
		}

		return is_executable($scriptName);
	}

	#[\Override]
	public function getDisplayName(): string {
		return $this->l->t('Run script');
	}

	#[\Override]
	public function getDescription(): string {
		return $this->l->t('Pass files to external scripts for processing outside of Nextcloud');
	}

	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APPID, 'app.svg');
	}

	#[\Override]
	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}

	#[\Override]
	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		if (!$event instanceof GenericEvent
			&& !$event instanceof MapperEvent) {
			return;
		}
		try {
			$extra = [];
			if ($eventName === '\OCP\Files::postRename' || $eventName === '\OCP\Files::postCopy') {
				/** @var Node $oldNode */
				[$oldNode, $node] = $event->getSubject();
				$extra = ['oldFilePath' => $oldNode->getPath()];
			} elseif ($event instanceof MapperEvent) {
				if ($event->getObjectType() !== 'files') {
					return;
				}
				$node = $this->rootFolder->getFirstNodeById((int)$event->getObjectId());
				if ($node === null) {
					return;
				}
			} else {
				$node = $event->getSubject();
			}
			/** @var Node $node */

			// '', admin, 'files', 'path/to/file.txt'
			[, , $folder,] = explode('/', $node->getPath(), 4);
			if ($folder !== 'files' || !($node instanceof FileNode)) {
				return;
			}

			$matches = $ruleMatcher->getFlows(false);
			if (empty($matches)) {
				return;
			}

			if (preg_match('/\$\(|`/', $node->getName())) {
				$this->logger->warning(
					'Potentially dangerous characters in filename, skipping workflow',
					[
						'app' => Application::APPID,
						'file' => $node->getPath(),
					]
				);
				return;
			}

			foreach ($matches as $match) {
				$template = $match['operation'];
				try {
					$replacements = $this->buildReplacements($template, $node, $eventName, $extra);
				} catch (PlaceholderNotSubstituted $e) {
					$this->logger->warning(
						'Could not substitute {placeholder} in {command} with node {node}',
						[
							'app' => Application::APPID,
							'placeholder' => $e->getPlaceholder(),
							'command' => $template,
							'node' => $node,
							'exception' => $e,
						]
					);
					continue;
				}
				$args = [
					'template' => $template,
					'replacements' => $replacements,
				];
				if (strpos($template, '%f') !== false) {
					$args['path'] = $node->getPath();
				}
				$this->jobList->add(Launcher::class, $args);
			}
		} catch (NotFoundException) {
		}
	}

	/**
	 * @return array<string, string>
	 * @throws PlaceholderNotSubstituted
	 */
	protected function buildReplacements(string $template, Node $node, string $event, array $extra = []): array {
		$replacements = [];

		if (strpos($template, '%e') !== false) {
			$replacements['%e'] = escapeshellarg($event);
		}

		if (strpos($template, '%n') !== false) {
			$replacements['%n'] = escapeshellarg($this->replacePlaceholderN($node));
		}

		if (strpos($template, '%i') !== false) {
			$nodeID = -1;
			try {
				$nodeID = $node->getId();
			} catch (InvalidPathException|NotFoundException) {
			}
			$replacements['%i'] = escapeshellarg((string)$nodeID);
		}

		if (strpos($template, '%a') !== false) {
			$user = $this->session->getUser();
			$userID = '';
			if ($user instanceof IUser) {
				$userID = $user->getUID();
			}
			$replacements['%a'] = escapeshellarg($userID);
		}

		if (strpos($template, '%o') !== false) {
			$user = $node->getOwner();
			$userID = '';
			if ($user instanceof IUser) {
				$userID = $user->getUID();
			}
			$replacements['%o'] = escapeshellarg($userID);
		}

		if (strpos($template, '%r') !== false) {
			// filled by ApprovalStateChangedListener when the flow was
			// triggered by an approval state change of the approval app
			$requesterID = null;
			try {
				$requesterID = $this->requesterContext->getRequester($node->getId());
			} catch (InvalidPathException|NotFoundException) {
			}
			if ($requesterID === null) {
				throw new PlaceholderNotSubstituted('r');
			}
			$replacements['%r'] = escapeshellarg($requesterID);
		}

		if (strpos($template, '%x') !== false) {
			$replacements['%x'] = escapeshellarg($extra['oldFilePath'] ?? '');
		}

		return $replacements;
	}

	/**
	 * @throws PlaceholderNotSubstituted
	 */
	protected function replacePlaceholderN(Node $node): string {
		$owner = $node->getOwner();
		try {
			$nodeID = $node->getId();
			$storage = $node->getStorage();
		} catch (NotFoundException|InvalidPathException $e) {
			$context = [
				'app' => Application::APPID,
				'exception' => $e,
				'node' => $node,
			];
			$message = 'Could not get if of node {node}';
			if (isset($nodeID)) {
				$message = 'Could not find storage for file ID {fid}, node: {node}';
				$context['fid'] = $nodeID;
			}

			$this->logger->warning($message, $context);
			throw new PlaceholderNotSubstituted('n', $e);
		}

		if (isset($storage) && $storage->instanceOfStorage(GroupFolderStorage::class)) {
			// group folders are always located within $DATADIR/__groupfolders/
			$absPath = $storage->getLocalFile($node->getInternalPath());
			$pos = strpos($absPath, '/__groupfolders/');
			// if the string cannot be found, the fallback is absolute path
			// it should never happen #famousLastWords
			if ($pos === false) {
				$this->logger->warning(
					'Groupfolder path does not contain __groupfolders. File ID: {fid}, Node path: {path}, absolute path: {abspath}',
					[
						'app' => Application::APPID,
						'fid' => $nodeID,
						'path' => $node->getPath(),
						'abspath' => $absPath,
					]
				);
			}
			$ncRelPath = substr($absPath, (int)$pos);
		} elseif (isset($storage) && $storage->instanceOfStorage(SharedStorage::class)) {
			try {
				$folder = $this->rootFolder->getUserFolder($owner->getUID());
			} catch (NotPermittedException|NoUserException $e) {
				throw new PlaceholderNotSubstituted('n', $e);
			}
			$nodes = $folder->getById($nodeID);
			if (empty($nodes)) {
				throw new PlaceholderNotSubstituted('n');
			}
			$newNode = array_shift($nodes);
			$ncRelPath = $newNode->getPath();
		} else {
			$ncRelPath = $node->getPath();
			if (!str_starts_with($node->getPath(), $owner->getUID())) {
				$nodes = $this->rootFolder->getById($nodeID);
				foreach ($nodes as $testNode) {
					if (str_starts_with($node->getPath(), $owner->getUID())) {
						$ncRelPath = $testNode;
						break;
					}
				}
			}
		}
		$ncRelPath = ltrim($ncRelPath, '/');

		return $ncRelPath;
	}

	#[\Override]
	public function getEntityId(): string {
		return File::class;
	}
}
