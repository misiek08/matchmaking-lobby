<?php
/**
 * @copyright   Copyright (c) 2009-2012 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */

namespace ManiaLivePlugins\MatchMakingLobby\MatchControl;

use ManiaLive\DedicatedApi\Callback\Event as ServerEvent;
use ManiaLivePlugins\MatchMakingLobby\Windows;
use ManiaLivePlugins\MatchMakingLobby\Windows\Label;

class MatchControl extends \ManiaLive\PluginHandler\Plugin
{

	const ABORTING = -2;
	const WAITING = -1;
	const SLEEPING = 0;
	const DECIDING = 1;
	const PLAYING = 2;
	const OVER = 3;
	const PREFIX = 'Match$08fBot$000»$8f0 ';

	/** @var int */
	private $state = self::SLEEPING;

	/** @var \DateTime */
	private $nextTick = null;

	/** @var string[] */
	private $intervals = array();

	/** @var bool[string] */
	private $players = array();

	/** @var string */
	private $hall = null;
	
	/** @var string */
	private $backLink = null;
	
	/** @var \ManiaLivePlugins\MatchMakingLobby\LobbyControl\Match */
	private $match = null;

	/** @var \ManiaLivePlugins\MatchMakingLobby\LobbyControl\GUI\AbstractGUI */
	private $gui;
	
	/** @var int */
	private $waitingTime = 0;
	
	/** @var int */
	private $matchId = 0;

	function onInit()
	{
		$this->setVersion('0.1');
	}

	function onLoad()
	{
		$this->connection->cleanGuestList();
		$this->connection->addGuest('-_-');
		$this->connection->setHideServer(1);
		$this->connection->setMaxPlayers(0);
		$this->connection->setMaxSpectators(0);
		$this->connection->removeGuest('-_-');
		$this->nextTick = new \DateTime();
		$this->intervals = array(
			self::ABORTING => '1 minute',
			self::WAITING => '5 seconds',
			self::SLEEPING => '5 seconds',
			self::DECIDING => '30 seconds',
			self::PLAYING => null,
			self::OVER => '10 seconds'
		);
		$this->enableDatabase();
		$this->enableTickerEvent();
		$this->createTables();

		$scriptName = $this->connection->getScriptName();
		$scriptName = end(explode('\\', $scriptName['CurrentValue']));
		$scriptName = str_ireplace('.script.txt', '', $scriptName);

		$guiClassName = '\ManiaLivePlugins\MatchMakingLobby\LobbyControl\GUI\\'.$scriptName;
		if(!class_exists($guiClassName))
		{
			throw new \UnexpectedValueException($guiClassName.' has no GUI class');
		}
		$this->gui = $guiClassName::getInstance();

		$this->updateLobbyWindow();
	}

	function onTick()
	{
		if(new \DateTime() < $this->nextTick) return;
		if($this->state != self::SLEEPING)
		{
			$this->updateLobbyWindow();
		}
		switch($this->state)
		{
			case self::SLEEPING:
				if(!($next = $this->getNext()))
				{
					$this->live();
					$this->sleep();
					break;
				}
				$this->prepare($next->backLink, $next->hall, $next->match);
				$this->wait();
				break;
			case self::DECIDING:
				$this->play();
				break;
			case self::WAITING:
				$this->waitingTime += 5;
				$current = $this->getNext();
				if($this->waitingTime >= 60 || $current === false)
				{
					$this->cancel();
					break;
				}
				if($current->backLink != $this->backLink || $current->hall != $this->hall || $current->match != $this->match)
				{
					$this->prepare($current->backLink, $current->hall ,$current->match);
					$this->wait();
					break;
				}
				break;
			case self::ABORTING:
				$this->cancel();
				break;
			case self::OVER:
				$this->end();
		}
	}

	function onPlayerConnect($login, $isSpectator)
	{
		$this->players[$login] = true;
		$this->forcePlayerTeam($login);
		if($this->isEverybodyHere())
		{
			if($this->state == self::WAITING) $this->decide(); 
			elseif($this->state == self::ABORTING) $this->play();
		}
	}

	function onPlayerInfoChanged($playerInfo)
	{
		try
		{
			$this->forcePlayerTeam($playerInfo['Login']);
		}
		catch(\Exception $e)
		{
			if($e->getMessage != 'Login unknown.')
			{
				throw $e;
			}
		}
	}

	function onPlayerDisconnect($login)
	{
		$this->players[$login] = false;
		if(in_array($this->state, array(self::DECIDING, self::PLAYING))) $this->abort();
	}

	function onEndMatch($rankings, $winnerTeamOrMap)
	{
		if($this->state == self::PLAYING) $this->over();
		elseif($this->state == self::DECIDING) $this->decide();
	}

