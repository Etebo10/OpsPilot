<?php

declare(strict_types=1);

function flash(
	string $type,
	string $message
): void {
	$_SESSION['_flash'] = [
		'type' => $type,
		'message' => $message
	];
}


function get_flash(): ?array
{
	$flash = $_SESSION['_flash'] ?? null;

	unset($_SESSION['_flash']);

	if (!is_array($flash)) {
		return null;
	}

	return [
		'type' => is_string($flash['type'] ?? null)
			? $flash['type']
			: 'success',
		'message' => is_string($flash['message'] ?? null)
			? $flash['message']
			: ''
	];
}
