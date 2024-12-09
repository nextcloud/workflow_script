<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WorkflowScript\BackgroundJobs;

use Exception;
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
				$view = new View(dirname($path));
				$tmpFile = $view->toTmpFile(basename($path));
			} catch (Exception $e) {
				$this->logger->warning($e->getMessage(), [
					'app' => Application::APPID,
					'exception' => $e
				]);
				return;
			}
			$command = str_replace('%f', escapeshellarg($tmpFile), $command);
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
}
