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
use InvalidArgumentException;
use OC\Files\View;
use OCA\WorkflowScript\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class Launcher extends QueuedJob {
	protected LoggerInterface $logger;

	public function __construct(ITimeFactory $time, LoggerInterface $logger) {
		parent::__construct($time);
		$this->logger = $logger;
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$command = (string)$argument['command'];

		if (strpos($command, '%f')) {
			$path = isset($argument['path']) ? (string)$argument['path'] : '';
			try {
				$command = str_replace('%f', escapeshellarg($this->resolveLocalPath($path)), $command);
			} catch (Exception $e) {
				$this->logger->warning($e->getMessage(), [
					'app' => Application::APPID,
					'exception' => $e
				]);
				return;
			}
		}

		// with wrapping sh around the command, we leave any redirects intact,
		// but ensure that the script is not blocking Nextcloud's execution
		$wrapper = 'sh -c ' . escapeshellarg($command) . ' >/dev/null &';
		$this->logger->info(
			'executing {script}',
			['app' => Application::APPID, 'script' => $wrapper]
		);
		shell_exec($wrapper);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function resolveLocalPath(string $path): string {
		try {
			$view = new View();
			$localFile = $view->getLocalFile($path);
			if ($localFile !== false && file_exists($localFile)) {
				return $localFile;
			}
			$tmpFile = $view->toTmpFile($path);
			if ($tmpFile === false) {
				throw new InvalidArgumentException();
			}
			return $tmpFile;
		} catch (Exception) {
			throw new InvalidArgumentException('Could not resolve local path for: ' . $path);
		}
	}
}
