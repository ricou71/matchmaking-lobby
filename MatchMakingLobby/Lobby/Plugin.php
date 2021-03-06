<?php
/**
 * @copyright   Copyright (c) 2009-2012 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */

namespace ManiaLivePlugins\MatchMakingLobby\Lobby;

use Maniaplanet\DedicatedServer\Structures;
use ManiaLive\DedicatedApi\Callback\Event as ServerEvent;
use ManiaLive\Data\Event as StorageEvent;
use ManiaLive\Gui\Windows\Shortkey;
use ManiaLivePlugins\MatchMakingLobby\Services;
use ManiaLivePlugins\MatchMakingLobby\Config;
use ManiaLivePlugins\MatchMakingLobby\GUI;
use ManiaLivePlugins\MatchMakingLobby\Services\Match;

class Plugin extends \ManiaLive\PluginHandler\Plugin implements Services\AllyListener
{

	const PREFIX = '$000»$09f ';

	/** @var int */
	protected $tick;

	/** @var Config */
	protected $config;

	/** @var MatchMakers\MatchMakerInterface */
	protected $matchMaker;

	/** @var GUI\AbstractGUI */
	protected $gui;

	/** @var string */
	protected $backLink;

	/** @var int[string] */
	protected $countDown = array();

	/** @var int[string] */
	protected $replacerCountDown = array();

	/** @var string[string]  */
	protected $replacers = array();

	/** @var int[string] */
	protected $blockedPlayers = array();

	/** @var Services\MatchMakingService */
	protected $matchMakingService;
	
	/** @var Services\AllyService */
	protected $allyService;

	/** @var string */
	protected $scriptName;

	/** @var string */
	protected $titleIdString;

	/** @var bool */
	protected $updatePlayerList = false;

	/** @var bool */
	protected $backupNeeded = false;

	/** @var int[string] */
	protected $matchCancellers = array();

	/** @var \ManiaLivePlugins\MatchMakingLobby\Utils\Dictionary */
	protected $dictionary;

	protected $setReadyAction;
	
	protected $setLocalAllyAction;
	
	protected $unsetLocalAllyAction;

	/**
	 * @var boolean 
	 */
	protected $allowRunMatchMaker = false;
	
	protected $maintenanceMode = false;
	
	protected $maintenanceMessage;
	
	/**
	 * @var int 
	 */
	protected $lastMasterId = 0;
	
	/**
	 * @var \DateTime 
	 */
	protected $lobbyFullUntil = false;

	function onInit()
	{
		$this->setVersion('4.0.0');

		$this->addDependency(new \ManiaLive\PluginHandler\Dependency('ManiaLive', '4.0.0'));
		
		//Load MatchMaker and helpers for GUI
		$this->config = Config::getInstance();
		$this->scriptName = \ManiaLivePlugins\MatchMakingLobby\Config::getInstance()->script ? : preg_replace('~(?:.*?[\\\/])?(.*?)\.Script\.txt~ui', '$1', $this->storage->gameInfos->scriptName);

		$matchMakerClassName = $this->config->getMatchMakerClassName($this->scriptName);
		if (!class_exists($matchMakerClassName))
		{
			throw new \Exception(sprintf("Can't find class %s. You should either set up the config : ManiaLivePlugins\MatchMakingLobby\Config.matchMakerClassName or the script name",$matchMakerClassName));
		}

		$guiClassName = $this->config->getGuiClassName($this->scriptName);
		if (!class_exists($guiClassName))
		{
			throw new \Exception(sprintf("Can't find class %s. You should either set up the config : ManiaLivePlugins\MatchMakingLobby\Config.guiClassName or the script name",$guiClassName));
		}

		$this->matchMakingService = new Services\MatchMakingService();
		$this->matchMakingService->createTables();

		$this->setGui(new $guiClassName());
		$this->gui->lobbyBoxPosY = 45;
		$this->setMatchMaker($matchMakerClassName::getInstance());

		$this->dictionary = \ManiaLivePlugins\MatchMakingLobby\Utils\Dictionary::getInstance($this->config->getDictionnary($this->scriptName));
		
		$this->allyService = Services\AllyService::getInstance($this->storage->serverLogin, $this->scriptName, $this->connection->getSystemInfo()->titleId);
		
		return true;
	}

