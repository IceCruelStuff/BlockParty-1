<?php

namespace BlockParty\Events;

use pocketmine\event\plugin\PluginEvent;
use pocketmine\Player;
use BlockParty\BlockParty;
use BlockParty\Arena\Arena;

class PlayerLoseArenaEvent extends PluginEvent{
    protected $player;
    protected $arena;
    
    public static $handlerList = null;
    
    public function __construct(BlockParty $plugin, Player $player, Arena $arena){
        $this->player = $player;
        $this->arena = $arena;
    }
    
    public function getPlayer(){
        return $this->player;
    }
    
    public function getArena(){
        return $this->arena;
    }
    
    public function getArenaName(){
        return $this->arena->id;
    }
}