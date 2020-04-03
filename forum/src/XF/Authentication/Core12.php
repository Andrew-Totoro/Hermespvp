<?php

namespace XF\Authentication;

class Core12 extends AbstractAuth
{
	protected function getDefaultOptions()
	{
		// TODO: Consider switching to PASSWORD_ARGON2I at a later date.

		return [
			'algo' => defined('PASSWORD_BCRYPT') ? PASSWORD_BCRYPT : null,
			'options' => [
				'cost' => \XF::config('passwordIterations')
			]
		];
	}

	protected function getHandler()
	{
		return new PasswordHash(\XF::config('passwordIterations'), false);
	}

	public function generate($password)
	{
		if (function_exists('password_hash'))
		{
			$options = $this->getDefaultOptions();

			$hash = password_hash($password, $options['algo'], $options['options']);
		}
		else
		{
			$hash = $this->getHandler()->HashPassword($password);
		}

		return [
			'hash' => $hash
		];
	}

	public function authenticate($userId, $password)
	{
		if (!is_string($password) || $password === '' || empty($this->data))
		{
			return false;
		}

		if (!preg_match('/^(?:\$(P|H)\$|[^\$])/i',  $this->data['hash'])
			&& function_exists('password_verify')
		)
		{
			return password_verify($password, $this->data['hash']);
		}
		else
		{
			return $this->getHandler()->CheckPassword($password, $this->data['hash']);
		}
	}

	public function isUpgradable()
	{
		if (!empty($this->data['hash']))
		{
			$hash = $this->data['hash'];

			$options = $this->getDefaultOptions();
			if (!preg_match('/^(?:\$(P|H)\$|[^\$])/i',  $hash)
				&& function_exists('password_needs_rehash')
			)
			{
				return password_needs_rehash($hash, $options['algo'], $options['options']);
			}

			$passwordHash = $this->getHandler();
			$expectedIterations = min(intval($options['options']['cost']), 30);
			$iterations = null;

			if (preg_match('/^\$(P|H)\$(.)/i',  $hash, $match))
			{
				$iterations = $passwordHash->reverseItoA64($match[2]) - 5; // 5 iterations removed in PHP 5
			}
			else if (preg_match('/^\$2(?:a|y)\$(\d+)\$.*$/i', $hash, $match))
			{
				$iterations = intval($match[1]);
			}

			return $expectedIterations !== $iterations;
		}

		return true;
	}

	public function getAuthenticationName()
	{
		return 'XF:Core12';
	}
}