	function onGiveUp($login)
	{
		$this->giveUp($login);
	}

	private function updateLobbyWindow()
	{
		$obj = $this->db->execute(
				'SELECT H.* FROM Halls H '.
				'INNER JOIN Servers S ON H.login = S.hall '.
				'WHERE S.login = %s', $this->db->quote($this->storage->serverLogin)
			)->fetchObject();
		if($obj)
		{
			$lobbyWindow = Windows\LobbyWindow::Create();
			$lobbyWindow->setAlign('right', 'bottom');
			$lobbyWindow->setPosition(170, $this->gui->lobbyBoxPosY);
			$lobbyWindow->set($obj->name, $obj->readyPlayers, $obj->connectedPlayers + $obj->playingPlayers, $obj->playingPlayers);
			$lobbyWindow->show();
		}
	}

	private function forcePlayerTeam($login)
	{
		if($this->match->team1 && $this->match->team2)
		{
			$team = (array_keys($this->match->team1, $login) ? 0 : 1);
			$this->connection->forcePlayerTeam($login, $team);
		}
	}

	private function live()
	{
		$script = $this->connection->getScriptName();
		$script = preg_replace('~(?:.*?[\\\/])?(.*?)\.Script\.txt~ui', '$1', $script['CurrentValue']);
		$this->db->execute(
			'INSERT INTO Servers(login, title, script, lastLive) VALUES(%s, %s, %s, NOW()) '.
			'ON DUPLICATE KEY UPDATE title=VALUES(title), script=VALUES(script), lastLive=VALUES(lastLive)',
			$this->db->quote($this->storage->serverLogin), 
			$this->db->quote($this->connection->getSystemInfo()->titleId),
			$this->db->quote($script)
		);
	}

	private function getNext()
	{
		$result = $this->db->execute(
				'SELECT H.backLink, S.hall, S.players as `match` FROM Servers  S '.
				'INNER JOIN Halls H ON S.hall = H.login '.
				'WHERE S.login=%s', $this->db->quote($this->storage->serverLogin)
			)->fetchObject();

		if(!$result || !$result->backLink) return false;
		$result->match = json_decode($result->match);
		return $result;
	}

	private function prepare($backLink, $hall, $match)
	{
		$this->backLink = $backLink;
		$this->hall = $hall;
		$this->players = array_fill_keys($match->players, false);
		$this->match = $match;
		Windows\ForceManialink::EraseAll();

		$giveUp = Windows\GiveUp::Create();
		$giveUp->setAlign('right');
		$giveUp->setPosition(160.1, $this->gui->lobbyBoxPosY + 4.7);
		$giveUp->set(\ManiaLive\Gui\ActionHandler::getInstance()->createAction(array($this, 'onGiveUp'), true));
		$giveUp->show();

		foreach($match->players as $login)
			$this->connection->addGuest((string)$login, true);
		$this->connection->executeMulticall();

		$this->enableDedicatedEvents(ServerEvent::ON_PLAYER_CONNECT | ServerEvent::ON_PLAYER_DISCONNECT | ServerEvent::ON_END_MATCH | ServerEvent::ON_PLAYER_INFO_CHANGED);
		Label::EraseAll();
	}

	private function sleep()
	{
		$this->changeState(self::SLEEPING);
	}

	private function wait()
	{
		$this->changeState(self::WAITING);
		$this->waitingTime = 0;
	}

	private function abort()
	{
		Windows\GiveUp::EraseAll();
		$this->connection->chatSendServerMessage('A player quits... If he does not come back soon, match will be aborted.');
		$this->changeState(self::ABORTING);
	}

	private function giveUp($login)
	{
		$confirm = Label::Create();
		$confirm->setPosition(0, 40);
		$confirm->setMessage('Match over. You will be transfered back.');
		$confirm->show();
		Windows\GiveUp::EraseAll();
		$this->connection->chatSendServerMessage(sprintf('Match aborted because $<%s$> gave up.',
				$this->storage->getPlayerObject($login)->nickName));
		$this->registerQuiter($login);
		$this->changeState(self::OVER);
	}

	private function cancel()
	{
		if($this->state == self::ABORTING)
		{
			$logins = array_keys($this->players, false);
			foreach($logins as $login)
			{
				$this->registerQuiter($login);
			}
		}
		$confirm = Label::Create();
		$confirm->setPosition(0, 40);
		$confirm->setMessage('Match over. You will be transfered back.');
		$confirm->show();
		$this->connection->chatSendServerMessage('Match aborted.');
		$this->changeState(self::OVER);
	}

