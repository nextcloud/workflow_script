<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WorkflowScript\Listener;

use OCA\Approval\Events\ApprovalStateChangedEvent;
use OCA\WorkflowScript\RequesterContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Picks up the requester of approval state changes provided by the
 * approval app, so the %r placeholder can be substituted when a flow
 * is triggered by the tag assignment following the state change.
 *
 * The event class belongs to the approval app. The registration happens
 * by class name, so nothing breaks when the approval app is not
 * installed: the event is then simply never emitted.
 *
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class ApprovalStateChangedListener implements IEventListener {
	public function __construct(
		private RequesterContext $requesterContext,
	) {
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof ApprovalStateChangedEvent) {
			return;
		}

		$requesterId = $event->getRequesterUserId();
		if ($requesterId === null || $requesterId === '') {
			return;
		}

		$this->requesterContext->setRequester($event->getFileId(), $requesterId);
	}
}
