<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Approval\Events {
	/**
	 * Stub of the event emitted by the approval app (since its version
	 * shipping OCA\Approval\Events\ApprovalStateChangedEvent). The approval
	 * app is an optional integration, so its classes are not available to
	 * the autoloader; this stub only exists for static analysis.
	 */
	class ApprovalStateChangedEvent extends \OCP\EventDispatcher\Event {
		public function __construct(
			private int $fileId,
			private int $ruleId,
			private int $newState,
			private ?string $actorUserId,
			private ?string $requesterUserId,
		) {
		}

		public function getFileId(): int {
			return $this->fileId;
		}

		public function getRuleId(): int {
			return $this->ruleId;
		}

		public function getNewState(): int {
			return $this->newState;
		}

		public function getActorUserId(): ?string {
			return $this->actorUserId;
		}

		public function getRequesterUserId(): ?string {
			return $this->requesterUserId;
		}
	}
}