	function onLoad()
	{
		//Check if Lobby is not running with the match plugin
		if($this->isPluginLoaded('\ManiaLivePlugins\MatchMakingLobby\Match\Plugin'))
		{
			throw new \Exception('Lobby and match cannot be one the same server.');
		}
		$this->enableDedicatedEvents(
			ServerEvent::ON_PLAYER_CONNECT |
			ServerEvent::ON_PLAYER_DISCONNECT |
			ServerEvent::ON_MODE_SCRIPT_CALLBACK |
			ServerEvent::ON_BEGIN_MAP
		);
		$this->enableStorageEvents(
			StorageEvent::ON_PLAYER_CHANGE_SIDE |
			StorageEvent::ON_PLAYER_JOIN_GAME
		);
		
		/** Register to Ally Service Event */
		\ManiaLive\Event\Dispatcher::register(Services\AllyEvent::getClass(), $this);

		$matchSettingsClass = $this->config->getMatchSettingsClassName($this->scriptName);
		/* @var $matchSettings \ManiaLivePlugins\MatchMakingLobby\MatchSettings\MatchSettings */
		if (!class_exists($matchSettingsClass))
		{
			throw new \Exception(sprintf("Can't find class %s. You should set up the config : ManiaLivePlugins\MatchMakingLobby\Config.matchSettingsClassName",$matchSettingsClass));
		}

		$this->titleIdString = $this->connection->getSystemInfo()->titleId;
		$this->backLink = $this->storage->serverLogin.':'.$this->storage->server->password.'@'.$this->titleIdString;

		$this->registerLobby();

		$this->setLobbyInfo();

		$partySize = ($this->matchMaker->getNumberOfTeam() ? (int) $this->matchMaker->getPlayersPerMatch() / $this->matchMaker->getNumberOfTeam() : 1);
		$this->gui->createWaitingScreen(
			\ManiaLive\Gui\ActionHandler::getInstance()->createAction(array($this, 'onPlayerReady')),
			$this->scriptName,
			$partySize,
			$this->config->rulesManialink,
			$this->config->logoURL, $this->config->logoLink
		);
		
		$this->gui->configurePlayerList($partySize > 1 ? true : false);

		foreach(array_merge($this->storage->players, $this->storage->spectators) as $login => $obj)
		{
			//Simulate player connection
			$this->onPlayerConnect($login, null);
		}
		$this->updatePlayerList = true;

		$matchSettings = new $matchSettingsClass();
		$settings = $matchSettings->getLobbyScriptSettings();
		$this->connection->setModeScriptSettings($settings);

		$this->enableTickerEvent();

		$this->connection->setCallVoteRatiosEx(false, array(
			new Structures\VoteRatio('SetModeScriptSettingsAndCommands', -1.),
			new Structures\VoteRatio('NextMap', -1.),
			new Structures\VoteRatio('JumpToMapIndex', -1.),
			new Structures\VoteRatio('SetNextMapIndex', -1.),
			new Structures\VoteRatio('RestartMap', -1.)
			));

		$this->updateLobbyWindow();

		//$this->gui->showHelp($this->scriptName);
		
		if ($this->config->showMasters)
		{
			$this->gui->createMasterList();
			$this->updateMasterList();
		}
		
		$this->connection->disableServiceAnnounces(true);

		$this->registerChatCommand('setAllReady', 'onSetAllReady', 0, true, \ManiaLive\Features\Admin\AdminGroup::get());
		$this->registerChatCommand('kickNonReady', 'onKickNotReady', 0, true, \ManiaLive\Features\Admin\AdminGroup::get());
		$this->registerChatCommand('resetPenalty', 'onResetPenalty', 1, true, \ManiaLive\Features\Admin\AdminGroup::get());
		$this->registerChatCommand('resetAllPenalties', 'onResetAllPenalties', 0, true, \ManiaLive\Features\Admin\AdminGroup::get());
		$this->registerChatCommand('maintenance', 'onMaintenance', 1, true, \ManiaLive\Features\Admin\AdminGroup::get());

		$this->connection->restartMap();
		
		$this->setLocalAllyAction = \ManiaLive\Gui\ActionHandler::getInstance()->createAction(array($this,'onPlayerSetLocalAlly'));
		$this->unsetLocalAllyAction = \ManiaLive\Gui\ActionHandler::getInstance()->createAction(array($this,'onPlayerUnsetLocalAlly'));
	}
	
	/**
	 * For some reason, players stop spawing in lobby mode, a restart on map start fixes that.
	 */
	function onBeginMap($map, $warmUp, $matchContinuation)
	{
		$this->connection->restartMap();
	}

	function onUnload()
	{
		$this->setLobbyInfo(false);
		parent::onUnload();
	}

	function onPlayerConnect($login, $isSpectator)
	{
		\ManiaLive\Utilities\Logger::debug(sprintf('Player connected: %s', $login));

		$player = Services\PlayerInfo::Get($login);
		$player->setAway(false);

		$playerObject = $this->storage->getPlayerObject($login);

		if($playerObject)
		{
			$player->ladderPoints = $playerObject->ladderStats['PlayerRankings'][0]['Score'];
			$player->allies = $this->allyService->get($login);
		}

		$match = $this->matchMakingService->getPlayerCurrentMatch($login, $this->storage->serverLogin, $this->scriptName, $this->titleIdString);

		if($match)
		{
			\ManiaLive\Utilities\Logger::debug(sprintf('send %s to is match %d', $login, $match->id));

			$player->isInMatch = true;

			$this->sendToServer($login, $match->matchServerLogin);
			
			$this->gui->updateWaitingScreenLabel($this->gui->getMatchInProgressText(), $login);
			return;
		}
		else
		{
			$player->isInMatch = false;
		}

		if(!$playerObject)
		{
			return;
		}
		
		$this->gui->createPlayerList($login);

		$this->gui->showWaitingScreen($login);
		$this->updatePlayerList = true;

		$this->updateKarma($login);

		$this->checkAllies($player);
		$this->allyService->setPlayerPresent($login);

		try
		{
			$this->connection->removeGuest($login);
		}
		catch(\Maniaplanet\DedicatedServer\Xmlrpc\Exception $e)
		{

		}
	}

	function onPlayerDisconnect($login, $disconnectionReason)
	{
		\ManiaLive\Utilities\Logger::debug(sprintf('Player disconnected: %s (%s)', $login, $disconnectionReason));

		$player = Services\PlayerInfo::Get($login);
		$player->setAway();

		if (array_key_exists($login, $this->blockedPlayers))
		{
			$this->matchMakingService->decreasePlayerPenalty($login, time() - $this->blockedPlayers[$login], $this->storage->serverLogin, $this->scriptName, $this->titleIdString);
			unset($this->blockedPlayers[$login]);
		}

		$match = $this->matchMakingService->getPlayerCurrentMatch($login, $this->storage->serverLogin, $this->scriptName, $this->titleIdString);
		if($match)
		{
			if (array_key_exists($login, $this->matchCancellers))
			{
				unset($this->matchCancellers[$login]);
			}

			if (array_key_exists($match->id, $this->countDown) && $this->countDown[$match->id] > 0)
			{
				$this->cancelMatch($login, $match);
			}
			if (array_key_exists($login, $this->replacerCountDown) && $this->replacerCountDown[$login] > 0)
			{
				$this->cancelReplacement($login, $match);
			}
		}

		$this->gui->removePlayerFromPlayerList($login);
		$this->allyService->setPlayerAway($login);
	}

