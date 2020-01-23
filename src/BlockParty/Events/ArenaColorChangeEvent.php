<?php

namespace BlockParty\Events;

use pocketmine\event\plugin\PluginEvent;
use BlockParty\BlockParty;
use BlockParty\Arena\Arena;
use pocketmine\event\Cancellable;

class ArenaColorChangeEvent extends PluginEvent implements Cancellable{
    protected $arena;
    protected $oldColor;
    protected $newColor;
    
    public static $handlerList = null;
    
    public function __construct(BlockParty $plugin, Arena $arena, $oldColor, $newColor){
        $this->arena = $arena;
        $this->newColor = $newColor;
        $this->oldColor = $oldColor;
    }
    
    
    public function getArena(){
        return $this->arena;
    }
    
    public function getArenaName(){
        return $this->arena->id;
    }
    
    public function getNewColor(){
        return $this->newColor;
    }
    
    public function getOldColor(){
        return $this->oldColor;
    }
    //color is 0-15
    public function setNewColor($color){
        $this->newcolor = $color;
    }
}