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

namespace OCA\FilesExternalScript\AppInfo;

use OCA\FilesExternalScript\Operation;
use OCP\AppFramework\QueryException;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\ILogger;

class Application extends \OCP\AppFramework\App {

	/**
	 * Application constructor.
	 */
	public function __construct() {
		parent::__construct('files_external_script');
	}

	protected function handleEvent(Node $node, string $eventName, array $extra = []) {
		// '', admin, 'files', 'path/to/file.txt'
		list(,, $folder,) = explode('/', $node->getPath(), 4);
		if($folder !== 'files' || $node instanceof Folder) {
			return;
		}

		try {
			$operation = $this->getContainer()->query(Operation::class);
			/** @var Operation $operation */
			$operation->considerScript($node, $eventName, $extra);
		} catch (QueryException $e) {
			$logger = $this->getContainer()->getServer()->getLogger();
			$logger->logException($e, ['app' => 'files_external_script', 'level' => ILogger::ERROR]);
		}
	}

	public function onCreate(Node $node) {
		$this->handleEvent($node, 'create');
	}

	public function onUpdate(Node $node) {
		$this->handleEvent($node, 'update');
	}

	public function onRename(Node $oldNode, Node $node) {
		$this->handleEvent($node, 'rename', ['oldFilePath' => $oldNode->getPath()]);
	}

	/**
	 * Register the app to several events
	 */
	public function registerHooksAndListeners() {
		$root = $this->getContainer()->getServer()->getRootFolder();
		$root->listen('\OC\Files', 'postCreate', [$this, 'onCreate']);
		$root->listen('\OC\Files', 'postWrite', [$this, 'onUpdate']);
		$root->listen('\OC\Files', 'postRename', [$this, 'onRename']);
	}

}