	//Core of the plugin
	function onTick()
	{
		$timers = array();
		$this->tick++;
		if ($this->tick % 8 == 0)
		{
			gc_collect_cycles();
			
			$mtime = microtime(true);
			foreach($this->blockedPlayers as $login => $time)
			{
				$this->updateKarma($login);
			}
			$timers['blocked'] = microtime(true) - $mtime;
		}
		
		if ($this->tick % 15 == 0 && $this->maintenanceMode)
		{
			$this->connection->chatSendServerMessageToLanguage($this->dictionary->getChat(array(
							array('textId' => 'maintenance', 'params' => array($this->maintenanceMessage))
			)));
		}

		//If there is some match needing players
		//find backup in ready players and send them to the match server
		$mtime = microtime(true);
		if($this->tick % 3 == 0)
		{
			$this->runReplacerMaker();
		}
		$timers['backups'] = microtime(true) - $mtime;

		if (($this->config->matchMakerDelay == 0 && $this->isMatchMakerAllowed() && $this->tick % 2 == 0) || ($this->config->matchMakerDelay != 0 && $this->tick % $this->config->matchMakerDelay == 0))
		{
			$this->runMatchMaker();
		}

		foreach($this->replacerCountDown as $login => $countDown)
		{
			$login = (string) $login;
			switch(--$countDown)
			{
				case -15:
					unset($this->replacerCountDown[$login]);
					break;

				case 0:
					$match = $this->matchMakingService->getPlayerCurrentMatch($login, $this->storage->serverLogin, $this->scriptName, $this->titleIdString);
					$player = $this->storage->getPlayerObject($login);
					if($match && $match->state >= Match::PREPARED)
					{
						if ($this->sendToServer($login, $match->matchServerLogin))
						{
							//TODO Add transfer textId to AbstractGUI
							$this->gui->createLabel($this->gui->getTransferText(), $login, null, false, false);
							$this->connection->addGuest($login, true);
							$this->connection->chatSendServerMessageToLanguage($this->dictionary->getChat(array(
								array('textId' => 'substituteMoved', 'params' => array(self::PREFIX, $player->nickName))
								)));
							$this->matchMakingService->updatePlayerState($this->replacers[$login], $match->id, Services\PlayerInfo::PLAYER_STATE_REPLACED);
						}
						else
						{
							//Something bad happened 
						}
					}
					else
					{
						\ManiaLive\Utilities\Logger::debug(sprintf('For replacer %s, match does not exist anymore', $login));
					}
					unset($match, $player);
					//nobreak

				default:
					$this->replacerCountDown[$login] = $countDown;
					break;
			}
		}
		unset($login, $countDown);

		$this->runJumper();

		//Clean guest list for not in match players
		if($this->tick % 29 == 0)
		{
			$mtime = microtime(true);
			$guests = $this->connection->getGuestList(-1, 0);
			if(count($guests))
			{
				$guests = Structures\Player::getPropertyFromArray($guests,'login');
				$logins = $this->matchMakingService->getPlayersJustFinishedAllMatches($this->storage->serverLogin, $guests);
				if($logins)
				{
					foreach($logins as $login)
					{
						$this->connection->removeGuest($login, true);
					}
				}
			}
			$timers['cleanGuest'] = microtime(true) - $mtime;
		}

		if($this->tick % 31 == 0)
		{
			$mtime = microtime(true);
			$this->setLobbyInfo();
			$timers['lobbyInfo'] = microtime(true) - $mtime;
		}
		if($this->tick % 6 == 0)
		{
			$mtime = microtime(true);
			$this->updateLobbyWindow();
			$timers['lobbyWindow'] = microtime(true) - $mtime;
		}
		
		if ($this->config->showMasters && $this->tick % 6 == 0)
		{
			$this->updateMasterList();
		}

		if($this->tick % 12 == 0)
		{
			if($this->scriptName == 'Elite' || $this->scriptName == 'Combo')
			{
				$endedMatches = $this->matchMakingService->getEndedMatchesSince($this->storage->serverLogin, $this->scriptName, $this->titleIdString);
				foreach($endedMatches as $endedMatch)
				{
					if($endedMatch->team1)
					{
						$states = $endedMatch->playersState;
						$connectedPlayersCallBack = function ($login) use ($states) { return $states[$login] == Services\PlayerInfo::PLAYER_STATE_CONNECTED;};
						$team1 = array_filter($endedMatch->team1, $connectedPlayersCallBack);
						$team2 = array_filter($endedMatch->team2, $connectedPlayersCallBack);
						$blue = implode(', ', array_map('\ManiaLib\Utils\Formatting::stripStyles', $team1));
						$red = implode(', ', array_map('\ManiaLib\Utils\Formatting::stripStyles', $team2));
					}
					else
					{
						$blue = \ManiaLib\Utils\Formatting::stripStyles($endedMatch->players[0]);
						$red = \ManiaLib\Utils\Formatting::stripStyles($endedMatch->players[1]);
					}

					$this->connection->chatSendServerMessage(sprintf(self::PREFIX.'$<$00f%s$> $o%d - %d$z $<$f00%s$>',$blue,$endedMatch->mapPointsTeam1, $endedMatch->mapPointsTeam2, $red));
				}
			}
		}

		// Update playerlist every 3sec
		if($this->updatePlayerList && $this->tick % 3 == 0)
		{
			$maxAlliesCount = $this->matchMaker->getPlayersPerMatch() / 2 - 1;
			$this->gui->updatePlayerList($this->blockedPlayers, $this->setLocalAllyAction, $this->unsetLocalAllyAction, $maxAlliesCount);
			$this->updatePlayerList = false;
		}
		if ($this->tick % 12 == 0)
		{
			$this->registerLobby();
		}
		Services\PlayerInfo::CleanUp();
		if($this->tick % 11 == 0)
		{
			$this->allyService->removePlayerAway();
		}

		$this->connection->executeMulticall();

		//Debug
		$timers = array_filter($timers, function($v) { return $v > 0.10; });
		if(count($timers))
		{
			$line = array();
			foreach($timers as $key => $value)
			{
				$line[] = sprintf('%s:%f',$key,$value);
			}
			\ManiaLive\Utilities\Logger::debug(implode('|', $line));
		}
	}
	
