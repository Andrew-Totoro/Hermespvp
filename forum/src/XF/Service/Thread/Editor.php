<?php

namespace XF\Service\Thread;

use XF\Entity\Thread;
use XF\Entity\User;

class Editor extends \XF\Service\AbstractService
{
	use \XF\Service\ValidateAndSavableTrait;

	/**
	 * @var Thread
	 */
	protected $thread;

	/**
	 * @var User
	 */
	protected $user;

	protected $performValidations = true;

	public function __construct(\XF\App $app, Thread $thread)
	{
		parent::__construct($app);
		$this->thread = $thread;
		$this->user = \XF::visitor();
	}

	public function getThread()
	{
		return $this->thread;
	}

	public function getUser()
	{
		return $this->user;
	}

	public function setPerformValidations($perform)
	{
		$this->performValidations = (bool)$perform;
	}

	public function getPerformValidations()
	{
		return $this->performValidations;
	}

	public function setTitle($title)
	{
		$this->thread->set('title', $title,
			['forceConstraint' => $this->performValidations ? false : true]
		);
	}

	public function setPrefix($prefixId)
	{
		$this->thread->prefix_id = $prefixId;
	}

	public function setDiscussionOpen($discussionOpen)
	{
		$this->thread->discussion_open = $discussionOpen;
	}

	public function setDiscussionState($discussionState)
	{
		$this->thread->discussion_state = $discussionState;
	}

	public function setSticky($sticky)
	{
		$this->thread->sticky = $sticky;
	}

	public function setCustomFields(array $customFields)
	{
		$thread = $this->thread;

		$editMode = $thread->getFieldEditMode();

		/** @var \XF\CustomField\Set $fieldSet */
		$fieldSet = $thread->custom_fields;
		$fieldDefinition = $fieldSet->getDefinitionSet()
			->filterEditable($fieldSet, $editMode)
			->filterOnly($thread->Forum->field_cache);

		$customFieldsShown = array_keys($fieldDefinition->getFieldDefinitions());

		if ($customFieldsShown)
		{
			$fieldSet->bulkSet($customFields, $customFieldsShown, $editMode);
		}
	}

	public function checkForSpam()
	{
		$thread = $this->thread;
		$post = $thread->FirstPost;

		if ($thread->discussion_state == 'visible' && $this->user->isSpamCheckRequired())
		{
			$user = $this->user;

			$message = $thread->title . "\n" . $post->message;

			$checker = $this->app->spam()->contentChecker();
			$checker->check($user, $message, [
				'permalink' => $this->app->router('public')->buildLink('canonical:threads', $thread),
				'content_type' => 'thread'
			]);

			$decision = $checker->getFinalDecision();
			switch ($decision)
			{
				case 'moderated':

					$thread->discussion_state = 'moderated';
					break;

				case 'denied':
					$checker->logSpamTrigger('thread', $thread->thread_id);
					$thread->error(\XF::phrase('your_content_cannot_be_submitted_try_later'));
					break;
			}
		}
	}
	
	protected function finalSetup()
	{
	}

	protected function _validate()
	{
		$this->finalSetup();

		$thread = $this->thread;

		if ($this->performValidations)
		{
			$this->checkForSpam();
		}

		$thread->preSave();
		$errors = $thread->getErrors();

		if ($this->performValidations)
		{
			if (!$thread->prefix_id
				&& $thread->Forum->require_prefix
				&& $thread->Forum->getUsablePrefixes()
				&& !$thread->canMove()
			)
			{
				$errors[] = \XF::phraseDeferred('please_select_a_prefix');
			}

			// the canMove check allows moderators to bypass this requirement when editing; they're likely editing
			// another user's thread so don't force them to add a prefix
		}

		return $errors;
	}

	protected function _save()
	{
		$thread = $this->thread;

		$thread->save();

		return $thread;
	}
}