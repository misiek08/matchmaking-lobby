<?php
/**
 * @copyright   Copyright (c) 2009-2013 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */

namespace ManiaLivePlugins\MatchMakingLobby\Lobby\MatchMakers;

abstract class AbstractAllies extends \ManiaLib\Utils\Singleton implements MatchMakerInterface
{
	function run(array $players = array())
	{
		$teams = $this->getTeams($players);

		return $this->getMatches($teams);
	}

	function getTeams(array $players = array())
	{
		$teams = array();
		$matchedPlayers = array();
		$matchableTeams = array();
		$matchableTeamsScore = array();
		$matches = array();

		$teamSize = $this->getPlayersPerMatch() / $this->getNumberOfTeam();

		$playersObject = array_map('\ManiaLivePlugins\MatchMakingLobby\Services\PlayerInfo::Get', $players);

		usort($playersObject, function ($a, $b)
		{
			return count($b->allies) - count($a->allies);
		});

		foreach ($playersObject as $player)
		{
			$team = array($player->login);
			foreach ($player->allies as $ally)
			{
				if (in_array($ally, $players))
				{
					$team[] = $ally;
				}
			}

			//Save only valid teams
			if (count($team) > 1 && count($team) <= $teamSize)
			{
				sort($team);
				$teams[] = $team;
			}
		}

		$teams = array_map(function ($team){ return serialize($team); }, $teams);

		$teams = array_count_values($teams);

		krsort($teams);

		foreach ($teams as $team => $size)
		{
			$team = unserialize($team);
			if (count($team) != $size)
			{
				continue;
			}
			else if (array_intersect($team, $matchedPlayers) != array())
			{
				continue;
			}
			else if ($size == $teamSize)
			{
				$matchableTeams[] = $team;
				$matchedPlayers = array_merge($matchedPlayers, $team);
			}
			else if ($size < $teamSize)
			{
				$missingCount = $teamSize - count($team);
				$closePlayers = $this->findClosePlayer($team, array_diff($players, $matchedPlayers, $team), $missingCount);

				if ($closePlayers)
				{
					$team = array_merge($team, $closePlayers);

					$matchedPlayers = array_merge($matchedPlayers, $team);
					$matchableTeams[] = $team;
				}
			}
		}

		//There are a few players not in teams
		return array_merge($matchableTeams, $this->getFallbackMatchMaker()->getTeams(array_diff($players, $matchedPlayers)));
	}

	public function getMatches(array $teams = array())
	{
		return $this->getFallbackMatchMaker()->getMatches($teams);
	}

	public function getBackup($missingPlayer, array $players = array())
	{
		return $this->getFallbackMatchMaker()->getBackup($missingPlayer, $players);
	}

	/**
	 * Return the exact number of players
	 * @param array $closeTo
	 * @param array $availablePlayers
	 * @param type $number
	 * @returns array
	 */
	protected function findClosePlayer($closeTo, $availablePlayers, $number)
	{
		if ($number == 0 || count($availablePlayers) < $number)
		{
			return array();
		}
		$foundPlayers =  array_map(function ($index) use ($availablePlayers) { return $availablePlayers[$index]; },
				(array) array_rand($availablePlayers, $number));
		return $foundPlayers;
	}

	/**
	 * This matchmaker is used for all players who does not have the right number of allies
	 * @return MatchMakerInterface
	 */
	protected abstract function getFallbackMatchMaker();
}

?>