	function updateMasterList()
	{
		$masters = $this->matchMakingService->getLatestMasters($this->storage->serverLogin, $this->scriptName, $this->titleIdString, $this->lastMasterId);
		$this->gui->updateMasterList($masters);
		$this->lastMasterId = array_key_exists(0, $masters) ? $masters[0]['id'] : $this->lastMasterId;
	}
	
	function onMaintenance($login, $message)
	{
		$this->maintenanceMode = !$this->maintenanceMode;
		$this->maintenanceMessage = $message;
	}
	
	protected function isMatchMakerAllowed()
	{
		return ($this->allowRunMatchMaker && !$this->maintenanceMode);
	}

	protected function runJumper()
	{
		foreach($this->countDown as $matchId => $countDown)
		{
			switch(--$countDown)
			{
				case -15:
					unset($this->countDown[$matchId]);
					break;
				case -10:
					//nobreak;
				case -5:
					$match = $this->matchMakingService->getMatch($matchId);
					//FIXME: maybe use storage which is a safer way to know if a player is still here
					$players = array_filter($match->players, function ($p) { return !Services\PlayerInfo::Get($p)->isAway(); });
					if($players)
					{
						\ManiaLive\Utilities\Logger::debug('re-display jumper for: '.implode(',', $players));
						$this->sendToServer($players, $match->matchServerLogin);
					}
					else
					{
						//no need to keep this countdown if all player are gone
						unset($this->countDown[$matchId]);
						continue;
					}
					$this->countDown[$matchId] = $countDown;
					break;
				case 0:
					\ManiaLive\Utilities\Logger::debug(sprintf('prepare jump for match : %d', $matchId));
					$match = $this->matchMakingService->getMatch($matchId);
					if($match->state >= Match::PREPARED)
					{
						\ManiaLive\Utilities\Logger::debug(sprintf('jumping to server : %s', $match->matchServerLogin));
						$players = array_map(array($this->storage, 'getPlayerObject'), $match->players);
						
						$this->sendToServer($match->players, $match->matchServerLogin);

						$nicknames = array();
						foreach($players as $player)
						{
							if($player && !array_key_exists($player->login, $this->blockedPlayers))
							{
								$nicknames[] = '$<'.\ManiaLib\Utils\Formatting::stripStyles($player->nickName).'$>';
								$this->connection->addGuest($player, true);
							}
						}
						$this->connection->chatSendServerMessageToLanguage($this->dictionary->getChat(array(
							array('textId' => 'matchJoin', 'params' => array(self::PREFIX, implode(' & ', $nicknames)))
							)));
					}
					else
					{
						\ManiaLive\Utilities\Logger::debug(sprintf('jump cancel match state is : %d', $match->state));
						foreach($match->players as $player)
						{
							$this->setReadyLabel($player);
						}
					}
					unset($match);
					//nobreak
				default:
					$this->countDown[$matchId] = $countDown;
					break;
			}
		}
	}

	protected function runReplacerMaker()
	{
		$matchesNeedingBackup = $this->matchMakingService->getMatchesNeedingBackup($this->storage->serverLogin, $this->scriptName, $this->titleIdString);
		if ($matchesNeedingBackup)
		{
			$this->backupNeeded = true;
			$potentialBackups = $this->getMatchablePlayers();
			$allyService = $this->allyService;
			$storage = $this->storage;

			//removing player which has allies from the potential backups
			$potentialBackups = array_filter($potentialBackups, function ($login) use ($storage, $allyService)
			{
				$obj = $storage->getPlayerObject($login);
				if($obj)
				{
					return !count($allyService->get($obj->login));
				}
			});
			if ($potentialBackups)
			{
				foreach($matchesNeedingBackup as $match)
				{
					$potentialBackupsForMatch = array_filter($potentialBackups,
						function ($backup) use ($match)
						{
							return !in_array($backup, $match->players);
						}
					);

					/** @var Match $match */
					$quitters = $this->matchMakingService->getMatchQuitters($match->id);
					foreach ($quitters as $quitter)
					{
						$backup = $this->matchMaker->getBackup($quitter, $potentialBackupsForMatch);
						if ($backup)
						{
							\ManiaLive\Utilities\Logger::debug(
							sprintf('match %d, %s will replace %s', $match->id, $backup, $quitter)
							);

							$this->matchMakingService->updatePlayerState($quitter, $match->id, Services\PlayerInfo::PLAYER_STATE_REPLACER_PROPOSED);
							$this->replacers[$backup] = $quitter;

							$teamId = $match->getTeam($quitter);
							$this->matchMakingService->addMatchPlayer($match->id, $backup, $teamId);
							$this->gui->createLabel($this->gui->getBackUpLaunchText($match), $backup, 0, false, false);
							$this->setShortKey($backup, array($this, 'onPlayerCancelReplacement'));
							$this->replacerCountDown[$backup] = 7;

							//Unset this replacer for next iteration
							unset($potentialBackupsForMatch[array_search($backup, $potentialBackupsForMatch)]);
							unset($potentialBackups[array_search($backup, $potentialBackups)]);
						}
					}
				}
			}
			else
			{
				$this->setNotReadyLabel();
			}
			unset($potentialBackups);
		}
		else
		{
			$this->backupNeeded = false;
		}
	}

