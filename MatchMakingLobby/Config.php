<?php
/**
 * @copyright   Copyright (c) 2009-2012 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */

namespace ManiaLivePlugins\MatchMakingLobby;

class Config extends \ManiaLib\Utils\Singleton
{

	public $script;
	public $penaltyTime = 4;
	public $matchMakerClassName;
	public $guiClassName;
	public $penaltiesCalculatorClassName;
	public $penaltyClass;

}

?>