	private function decide()
	{
		if($this->state != self::DECIDING)
				$this->connection->chatSendServerMessage('Match is starting ,you still have time to change the map if you want.');
		if(!$this->matchId)
		{
			$script = $this->connection->getScriptName();
			$script = preg_replace('~(?:.*?[\\\/])?(.*?)\.Script\.txt~ui', '$1', $script['CurrentValue']);
			$this->db->execute(
				'INSERT INTO PlayedMatchs (`server`, `title`, `script`, `match`, `playedDate`) VALUES (%s, %s, %s, %s, NOW())',
				$this->db->quote($this->storage->serverLogin), 
				$this->db->quote($this->connection->getSystemInfo()->titleId),
				$this->db->quote($script),
				$this->db->quote(json_encode($this->match))
			);
		}
		$this->changeState(self::DECIDING);
	}

	private function play()
	{
		$giveUp = Windows\GiveUp::Create();
		$giveUp->setAlign('right');
		$giveUp->setPosition(160.1, $this->gui->lobbyBoxPosY + 4.7);
		$giveUp->set(\ManiaLive\Gui\ActionHandler::getInstance()->createAction(array($this, 'onGiveUp'), true));
		$giveUp->show();
		
		if($this->state == self::DECIDING) $this->connection->chatSendServerMessage('Time to change map is over!');
		else $this->connection->chatSendServerMessage('Player is back, match continues.');
		$this->changeState(self::PLAYING);
	}

	private function over()
	{
		Windows\GiveUp::EraseAll();
//		$this->connection->chatSendServerMessage('Match over! You will be transfered back to the lobby.');
		$this->changeState(self::OVER);
	}

	private function end()
	{
		$this->db->execute(
			'UPDATE Servers SET hall=NULL, players=NULL WHERE login=%s', $this->db->quote($this->storage->serverLogin)
		);

		$jumper = Windows\ForceManialink::Create();
		$jumper->set('maniaplanet://#qjoin='.$this->backLink);
		$jumper->show();
		$this->connection->cleanGuestList();
		$this->sleep();
		usleep(20);
		$this->connection->restartMap();
	}

	private function changeState($state)
	{
		if($this->intervals[$state])
		{
			$this->nextTick = new \DateTime($this->intervals[$state]);
			$this->enableTickerEvent();
		}
		else $this->disableTickerEvent();

		$this->state = $state;
	}

	private function isEverybodyHere()
	{
		return count(array_filter($this->players)) == count($this->players);
	}
	
	private function registerQuiter($login)
	{
		$this->db->execute(
			'INSERT INTO Quitters VALUES (%s,NOW(), %s)', 
			$this->db->quote($login),
			$this->db->quote($this->hall)
		);
	}

	private function createTables()
	{
		$this->db->execute(
			<<<EOHalls
CREATE TABLE IF NOT EXISTS `Halls` (
	`login` VARCHAR(25) NOT NULL,
	`readyPlayers` INT(10) NOT NULL,
	`connectedPlayers` INT(10) NOT NULL,
	`playingPlayers` INT(10) NOT NULL,
	`name` VARCHAR(76) NOT NULL,
	`backLink` VARCHAR(76) NOT NULL,
	PRIMARY KEY (`login`)
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB;
EOHalls
		);
		
		$this->db->execute(
			<<<EOServers
CREATE TABLE IF NOT EXISTS `Servers` (
  `login` varchar(25) NOT NULL,
  `title` varchar(51) NOT NULL,
  `script` varchar(50) DEFAULT NULL,
  `lastLive` datetime NOT NULL,
  `hall` varchar(25) DEFAULT NULL COMMENT 'login@title',
  `players` text,
  PRIMARY KEY (`login`),
  KEY `title` (`title`),
  KEY `script` (`script`),
  KEY `lastLive` (`lastLive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
EOServers
		);
		
		$this->db->execute(
			<<<EOMatchs
CREATE TABLE IF NOT EXISTS `PlayedMatchs` (
	`id` INT(10) NOT NULL AUTO_INCREMENT,
	`server` VARCHAR(25) NOT NULL,
	`title` varchar(51) NOT NULL,
	`script` VARCHAR(50) NOT NULL,
	`match` TEXT NOT NULL,
	`playedDate` DATETIME NOT NULL,
	PRIMARY KEY (`id`)
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB;
EOMatchs
		);
		
		$this->db->execute(
			<<<EOQuitters
CREATE TABLE IF NOT EXISTS `Quitters` (
	`playerLogin` VARCHAR(25) NOT NULL,
	`creationDate` DATETIME NOT NULL,
	`hall` VARCHAR(25) NOT NULL
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB;
EOQuitters
		);
	}

}

?>