	function onModeScriptCallback($param1, $param2)
	{
		\ManiaLive\Utilities\Logger::debug($param1);
		switch ($param1)
		{
			case 'RunMatchMaker':
				$this->allowRunMatchMaker = true;
				break;
			case 'StopMatchMaker':
				$this->allowRunMatchMaker = false;
				break;
		}
	}
	
	protected function runMatchMaker()
	{
		//Check if a server is available
		if ($this->matchMakingService->countAvailableServer($this->storage->serverLogin, $this->scriptName, $this->titleIdString) > 0)
		{
			$players = $this->getMatchablePlayers();
			shuffle($players);
			$matches = $this->matchMaker->run($players);
			foreach($matches as $match)
			{
				/** @var Match $match */
				$server = $this->matchMakingService->getAvailableServer(
					$this->storage->serverLogin,
					$this->scriptName,
					$this->titleIdString
				);
				if($server)
				{
					//Match ready, let's prepare it !
					$this->prepareMatch($server, $match);
				}
				else
				{
					//FIXME: we shouldn't be in this case.. remove ?
					foreach($match->players as $login)
					{
						$this->gui->createLabel($this->gui->getNoServerAvailableText(), $login);
					}
				}
			}
			unset($matches, $match);
		}
		// No server available for this match
		else
		{
			$this->setReadyLabel();
		}
	}

	function onPlayerChangeSide($player, $oldSide)
	{
		if($oldSide == 'spectator' && !Services\PlayerInfo::Get($player->login)->isReady())
		{
			$this->onPlayerReady($player->login);
		}
		$this->updateLobbyWindow();
	}

	function onPlayerJoinGame($login)
	{
		try
		{
			$this->connection->forceSpectator($login, 1);
		}
		catch (\Maniaplanet\DedicatedServer\Xmlrpc\Exception $e)
		{
			//do nothing
		}
	}
	
	function onPlayerReady($login)
	{
		$mtime = microtime(true);
		if(!$this->matchMakingService->isInMatch($login, $this->storage->serverLogin, $this->scriptName, $this->titleIdString))
		{
			try
			{
				$tokenInfos = $this->connection->getDemoTokenInfosForPlayer($login);
			}
			catch (\Exception $e)
			{
				//Player is disconnected ?
				return;
			}
			if($tokenInfos->TokenCost > 0)
			{
				$this->gui->removeWaitingScreen($login);
				$this->resetShortKey($login);
				if ($tokenInfos->CanPayToken)
				{
					$this->onAnswerYesToDialog($login);
				}
				else
				{
					$this->gui->showDemoPlayDialog($login, array($this, 'onClickOnSplashBackground'), array($this,'onCloseSplash'));
				}
			}
			elseif($tokenInfos->TokenCost == 0)
			{
				$this->setPlayerReady($login);
			}
		}
		else
		{
			\ManiaLive\Utilities\Logger::debug(sprintf('Player try to be ready while in match: %s', $login));
		}
		$time = microtime(true) - $mtime;
		if($time > 0.05)
			\ManiaLive\Utilities\Logger::debug(sprintf('onPlayerReady:%f',$time));
	}

	function onPlayerNotReady($login)
	{
		$mtime = microtime(true);
		$this->setPlayerNotReady($login);
		$time = microtime(true) - $mtime;
		if($time > 0.05)
			\ManiaLive\Utilities\Logger::debug(sprintf('onPlayerNotReady:%f',$time));
	}
	
	public function onAlliesChanged($login)
	{
		$player = $this->storage->getPlayerObject($login);
		if($player)
		{
			$this->checkAllies($player);
			if(!Services\PlayerInfo::Get($login)->isReady())
			{
				$this->gui->showWaitingScreen($login);
			}
			Services\PlayerInfo::Get($login)->allies = $this->allyService->get($login);
			$this->updatePlayerList = true;
		}
	}

	protected function checkAllies($player)
	{
		if ($this->matchMaker->getNumberOfTeam() > 0)
		{
			$alliesMax = ($this->matchMaker->getPlayersPerMatch()/$this->matchMaker->getNumberOfTeam())-1;
			//Too many allies
			if (count($this->allyService->get($player->login)) > $alliesMax)
			{
				$this->gui->ShowTooManyAlliesLabel($player->login, $alliesMax);
			}
			else
			{
				$this->gui->eraseTooManyAlliesLabel($player->login);
			}
		}
	}

	function onNothing()
	{
		//Do nothing
	}

	function doNotShow($login)
	{
		//TODO store data
		$this->gui->hideSplash($login);
	}

	function onPlayerCancelReplacement($login)
	{
		$match = $this->matchMakingService->getPlayerCurrentMatch($login, $this->storage->serverLogin, $this->scriptName, $this->titleIdString);

		if ($match)
		{
			$this->cancelReplacement($login, $match);
		}
	}

