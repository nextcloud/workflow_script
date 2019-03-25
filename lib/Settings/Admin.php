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

namespace OCA\WorkflowScript\Settings;

use OCA\WorkflowScript\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\Util;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Admin implements ISettings {
	/** @var IL10N */
	private $l10n;

	/** @var Application */
	private $app;

	/** @var EventDispatcherInterface */
	private $eventDispatcher;

	public function __construct(IL10N $l10n, Application $app, EventDispatcherInterface $eventDispatcher) {
		$this->l10n = $l10n;
		$this->app = $app;
		$this->eventDispatcher = $eventDispatcher;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm() {
		$appName = $this->app->getContainer()->getAppName();
		$this->eventDispatcher->dispatch('OCP\WorkflowEngine::loadAdditionalSettingScripts');
		Util::addScript($appName, 'admin');
		$parameters = [
			'appid' => $appName,
			'docs' => '',
			'heading' => $this->l10n->t('External scripts'),
			'settings-hint' => $this->l10n->t('Pass files to external scripts for processing outside of Nextcloud'),
			'description' => $this->l10n->t('Each rule group consists of one or more rules. A request matches a group if all rules evaluate to true. When a file is created, written, deleted or renamed, the file is provided to the external script. If you do not use any of the following placeholders, the file path will be added to the provided command. Eventually the scripts are launched by a background job, but Nextcloud will not keep track of them or wait for their execution to finish.'),
		];

		return new TemplateResponse('workflowengine', 'admin', $parameters, 'blank');
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection() {
		return 'workflow_script';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the admin section. The forms are arranged in ascending order of the
	 * priority values. It is required to return a value between 0 and 100.
	 *
	 * E.g.: 70
	 */
	public function getPriority() {
		return 40;
	}
}
