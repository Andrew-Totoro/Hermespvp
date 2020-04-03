<?php

namespace XF\Mail;

class Mail
{
	/**
	 * @var Mailer
	 */
	protected $mailer;

	/**
	 * @var \Swift_Message
	 */
	protected $message;

	/**
	 * @var \XF\Language|null
	 */
	protected $language;

	/**
	 * @var \XF\Entity\User|null
	 */
	protected $toUser;

	protected $bounceHmac;
	protected $verpBase;

	protected $templateName;
	protected $templateParams = [];

	/**
	 * @var null|\Exception
	 */
	protected $setupError;

	public function __construct(Mailer $mailer, $templateName = null, array $templateParams = null)
	{
		$this->mailer = $mailer;
		$this->message = \Swift_Message::newInstance();

		if ($templateName)
		{
			$this->templateName = $templateName;
			$this->templateParams = is_array($templateParams) ? $templateParams : [];
		}
	}

	public function setTo($email, $name = null)
	{
		try
		{
			$this->message->setTo($email, $name);
		}
		catch (\Swift_SwiftException $e)
		{
			$this->applySetupError($e);

			return $this;
		}

		$this->bounceHmac = $this->mailer->calculateBounceHmac($email);

		$headers = $this->message->getHeaders();
		if ($headers->has('X-To-Validate'))
		{
			$headers->removeAll('X-To-Validate');
		}
		$headers->addTextHeader('X-To-Validate', $this->bounceHmac . '+' . $email);

		if ($this->verpBase)
		{
			$this->applyVerp();
		}

		$this->toUser = null;

		return $this;
	}

	public function setToUser(\XF\Entity\User $user)
	{
		if (!$user->email)
		{
			$this->setupError = new \Exception("Trying to send email to user without email (ID: $user->user_id)");

			return $this;
		}

		$this->setTo($user->email, $user->username);
		$this->setLanguage(\XF::app()->language($user->language_id));

		$this->toUser = $user;

		return $this;
	}

	public function getToUser()
	{
		return $this->toUser;
	}

	public function setFrom($email, $name = null)
	{
		try
		{
			$this->message->setFrom($email, $name);
		}
		catch (\Swift_SwiftException $e)
		{
			$this->applySetupError($e);
		}

		return $this;
	}

	public function setReplyTo($email, $name = null)
	{
		try
		{
			$this->message->setReplyTo($email, $name);
		}
		catch (\Swift_SwiftException $e)
		{
			$this->applySetupError($e);
		}

		return $this;
	}

	public function setReturnPath($email, $useVerp = false)
	{
		$email = preg_replace('/["\'\s\\\\]/', '', $email);
		$this->message->setReturnPath($email);

		try
		{
			$this->message->setReturnPath($email);
		}
		catch (\Swift_SwiftException $e)
		{
			$this->applySetupError($e);
		}

		if ($useVerp)
		{
			$this->verpBase = $email;
			if ($this->bounceHmac)
			{
				$this->applyVerp();
			}
		}

		return $this;
	}

	protected function applyVerp()
	{
		$toAll = $this->message->getTo();
		if (!$toAll || count($toAll) > 1)
		{
			// 0 or 2+ to addresses, so we can't really do verp
			return $this;
		}

		$to = key($toAll);

		$verpValue = str_replace('@', '=', $to);
		$bounceEmailAddress = str_replace('@', "+{$this->bounceHmac}+$verpValue@", $this->verpBase);
		$bounceEmailAddress = preg_replace('/["\'\s\\\\]/', '', $bounceEmailAddress);

		try
		{
			$this->message->setReturnPath($bounceEmailAddress);
		}
		catch (\Swift_SwiftException $e)
		{
			$this->applySetupError($e);
		}

		return $bounceEmailAddress;
	}

	public function setSender($sender, $name = null)
	{
		try
		{
			$this->message->setSender($sender, $name);
		}
		catch (\Swift_SwiftException $e)
		{
			$this->applySetupError($e);
		}

		return $this;
	}

	public function setId($id)
	{
		try
		{
			$this->message->setId($id);
		}
		catch (\Swift_SwiftException $e)
		{
			$this->applySetupError($e);
		}

		return $this;
	}

	public function addHeader($name, $value)
	{
		$this->message->getHeaders()->addTextHeader($name, $value);

		return $this;
	}

	public function setContent($subject, $htmlBody, $textBody = null)
	{
		if (!$htmlBody && !$textBody)
		{
			throw new \InvalidArgumentException("Must provide at least one of the HTML and text bodies");
		}

		if ($textBody === null)
		{
			$textBody = $this->mailer->generateTextBody($htmlBody);
		}

		$this->message->setSubject($subject);

		if ($textBody && !$htmlBody)
		{
			$this->message->setBody($textBody, 'text/plain', 'utf-8');
		}
		else
		{
			if ($htmlBody)
			{
				$this->message->addPart($htmlBody, 'text/html', 'utf-8');
			}
			if ($textBody)
			{
				$this->message->addPart($textBody, 'text/plain', 'utf-8');
			}
		}

		$this->templateName = null;
		$this->templateParams = [];

		return $this;
	}

	public function setTemplate($name, array $params = [])
	{
		$this->templateName = $name;
		$this->templateParams = $params;

		return $this;
	}

	public function getTemplateName()
	{
		return $this->templateName;
	}

	public function renderTemplate()
	{
		if (!$this->templateName)
		{
			throw new \LogicException("Cannot render an email template without one specified");
		}

		$output = $this->mailer->renderMailTemplate(
			$this->templateName, $this->templateParams, $this->language, $this->toUser
		);

		$this->setContent($output['subject'], $output['html'], $output['text']);

		if ($output['headers'])
		{
			$headers = $this->message->getHeaders();
			foreach ($output['headers'] AS $header => $value)
			{
				$headers->addTextHeader($header, $value);
			}
		}

		return $this;
	}

	public function setLanguage(\XF\Language $language = null)
	{
		$this->language = $language;

		return $this;
	}

	public function getLanguage()
	{
		return $this->language;
	}

	public function getFromAddress()
	{
		$from = $this->message->getFrom();

		if (!$from)
		{
			return null;
		}

		return is_array($from) ? key($from) : $from;
	}

	public function getMessageObject()
	{
		return $this->message;
	}

	public function getSendableMessage()
	{
		if ($this->templateName)
		{
			$this->renderTemplate();
		}

		return $this->message;
	}

	public function send(\Swift_Transport $transport = null)
	{
		if ($this->setupError)
		{
			$this->logSetupError($this->setupError);
			return 0;
		}

		$message = $this->getSendableMessage();
		if (!$message->getTo())
		{
			return 0;
		}

		return $this->mailer->send($message, $transport);
	}

	public function queue()
	{
		if ($this->setupError)
		{
			$this->logSetupError($this->setupError);
			return false;
		}

		$message = $this->getSendableMessage();
		if (!$message->getTo())
		{
			return false;
		}

		return $this->mailer->queue($message);
	}

	/**
	 * Handles the application of the setup error. Throws the exception immediately in debug mode.
	 * (In normal execution, queues it for logging when the email is sent.)
	 *
	 * @param \Exception $e
	 * @throws \Exception
	 */
	protected function applySetupError(\Exception $e)
	{
		if (\XF::$debugMode)
		{
			throw $e;
		}

		$this->setupError = $e;
	}

	protected function logSetupError(\Exception $e)
	{
		$to = $this->message->getTo();
		$toEmails = $to ? implode(', ', array_keys($to)) : '[unknown]';

		\XF::logException($this->setupError, false, "Email to {$toEmails} failed setup:");
	}
}