	function onPlayerCancelMatchStart($login)
	{
		\ManiaLive\Utilities\Logger::debug('Player cancel match start: '.$login);

		$match = $this->matchMakingService->getPlayerCurrentMatch($login, $this->storage->serverLogin, $this->scriptName, $this->titleIdString);
		if ($match !== false && $match->state == Match::PREPARED && array_key_exists($match->id, $this->countDown) && $this->countDown[$match->id] > 0)
		{
			$this->cancelMatch($login, $match);
		}
		else
		{
			if($match === false)
			{
				\ManiaLive\Utilities\Logger::debug(sprintf('error: player %s cancel unknown match start',$login));
			}
			else
			{
				\ManiaLive\Utilities\Logger::debug(sprintf('error: player %s cancel match start (%d) not in prepared mode',$login, $match->id));
			}
		}
	}

	public function onSetAllReady()
	{
		foreach(array_merge($this->storage->players, $this->storage->spectators) as $player)
		{
			if (!array_key_exists($player->login, $this->blockedPlayers))
			{
				$this->onPlayerReady($player->login);
			}
		}
	}

	public function onKickNotReady()
	{
		foreach(Services\PlayerInfo::GetNotReady() as $player)
		{
			$this->connection->kick($player->login);
		}
	}

	public function onResetPenalty($login)
	{
		$this->matchMakingService->decreasePlayerPenalty($login, 86000, $this->storage->serverLogin, $this->scriptName, $this->titleIdString);
	}
	
	public function onResetAllPenalties()
	{
		foreach (array_merge($this->storage->players, $this->storage->spectators) as $player)
		{
			$this->matchMakingService->decreasePlayerPenalty($player->login, 86000, $this->storage->serverLogin, $this->scriptName, $this->titleIdString);
		}
	}

	function onCloseSplash($login)
	{
		$this->closeSplash($login);
	}

	function onClickOnSplashBackground($login)
	{
		$this->closeSplash($login);
		$this->connection->sendOpenLink($login, 'elite-ads', 1);
	}
	
	function closeSplash($login)
	{
		$this->gui->removeDemoPayDialog($login);
		$this->gui->showWaitingScreen($login);
	}

	function onAnswerNoToDialog($login)
	{
		$this->gui->removeDemoReadyDialog($login);
		$this->gui->showWaitingScreen($login);
	}

	function onAnswerYesToDialog($login)
	{
		$this->gui->removeDemoReadyDialog($login);
		$this->setPlayerReady($login);
	}
	
	function onPlayerSetLocalAlly($login, array $params = array())
	{
		$allies = $this->allyService->getAll($login);
		$maxAlliesCount = $this->matchMaker->getPlayersPerMatch() / $this->matchMaker->getNumberOfTeam() - 1;
		if(count($allies) < $maxAlliesCount)
		{
			$this->allyService->set($login, $params['allyLogin']);
		}
	}
	
	function onPlayerUnsetLocalAlly($login, array $params = array())
	{
		$this->allyService->remove($login, $params['allyLogin']);
	}
	
	function onPlayerNeedAlliesHelp($login)
	{
		$this->gui->hideWaitingScreen($login);
		$this->gui->showAlliesHelp($login, \ManiaLive\Gui\ActionHandler::getInstance()->createAction(array($this, 'onCloseAlliesHelp')));
	}
	
	function onCloseAlliesHelp($login)
	{
		$this->gui->removeAlliesHelp($login);
		$this->gui->showWaitingScreen($login);
	}

	protected function setPlayerReady($login)
	{
		$player = Services\PlayerInfo::Get($login);
		$player->setReady(true);

		$this->setReadyLabel($login);

		$this->updatePlayerList = true;
		$this->gui->removeWaitingScreen($login);

		try
		{
			$this->connection->forceSpectator($login, 2);
		}
		catch (\Exception $e)
		{
			//Do nothing
			//Maybe log because it's strange :)
		}
	}

	protected function setPlayerNotReady($login)
	{
		$player = Services\PlayerInfo::Get($login);
		$player->setReady(false);

		$this->setNotReadyLabel($login);

		$this->updatePlayerList = true;

		try
		{
			$this->connection->forceSpectator($login, 1);
		}
		catch (\Exception $e)
		{
			//Do nothing
			//Maybe log because it's strange :)
		}

		$this->gui->showWaitingScreen($login);
	}

	protected function cancelMatch($login, Match $match)
	{
		\ManiaLive\Utilities\Logger::debug(sprintf('Cancelling match %d', $match->id));
		
		unset($this->countDown[$match->id]);

		if(array_key_exists($login, $this->matchCancellers))
		{
			$this->matchCancellers[$login]++;
		}
		else
		{
			$this->matchCancellers[$login] = 1;
		}

		if($this->matchCancellers[$login] > $this->config->authorizedMatchCancellation)
		{
			$this->matchMakingService->increasePlayerPenalty($login,
				42 + 5 *($this->matchCancellers[$login] - $this->config->authorizedMatchCancellation),
				$this->storage->serverLogin, $this->scriptName, $this->titleIdString);
		}

		$this->matchMakingService->cancelMatch($match);

		$this->matchMakingService->updatePlayerState($login, $match->id, Services\PlayerInfo::PLAYER_STATE_CANCEL);

		$this->connection->chatSendServerMessageToLanguage($this->dictionary->getChat(array(
				array('textId' => 'matchCancel', 'params' => array(static::PREFIX, $this->storage->getPlayerObject($login)->nickName))
		)));

		foreach($match->players as $playerLogin)
		{
			Services\PlayerInfo::Get($playerLogin)->isInMatch = false;
			$this->gui->eraseMatchSumUp($playerLogin);

			if($playerLogin != $login) $this->setPlayerReady($playerLogin);
			else $this->setPlayerNotReady($playerLogin);
		}
		$this->updateKarma($login);
	}

