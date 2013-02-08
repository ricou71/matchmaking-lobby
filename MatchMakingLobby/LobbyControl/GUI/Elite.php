<?php
/**
 * @copyright   Copyright (c) 2009-2013 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */

namespace ManiaLivePlugins\MatchMakingLobby\LobbyControl\GUI;

use ManiaLive\Gui\Windows\Shortkey;
use ManiaLivePlugins\MatchMakingLobby\LobbyControl\Match;

class Elite extends AbstractGUI
{

	public $actionKey = Shortkey::F6;
	public $lobbyBoxPosY = 0;

	public function getLaunchMatchText(Match $m, $player)
	{
		$key = array_search($player, $m->team1);
		if($key)
		{
			$mate1 = \ManiaLive\Data\Storage::getInstance()->getPlayerObject($m->team1[($key + 1) % 3])->nickName;
			$mate2 = \ManiaLive\Data\Storage::getInstance()->getPlayerObject($m->team1[($key + 2) % 3])->nickName;
		}
		else
		{
			$key = array_search($player, $m->team2);
			$mate1 = \ManiaLive\Data\Storage::getInstance()->getPlayerObject($m->team1[($key + 1) % 3])->nickName;
			$mate2 = \ManiaLive\Data\Storage::getInstance()->getPlayerObject($m->team1[($key + 2) % 3])->nickName;
		}
		return sprintf('$0F0Match with $<%s$> & $<%s$> starts in $<$FFF%%2d$>, F6 to cancel...', $mate1, $mate2);
	}

	public function getNotReadyText()
	{
		return 'Press F6 to find a match.';
	}

	public function getPlayerBackLabelPrefix()
	{
		return 'Welcome back. ';
	}

	public function getReadyText()
	{
		return 'Searching for an opponent, F6 to cancel.';
	}

	public function getMatchInProgressText()
	{
		return 'You have a match in progress. Prepare to be transfered.';
	}

}

?>