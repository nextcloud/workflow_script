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

namespace OCA\WorkflowScript\BackgroundJobs;

use Exception;
use OC\BackgroundJob\QueuedJob;
use OC\Files\View;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

class Launcher extends QueuedJob {

	/** @var LoggerInterface */
	protected $logger;
	/** @var ITempManager */
	private $tempManager;
	/** @var IRootFolder */
	private $rootFolder;

	/**
	 * BackgroundJob constructor.
	 *
	 * @param LoggerInterface $logger
	 */
	public function __construct(LoggerInterface $logger, ITempManager $tempManager, IRootFolder $rootFolder) {
		$this->logger = $logger;
		$this->tempManager = $tempManager;
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument) {
		$command = (string)$argument['command'];

		if (strpos($command, '%f')) {
			$path = isset($argument['path']) ? (string)$argument['path'] : '';
			try {
				$view = new View(dirname($path));
				$tmpFile = $view->toTmpFile(basename($path));
			} catch (Exception $e) {
				$this->logger->warning($e->getMessage(), [
					'app' => 'workflow_script',
					'exception' => $e
				]);
				return;
			}
			$command = str_replace('%f', escapeshellarg($tmpFile), $command);
		}

		// with wrapping sh around the the command, we leave any redirects in tact,
		// but ensure that the script is not blocking Nextcloud's execution
		$wrapper = 'sh -c ' . escapeshellarg($command) . ' >/dev/null &';
		$this->logger->info(
			'executing {script}',
			['app' => 'workflow_script', 'script' => $wrapper]
		);
		shell_exec($wrapper);
	}
}
