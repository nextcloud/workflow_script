<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WorkflowScript\Exception;

use Exception;
use Throwable;

class PlaceholderNotSubstituted extends Exception {
	private string $placeholder;

	public function __construct(string $placeholder, ?Throwable $previous = null) {
		parent::__construct('', 0, $previous);
		$this->placeholder = $placeholder;
	}

	public function getPlaceholder(): string {
		return $this->placeholder;
	}
}
