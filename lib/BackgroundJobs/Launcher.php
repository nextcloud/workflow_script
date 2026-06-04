<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WorkflowScript\BackgroundJobs;

use Exception;
use InvalidArgumentException;
use OC\Files\View;
use OCA\WorkflowScript\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * @psalm-api
 */
class Launcher extends QueuedJob {
	protected LoggerInterface $logger;

	public function __construct(ITimeFactory $time, LoggerInterface $logger) {
		parent::__construct($time);
		$this->logger = $logger;
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
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