	protected function cancelReplacement($login, Match $match)
	{
		//If trying to cancel replacement too late, not replacing
		if (array_key_exists($login, $this->replacerCountDown) && $this->replacerCountDown[$login] > 1)
		{
			\ManiaLive\Utilities\Logger::debug(sprintf('%s cancel replacement at countdown %d', $login, array_key_exists($login, $this->replacerCountDown) ? $this->replacerCountDown[$login] : '-1'));

			$this->matchMakingService->updatePlayerState($login, $match->id, Services\PlayerInfo::PLAYER_STATE_CANCEL);

			//FIXME: it could have been QUITTER or GIVEUP
			$this->matchMakingService->updatePlayerState($this->replacers[$login], $match->id,
				Services\PlayerInfo::PLAYER_STATE_QUITTER);

			unset($this->replacerCountDown[$login]);
			unset($this->replacers[$login]);

			$this->setPlayerReady($login);
		}
	}

	private function prepareMatch($server, $match)
	{
		$id = $this->matchMakingService->registerMatch($server, $match, $this->scriptName, $this->titleIdString, $this->storage->serverLogin);
		\ManiaLive\Utilities\Logger::debug(sprintf('Preparing match %d on server: %s',$id, $server));
		\ManiaLive\Utilities\Logger::debug($match);

		$this->countDown[$id] = 7;
		
		foreach($match->players as $player)
		{
			$this->gui->removeLabel($player);
			$this->gui->removeWaitingScreen($player);
			$this->gui->showMatchSumUp($match, $player, 5);
			$this->resetShortKey($player);
			Services\PlayerInfo::Get($player)->isInMatch = true;
			$this->connection->forceSpectator((string) $player, 1, true);
		}
		$this->connection->executeMulticall();

		$this->updatePlayerList = true;
	}

	private function getReadyPlayersCount()
	{
		$count = 0;
		foreach(array_merge($this->storage->players, $this->storage->spectators) as $player)
			$count += Services\PlayerInfo::Get($player->login)->isReady() ? 1 : 0;

		return $count;
	}

	private function getTotalPlayerCount()
	{
		//Number of matchs in DB minus matchs prepared
		//Because player are still on the server
		$playingPlayers = $this->getPlayingPlayersCount();

		$playerCount = count($this->storage->players) + count($this->storage->spectators);

		return $playerCount + $playingPlayers;
	}

	private function getPlayingPlayersCount()
	{
		return $this->matchMakingService->getPlayersPlayingCount($this->storage->serverLogin);
	}

	private function getTotalSlots()
	{
		$matchServerCount = $this->matchMakingService->getLiveMatchServersCount($this->storage->serverLogin, $this->scriptName, $this->titleIdString);
		return $matchServerCount * $this->matchMaker->getPlayersPerMatch() + $this->storage->server->currentMaxPlayers + $this->storage->server->currentMaxSpectators;
	}

	private function registerLobby()
	{
		$connectedPlayerCount = count($this->storage->players) + count($this->storage->spectators);
		$this->matchMakingService->registerLobby($this->storage->serverLogin, $this->getReadyPlayersCount(), $connectedPlayerCount, $this->storage->server->name, $this->backLink);
	}

	/**
	 * @param $login
	 */
	private function updateKarma($login)
	{
		$player = $this->storage->getPlayerObject($login);
		$playerInfo = Services\PlayerInfo::Get($login);
		if ($player && $playerInfo)
		{
			$penalty = $this->matchMakingService->getPlayerPenalty($login, $this->storage->serverLogin, $this->scriptName, $this->titleIdString);
			if($penalty > 0)
			{
				if(array_key_exists($login, $this->blockedPlayers))
				{
					if ((time() - $this->blockedPlayers[$login]) >= $penalty)
					{
						$this->matchMakingService->decreasePlayerPenalty($login, time() - $this->blockedPlayers[$login], $this->storage->serverLogin, $this->scriptName, $this->titleIdString);
					}
				}
				else
				{
					$this->blockedPlayers[$login] = time();

					$this->connection->chatSendServerMessageToLanguage($this->dictionary->getChat(array(
							array('textId' => 'playerSuspended' , 'params' => array(self::PREFIX, $player->nickName))
					)));
				}

				$this->setPlayerNotReady($login);

				$this->resetShortKey($login);
				$this->updatePlayerList = true;
				$this->gui->updateWaitingScreenLabel($this->gui->getBadKarmaText($penalty), $login);
				$this->gui->disableReadyButton($login);
			}
			else
			{
				unset($this->blockedPlayers[$login]);
				$this->setPlayerNotReady($login);
				$this->gui->updateWaitingScreenLabel(null, $login);
				$this->gui->disableReadyButton($login, false);
			}
		}
		else
		{
			if(array_key_exists($login, $this->blockedPlayers))
			{
				unset($this->blockedPlayers[$login]);
			}
			\ManiaLive\Utilities\Logger::debug(sprintf('UpdateKarma for not connected player %s', $login));
		}
	}

	protected function setShortKey($login, $callback)
	{
		$shortKey = Shortkey::Create($login);
		$shortKey->removeCallback($this->gui->actionKey);
		$shortKey->addCallback($this->gui->actionKey, $callback);
	}

	protected function resetShortKey($login)
	{
		Shortkey::Erase($login);
	}

