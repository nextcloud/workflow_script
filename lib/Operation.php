<?php
/**
 * @copyright Copyright (c) 2018 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\WorkflowScript;

use Exception;
use InvalidArgumentException;
use OC;
use OC\Files\View;
use OC\User\NoUserException;
use OCA\Files_Sharing\SharedStorage;
use OCA\GroupFolders\Mount\GroupFolderStorage;
use OCA\WorkflowEngine\Entity\File;
use OCA\WorkflowScript\BackgroundJobs\Launcher;
use OCA\WorkflowScript\Exception\PlaceholderNotSubstituted;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\GenericEvent as LegacyGenericEvent;
use UnexpectedValueException;

class Operation implements ISpecificOperation {

	/** @var IManager */
	private $workflowEngineManager;
	/** @var IJobList */
	private $jobList;
	/** @var IL10N */
	private $l;
	/** @var IUserSession */
	private $session;
	/** @var IRootFolder */
	private $rootFolder;
	/** @var IConfig */
	private $config;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		IManager $workflowEngineManager,
		IJobList $jobList,
		IL10N $l,
		IUserSession $session,
		IRootFolder $rootFolder,
		IConfig $config,
		LoggerInterface $logger
	) {
		$this->workflowEngineManager = $workflowEngineManager;
		$this->jobList = $jobList;
		$this->l = $l;
		$this->session = $session;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * @throws UnexpectedValueException
	 * @since 9.1
	 */
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

	public function getDisplayName(): string {
		return $this->l->t('Run script');
	}

	public function getDescription(): string {
		return $this->l->t('Pass files to external scripts for processing outside of Nextcloud');
	}

	public function getIcon(): string {
		return OC::$server->getURLGenerator()->imagePath('workflow_script', 'app.svg');
	}

	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		if (!$event instanceof GenericEvent
			&& !$event instanceof LegacyGenericEvent
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
				$nodes = $this->rootFolder->getById($event->getObjectId());
				if (!isset($nodes[0])) {
					return;
				}
				$node = $nodes[0];
				unset($nodes);
			} else {
				$node = $event->getSubject();
			}
			/** @var Node $node */

			// '', admin, 'files', 'path/to/file.txt'
			[, , $folder,] = explode('/', $node->getPath(), 4);
			if ($folder !== 'files' || $node instanceof Folder) {
				return;
			}

			$matches = $ruleMatcher->getFlows(false);
			foreach ($matches as $match) {
				try {
					$command = $this->buildCommand($match['operation'], $node, $eventName, $extra);
				} catch (PlaceholderNotSubstituted $e) {
					$this->logger->warning(
						'Could not substitute {placeholder} in {command} with node {node}',
						[
							'app' => 'workflow_script',
							'placeholder' => $e->getPlaceholder(),
							'command' => $match['operation'],
							'node' => $node,
							'exception' => $e,
						]
					);
				}
				$args = ['command' => $command];
				if (strpos($command, '%f')) {
					$args['path'] = $node->getPath();
				}
				$this->jobList->add(Launcher::class, $args);
			}
		} catch (NotFoundException $e) {
		}
	}

	/**
	 * @throws PlaceholderNotSubstituted
	 */
	protected function buildCommand(string $template, Node $node, string $event, array $extra = []) {
		$command = $template;

		if (strpos($command, '%e')) {
			$command = str_replace('%e', escapeshellarg($event), $command);
		}

		if (strpos($command, '%n')) {
			// Nextcloud relative-path
			$ncRelPath = $this->replacePlaceholderN($node);
			$command = str_replace('%n', escapeshellarg($ncRelPath), $command);
			unset($ncRelPath);
		}

		if (strpos($command, '%f')) {
			try {
				$view = new View();
				if ($node instanceof Folder) {
					$fullPath = $view->getLocalFolder($node->getPath());
				} else {
					$fullPath = $view->getLocalFile($node->getPath());
				}
				if ($fullPath === null) {
					throw new InvalidArgumentException();
				}
				$command = str_replace('%f', escapeshellarg($fullPath), $command);
			} catch (Exception $e) {
				throw new InvalidArgumentException('Could not determine full path');
			}
		}

		if (strpos($command, '%i')) {
			$nodeID = -1;
			try {
				$nodeID = $node->getId();
			} catch (InvalidPathException | NotFoundException $e) {
			}
			$command = str_replace('%i', escapeshellarg($nodeID), $command);
		}

		if (strpos($command, '%a')) {
			$user = $this->session->getUser();
			$userID = '';
			if ($user instanceof IUser) {
				$userID = $user->getUID();
			}
			$command = str_replace('%a', escapeshellarg($userID), $command);
		}

		if (strpos($command, '%o')) {
			$user = $node->getOwner();
			$userID = '';
			if ($user instanceof IUser) {
				$userID = $user->getUID();
			}
			$command = str_replace('%o', escapeshellarg($userID), $command);
		}

		if (strpos($command, '%x')) {
			if (!isset($extra['oldFilePath'])) {
				$extra['oldFilePath'] = '';
			}
			$command = str_replace('%x', escapeshellarg($extra['oldFilePath']), $command);
		}

		return $command;
	}

	/**
	 * @throws PlaceholderNotSubstituted
	 */
	protected function replacePlaceholderN(Node $node): string {
		$owner = $node->getOwner();
		try {
			$nodeID = $node->getId();
			$storage = $node->getStorage();
		} catch (NotFoundException | InvalidPathException $e) {
			$context = [
				'app' => 'workflow_script',
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
			$absPath = $storage->getLocalFile($node->getPath());
			$pos = strpos($absPath, '/__groupfolders/');
			// if the string cannot be found, the fallback is absolute path
			// it should never happen #famousLastWords
			if ($pos === false) {
				$this->logger->warning(
					'Groupfolder path does not contain __groupfolders. File ID: {fid}, Node path: {path}, absolute path: {abspath}',
					[
						'app' => 'workflow_script',
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
			} catch (NotPermittedException | NoUserException $e) {
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
			if (strpos($node->getPath(), $owner->getUID()) !== 0) {
				$nodes = $this->rootFolder->getById($nodeID);
				foreach ($nodes as $testNode) {
					if (strpos($node->getPath(), $owner->getUID()) === 0) {
						$ncRelPath = $testNode;
						break;
					}
				}
			}
		}
		$ncRelPath = ltrim($ncRelPath, '/');

		return $ncRelPath;
	}

	public function getEntityId(): string {
		return File::class;
	}
}
