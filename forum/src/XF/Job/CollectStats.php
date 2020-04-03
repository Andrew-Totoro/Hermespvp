<?php

namespace XF\Job;

class CollectStats extends AbstractJob
{
	public function run($maxRunTime)
	{
		$success = true;
		$error = null;

		/** @var \XF\Repository\CollectStats $collectStatsRepo */
		$collectStatsRepo = $this->app->repository('XF:CollectStats');

		if (!$collectStatsRepo->isEnabled())
		{
			return $this->complete();
		}

		$stats = $collectStatsRepo->collectStats();
		if ($stats)
		{
			$client = $this->app->http()->client();

			try
			{
				$response = $client->post('https://xenforo.com/api/submit-stats.json', [
					'exceptions' => false,
					'body' => [
						'stats' => $stats
					]
				]);

				$responseJson = $response->json();

				if ($response->getStatusCode() !== 200)
				{
					$success = false;
					if (isset($responseJson['error']))
					{
						$error = reset($responseJson['error']);
					}
					else
					{
						$error = 'An unexpected error occurred.';
					}
				}
			}
			catch (\GuzzleHttp\Exception\RequestException $e)
			{
				$success = false;
				$error = $e->getMessage();
			}
		}

		if (!$success)
		{
			\XF::logError('XenForo stats collection failed: ' . $error);
		}

		// TODO: We could log failures, if applicable, and attempt to retry
		$serverStatsConfig = $this->app->options()->collectServerStats;
		$serverStatsConfig['last_sent'] = time();

		/** @var \XF\Repository\Option $optionRepo */
		$optionRepo = $this->app->repository('XF:Option');

		// skip verifying the option here as only last_sent will have changed
		$optionRepo->updateOptionSkipVerify('collectServerStats', $serverStatsConfig);

		// jitter between 0 and 14 days on top of the base 28 days. This should ensure some randomness
		// of the requests to the XF server so not all sites try to communicate at the same time
		// while still ensuring that we collect stats once every 4-6 weeks.
		$continueDate = \XF::$time + 28 * 24 * 3600;
		$offsetJitter = mt_rand(0, 14 * 24 * 3600);
		$continueDate += $offsetJitter;

		$result = $this->resume();

		$result->continueDate = $continueDate;

		return $result;
	}

	public function getStatusMessage()
	{
		return \XF::phrase('collecting_server_stats');
	}

	public function canCancel()
	{
		return false;
	}

	public function canTriggerByChoice()
	{
		return false;
	}
}