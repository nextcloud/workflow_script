<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WorkflowScript;

/**
 * Request-scoped storage for the approval requester of files.
 *
 * The approval app (when installed) emits an ApprovalStateChangedEvent
 * carrying the requester right before it (un)assigns its system tags.
 * The workflow engine reacts to the tag change synchronously within the
 * same request, so the requester is picked up here when substituting
 * the %r placeholder while building the command.
 */
class RequesterContext {
	/** @var array<int, string> */
	private array $requesters = [];

	public function setRequester(int $fileId, string $requesterId): void {
		$this->requesters[$fileId] = $requesterId;
	}

	public function getRequester(int $fileId): ?string {
		return $this->requesters[$fileId] ?? null;
	}
}
