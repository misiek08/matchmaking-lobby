<?php
/**
 * @copyright   Copyright (c) 2009-2013 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */

namespace ManiaLivePlugins\MatchMakingLobby\Services;

class MatchInfo
{
	/**
	 * String to use in a maniaplanet link to switch players on the lobby
	 * @var string
	 */
	public $matchId;
	
	/**
	 * The server login where the match is played
	 * @var string
	 */
	public $matchServerLogin;
	
	/**
	 * The Match itself
	 * @var Match
	 */
	public $match;
}

?>