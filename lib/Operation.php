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

use OC\Files\Node\File;
use OC\Files\Node\Folder;
use OCA\WorkflowScript\BackgroundJobs\Launcher;
use OCP\BackgroundJob\IJobList;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IOperation;

class Operation implements IOperation {

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

	public function __construct(IManager $workflowEngineManager, IJobList $jobList, IL10N $l, IUserSession $session, IRootFolder $rootFolder) {
		$this->workflowEngineManager = $workflowEngineManager;
		$this->jobList = $jobList;
		$this->l = $l;
		$this->session = $session;
		$this->rootFolder = $rootFolder;
	}

	public function considerScript(Node $node, string $event, array $extra = []) {
		try {
			$this->workflowEngineManager->setFileInfo($node->getStorage(), $node->getInternalPath());
			$matches = $this->workflowEngineManager->getMatchingOperations(Operation::class, false);
			foreach ($matches as $match) {
				$command = $this->buildCommand($match['operation'], $node, $event, $extra);
				$args = ['command' => $command];
				if (strpos($command, '%f')) {
					$args['path'] = $node->getPath();
				}
				$this->jobList->add(Launcher::class, $args);
			}
		} catch (NotFoundException $e) {
		}
	}

	protected function buildCommand(string $template, Node $node, string $event, array $extra = []) {
		$command = $template;

		if (strpos($command, '%e')) {
			$command = str_replace('%e', escapeshellarg($event), $command);
		}

		if (strpos($command, '%n')) {
			// Nextcloud relative-path
			$command = str_replace('%n', escapeshellarg($node->getPath()), $command);
		}

		if (false && strpos($command, '%f')) {
			try {
				$view = new \OC\Files\View($node->getParent()->getPath());
				if($node instanceof \OCP\Files\Folder) {
					$fullPath = $view->getLocalFolder($node->getPath());
				} else {
					$fullPath = $view->getLocalFile($node->getPath());
				}
				if($fullPath === null) {
					throw new \InvalidArgumentException();
				}
				//$fullPath = $node->getParent()->getFullPath($node->getPath());
				$command = str_replace('%f', escapeshellarg($fullPath), $command);
			} catch (\Exception $e) {
				throw new \InvalidArgumentException('Could not determine full path');
			}
		}

		if (strpos($command, '%i')) {
			$nodeID = -1;
			try {
				$nodeID = $node->getId();
			} catch (InvalidPathException $e) {
			} catch (NotFoundException $e) {
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
	 * @param string $name
	 * @param array[] $checks
	 * @param string $operation
	 * @throws \UnexpectedValueException
	 * @since 9.1
	 */
	public function validateOperation($name, array $checks, $operation) {
		if (empty($operation)) {
			throw new \UnexpectedValueException($this->l->t('Please provide a script name'));
		}

		$scriptName = explode(' ', $operation, 2)[0];
		if (!$this->isScriptValid($scriptName)) {
			throw new \UnexpectedValueException($this->l->t('The script does not seem to be executable'));
		}
	}

	protected function isScriptValid(string $scriptName) {
		$which = shell_exec('command -v ' . escapeshellarg($scriptName));
		if (!empty($which)) {
			return true;
		}

		return is_executable($scriptName);
	}
}