	private function updateLobbyWindow()
	{
		$playersCount = $this->getReadyPlayersCount();
		$playingPlayersCount = $this->getPlayingPlayersCount();
		$avgWaitingTime = $this->matchMakingService->getAverageTimeBetweenMatches($this->storage->serverLogin, $this->scriptName, $this->titleIdString);
		$this->gui->updateLobbyWindow(
			$this->storage->server->name,
			$playersCount,
			$playingPlayersCount,
			$avgWaitingTime
		);

	}

	/**
	 * 
	 * @param boolean $enable
	 */
	private function setLobbyInfo($enable = true)
	{
		if($enable)
		{
			$maxPlayers = $this->getTotalSlots();
			$averageLevel = $this->getAveragePlayerLadder();
			if ($this->matchMakingService->countAvailableServer($this->storage->serverLogin, $this->scriptName, $this->titleIdString) == 0)
			{
				$lobbyPlayers = $maxPlayers;
				$this->lobbyFullUntil = new \DateTime('+ 10 minutes');
			}
			elseif ($this->lobbyFullUntil !== false && new \DateTime < $this->lobbyFullUntil)
			{
				$lobbyPlayers = $maxPlayers;
			}
			else
			{
				$this->lobbyFullUntil = false;
				$lobbyPlayers = $this->getTotalPlayerCount();
			}
		}
		else
		{
			$lobbyPlayers = count($this->storage->players);
			$maxPlayers = $this->storage->server->currentMaxPlayers;
			$averageLevel = 20000.;
		}
		$this->connection->setLobbyInfo($enable, $lobbyPlayers, $maxPlayers, (double) $averageLevel);
	}

	protected function setGui(GUI\AbstractGUI $GUI)
	{
		$this->gui = $GUI;
	}

	protected function setMatchMaker(MatchMakers\MatchMakerInterface $matchMaker)
	{
		$this->matchMaker = $matchMaker;
	}

	protected function getMatchablePlayers()
	{
		$readyPlayers = Services\PlayerInfo::GetReady();
		$service = $this->matchMakingService;

		$serverLogin = $this->storage->serverLogin;
		$scriptName = $this->scriptName;
		$titleIdString = $this->titleIdString;
		$blockedPlayers = array_keys($this->blockedPlayers);

		$matchablePlayers = array_filter($readyPlayers,
			function (Services\PlayerInfo $p) use ($service, $serverLogin, $scriptName, $titleIdString, $blockedPlayers)
			{
				return !in_array($p->login, $blockedPlayers) && !$p->isAway() && !$service->isInMatch($p->login, $serverLogin, $scriptName, $titleIdString);
			});
		
		return array_map(function (Services\PlayerInfo $p) { return (string) $p->login; }, $matchablePlayers);
	}

	/**
	 * @param string $login If null, set message for all ready players
	 */
	protected function setReadyLabel($login = null)
	{
		$matchablePlayers = $this->getMatchablePlayers();
		$players = ($login === null) ? $matchablePlayers : array($login);
		if ($this->matchMakingService->countAvailableServer($this->storage->serverLogin, $this->scriptName, $this->titleIdString) <= 0)
		{
			$message = $this->gui->getNoServerAvailableText();
		}
		else if(count($matchablePlayers) < $this->matchMaker->getPlayersPerMatch())
		{
			$message = $this->gui->getNeedReadyPlayersText();
		}
		else
		{
			$message = $this->gui->getReadyText();
		}

		foreach($players as $login)
		{
			$this->gui->createLabel($message, $login);
			$this->setShortKey($login, array($this, 'onPlayerNotReady'));
		}
	}

	/**
	 * @param string $login If null, set message for all non ready players
	 */
	protected function setNotReadyLabel($login = null)
	{
		$players = ($login === null) ? Services\PlayerInfo::GetNotReady() : array(Services\PlayerInfo::Get($login));
		foreach ($players as $player)
		{
			$this->gui->removeLabel($player->login);
			if (!array_key_exists($player->login, $this->blockedPlayers))
			{
				$message = null;
				if (count(Services\PlayerInfo::GetReady()) == 0 && $this->backupNeeded)
				{
					$message = $this->gui->getNoReadyPlayers();
				}
				$this->setShortKey($player->login, array($this,'onPlayerReady'));
				if ($message)
				{
					$this->gui->updateWaitingScreenLabel($message, $player->login);
				}
			}
			else
			{
				$this->resetShortKey($player->login);
			}
		}
	}
	
	/**
	 * @param string $login login of the player to send
	 * @param string $serverLogin server login to send them to
	 * @return boolean
	 */
	protected function sendToServer($login, $serverLogin)
	{
		try
		{
			$this->connection->sendOpenLink($login, $this->generateServerLink($serverLogin), 1);
			return true;
		}
		catch (\Maniaplanet\DedicatedServer\Xmlrpc\Exception $e)
		{
			return false;
		}
	}

	protected function generateServerLink($serverLogin)
	{
		return sprintf('#qjoin=%s@%s', $serverLogin, $this->titleIdString);
	}

	protected function getAveragePlayerLadder()
	{
		$logins = $this->matchMakingService->getPlayersPlaying($this->storage->serverLogin);
		$points = array();
		foreach($logins as $login)
		{
			$player = Services\PlayerInfo::Get($login);
			if($player)
			{
				$points[] = $player->ladderPoints;
			}
		}
		foreach(array_merge($this->storage->players, $this->storage->spectators) as $player)
		{
			$points[] = $player->ladderStats['PlayerRankings'][0]['Score'];
		}
		return count($points) == 0 ? 0. : array_sum($points) / count($points);
	}
}

?>
