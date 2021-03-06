<?php

namespace XF\Finder;

use XF\Mvc\Entity\Finder;

class ConversationMessage extends Finder
{
	public function inConversation(\XF\Entity\ConversationMaster $conversation)
	{
		$this->where('conversation_id', $conversation->conversation_id);

		return $this;
	}

	public function earlierThan(\XF\Entity\ConversationMessage $message)
	{
		$this->where('message_date', '<', $message->message_date);

		return $this;
	}

	public function forFullView()
	{
		$this->with(['User', 'User.Profile']);

		if ($this->app()->options()->showMessageOnlineStatus)
		{
			$this->with('User.Activity');
		}

		$userId = \XF::visitor()->user_id;
		if ($userId)
		{
			$this->with('Likes|' . $userId);
		}

		return $this;
	}
}