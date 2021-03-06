<?php
declare(strict_types = 1);

namespace BlockParty\Arena;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerKickEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\Player;
use BlockParty\BlockParty;
use pocketmine\math\Vector3;
use pocketmine\level\Position;
use pocketmine\block\Block;
use pocketmine\entity\Effect;
use pocketmine\item\Item;
use pocketmine\level\Level;
use BlockParty\Events\PlayerJoinArenaEvent;
use BlockParty\Events\PlayerLoseArenaEvent;
use BlockParty\Events\PlayerWinArenaEvent;
use BlockParty\Events\ArenaColorChangeEvent;

class Arena implements Listener
 {

  private $id;

  public $plugin;

  public $data;

  public $lobbyp       = [];

  public $ingamep      = [];

  public $spec         = [];

  public $game         = 0;

  public $currentColor = 0;

  public $winners      = [];

  public $deads        = [];

  public $setup        = false;

  public function __construct($id, BlockParty $plugin)
   {
    $this->id     = $id;
    $this->plugin = $plugin;
    $this->data   = $plugin->arenas[$id];
    $this->checkWorlds();
    if ($this->data['arena']['time'] !== "true")
     {
      $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data['arena']['arena_world'])
        ->setTime((int)str_replace(['day', 'night'], [6000, 18000], $this->data['arena']['time']));
      $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data['arena']['arena_world'])
        ->stopTime();
     }
    $this->resetFloor();
   }

  public function enableScheduler()
   {
    $this
      ->plugin
      ->getScheduler()
      ->scheduleRepeatingTask(new ArenaScheduler($this) , 20);
   } 

  public function getPlayerMode(Player $p)
   {
    if (isset($this->lobbyp[strtolower($p->getName()) ]))
     {
      return 0;
     }
    if (isset($this->ingamep[strtolower($p->getName()) ]))
     {
      return 1;
     }
    if (isset($this->spec[strtolower($p->getName()) ]))
     {
      return 2;
     }
    return false;
   }

  public function messageArenaPlayers($msg)
   {
    $ingame = array_merge($this->lobbyp, $this->ingamep, $this->spec);
    foreach ($ingame as $p)
     {
      $p->sendMessage($this
        ->plugin
        ->getPrefix() . $msg);
     }
   }

  public function joinToArena(Player $p)
   {
    if ($p->hasPermission("bp.acces") || $p->isOp())
     {
      if ($this->setup === true)
       {
        $p->sendMessage($this
          ->plugin
          ->getPrefix() . $this
          ->plugin
          ->getMsg('arena_in_setup'));
        return;
       }
      if (isset($this->lobbyp[strtolower($p->getName()) ]))
       {
        return;
       }
      if (isset($this->ingamep[strtolower($p->getName()) ]))
       {
        return;
       }
      if (isset($this->spec[strtolower($p->getName()) ]))
       {
        return;
       }
      if (count($this->lobbyp) >= $this->getMaxPlayers())
       {
        $p->sendMessage($this
          ->plugin
          ->getPrefix() . $this
          ->plugin
          ->getMsg('game_full'));
        return;
       }
      if ($this->game == 1)
       {
        $p->sendMessage($this
          ->plugin
          ->getPrefix() . $this
          ->plugin
          ->getMsg('ingame'));
        return;
       }
      if (!$this
        ->plugin
        ->getServer()
        ->isLevelGenerated($this->data['arena']['arena_world']))
       {
        $this
          ->plugin
          ->getServer()
          ->generateLevel($this->data['arena']['arena_world']);
       }
      if (!$this
        ->plugin
        ->getServer()
        ->isLevelLoaded($this->data['arena']['arena_world']))
       {
        $this
          ->plugin
          ->getServer()
          ->loadLevel($this->data['arena']['arena_world']);
       }
      $this
        ->plugin
        ->getServer()
        ->getPluginManager()
        ->callEvent($event = new PlayerJoinArenaEvent($this->plugin, $p, $this));
      if ($event->isCancelled())
       {
        return;
       }
      $p->setGamemode($p::ADVENTURE);
      $p->setFood(20);

      $this->saveInv($p);
      $p->getInventory()
        ->clearAll();
      $p->getArmorInventory()
        ->clearAll();
      $p->getCursorInventory()
        ->clearAll();
      $inv = $p->getInventory();
      $inv->setItem(8, Item::get(Item::BED)
        ->setCustomName("§l§cLeave"));

      $p->teleport(new Position($this->data['arena']['lobby_position_x'], $this->data['arena']['lobby_position_y'], $this->data['arena']['lobby_position_z'], $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data['arena']['arena_world'])));
      $p->sendMessage($this
        ->plugin
        ->getPrefix() . $this
        ->plugin
        ->getMsg('join'));
      $this->lobbyp[strtolower($p->getName()) ]         = $p;
      $vars    = ['%1'];
      $replace = [$p->getName() ];
      $this->messageArenaPlayers(str_replace($vars, $replace, $this
        ->plugin
        ->getMsg('join_others')));
      return;
     }
    $p->sendMessage($this
      ->plugin
      ->getPrefix() . $this
      ->plugin
      ->getMsg('has_not_permission'));
   }

  public function leaveArena(Player $p)
   {
    if ($this->getPlayerMode($p) == 0)
     {
      unset($this->lobbyp[strtolower($p->getName()) ]);
      $p->setFood(20);
      $p->teleport($this
        ->plugin
        ->getServer()
        ->getDefaultLevel()
        ->getSpawnLocation());
      $p->getInventory()
        ->clearAll(); // Fix Bug Item //
     }
    if ($this->getPlayerMode($p) == 1)
     {
      $this->checkWinners($p);
      unset($this->ingamep[strtolower($p->getName()) ]);
      $this->messageArenaPlayers(str_replace("%1", $p->getName() , $this
        ->plugin
        ->getMsg('leave_others')));
      $this->checkAlive();
     }
    if ($this->getPlayerMode($p) == 2)
     {
      unset($this->spec[strtolower($p->getName()) ]);
      $p->setFood(20);
      $p->teleport($this
        ->plugin
        ->getServer()
        ->getDefaultLevel()
        ->getSpawnLocation());
     }
    $p->sendMessage($this
      ->plugin
      ->getPrefix() . $this
      ->plugin
      ->getMsg('leave'));
    $p->removeAllEffects();
   }

  public function startGame()
   {
    $this->game = 1;
    foreach ($this->lobbyp as $p)
     {
      unset($this->lobbyp[strtolower($p->getName()) ]);
      $this->ingamep[strtolower($p->getName()) ] = $p;
      $p->setGamemode($p::ADVENTURE);
      $p->getInventory()
        ->clearAll();
      $p->teleport(new Position($this->data['arena']['join_position_x'], $this->data['arena']['join_position_y'], $this->data['arena']['join_position_z'], $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data['arena']['arena_world'])));
      if ($this->data['type'] == "furious")
       {
        $this->giveEffect(1, $p);
       }
      if ($this->data['type'] == "stoned")
       {
        $this->giveEffect(9, $p);
       }
     }
    $this->messageArenaPlayers($this
      ->plugin
      ->getMsg('start_game'));
    $this->setColor(rand(0, 15));
    $this->resetFloor();
   }

  public function giveEffect($e, Player $p)
   {
    $effect = Effect::getEffect($e);
    if ($e === 1)
     {
     }
    else
     {
     }
    $effect->setDuration(9999999999);
    $effect->setVisible(false);
    $p->addEffect($effect);
   }

  public function resetFloor()
   {
    $colorcount = 0;
    $blocks     = 0;
    $y          = $this->data['arena']['floor_y'];
    $level      = $this
      ->plugin
      ->getServer()
      ->getLevelByName($this->data['arena']['arena_world']);
    for ($x          = min($this->data['arena']['first_corner_x'], $this->data['arena']['second_corner_x']);$x <= max($this->data['arena']['first_corner_x'], $this->data['arena']['second_corner_x']);$x += 3)
     {
      for ($z = min($this->data['arena']['first_corner_z'], $this->data['arena']['second_corner_z']);$z <= max($this->data['arena']['first_corner_z'], $this->data['arena']['second_corner_z']);$z += 3)
       {
        $blocks++;
        $color = rand(0, 15);
        if ($colorcount === 0 && $blocks === 15 || $colorcount <= 1 && $blocks === 40)
         {
          $color = $this->currentColor;
         }
        $block = Block::get($this->getBlock() , $color);
        if ($block->getDamage() === $this->currentColor)
         {
          $colorcount++;
         }
        $level->setBlock(new Position($x, $y, $z, $level) , $block, false, true);
        $level->setBlock(new Position($x, $y, $z + 1, $level) , $block, false, true);
        $level->setBlock(new Position($x, $y, $z + 2, $level) , $block, false, true);
        $level->setBlock(new Position($x + 1, $y, $z, $level) , $block, false, true);
        $level->setBlock(new Position($x + 1, $y, $z + 1, $level) , $block, false, true);
        $level->setBlock(new Position($x + 1, $y, $z + 2, $level) , $block, false, true);
        $level->setBlock(new Position($x + 2, $y, $z, $level) , $block, false, true);
        $level->setBlock(new Position($x + 2, $y, $z + 1, $level) , $block, false, true);
        $level->setBlock(new Position($x + 2, $y, $z + 2, $level) , $block, false, true);
       }
     }
   }

  public function getBlock()
   {
    if (strtolower($this->data['material']) == "wool")
     {
      return 35;
     }
    elseif (strtolower($this->data['material']) == "clay")
     {
      return 159;
     }
    else
     {
      $this
        ->plugin
        ->getLogger()
        ->error(TextFormat::RED . "material " . $this->arenas[$arena]['material'] . " doesn´t exist in arena " . $arena);
     }
   }

  public function removeAllExpectOne()
   {
    $y     = $this->data['arena']['floor_y'];
    $level = $this
      ->plugin
      ->getServer()
      ->getLevelByName($this->data['arena']['arena_world']);
    $color = $this->currentColor;
    for ($x     = min($this->data['arena']['first_corner_x'], $this->data['arena']['second_corner_x']);$x <= max($this->data['arena']['first_corner_x'], $this->data['arena']['second_corner_x']);$x++)
     {
      for ($z = min($this->data['arena']['first_corner_z'], $this->data['arena']['second_corner_z']);$z <= max($this->data['arena']['first_corner_z'], $this->data['arena']['second_corner_z']);$z++)
       {
        if ($level->getBlock(new Vector3($x, $y, $z))->getDamage() !== $color && $level->getBlock(new Vector3($x, $y, $z))->getId() === $this->getBlock())
         {
          $level->setBlock(new Vector3($x, $y, $z) , Block::get(0, 0) , false, true);
         }
       }
     }
   }

  public function onQuit(PlayerQuitEvent $e)
   {
    if ($this->getPlayerMode($e->getPlayer()) !== false)
     {
      $this->leaveArena($e->getPlayer());
     }
   }

  public function onKick(PlayerKickEvent $e)
   {
    if ($this->getPlayerMode($e->getPlayer()) !== false)
     {
      $this->leaveArena($e->getPlayer());
     }
   }

  public function checkAlive()
   {
    if (count($this->ingamep) <= 1)
     {
      if (count($this->ingamep) === 1)
       {
        foreach ($this->ingamep as $p)
         {
          $this->winners[1] = $p->getName();
         }
       }
      $this->stopGame();
     }
   }

  public function stopGame()
   {
    $this->unsetAllPlayers();
    $this->game = 0;
    $this->broadcastResults();
    $this->winners = [];
    $this->resetFloor();
   }

  public function unsetAllPlayers()
   {
    foreach ($this->ingamep as $p)
     {
      $p->removeAllEffects();
      unset($this->ingamep[strtolower($p->getName()) ]);
      $p->teleport($this
        ->plugin
        ->getServer()
        ->getDefaultLevel()
        ->getSpawnLocation());

     }
    foreach ($this->lobbyp as $p)
     {
      $p->removeAllEffects();
      unset($this->lobbyp[strtolower($p->getName()) ]);
      $p->teleport($this
        ->plugin
        ->getServer()
        ->getDefaultLevel()
        ->getSpawnLocation());
      
     }
    foreach ($this->spec as $p)
     {
      $p->removeAllEffects();
      unset($this->spec[strtolower($p->getName()) ]);
      $p->teleport($this
        ->plugin
        ->getServer()
        ->getDefaultLevel()
        ->getSpawnLocation());
     }
   }

  public function saveInv(Player $p)
   {
    $items = [];
    foreach ($p->getInventory()
      ->getContents() as $slot  => & $item)
     {
      $items[$slot]       = implode(":", [$item->getId() , $item->getDamage() , $item->getCount() ]);
     }
    $this
      ->plugin
      ->inv[strtolower($p->getName()) ]       = $items;
    $p->getInventory()
      ->clearAll();
   }

  public function onRespawn(PlayerRespawnEvent $e)
   {
    $p = $e->getPlayer();
    if ($this->getPlayerMode($p) === 0)
     {
      $e->setRespawnPosition(new Position($this->data['arena']['lobby_position_x'], $this->data['arena']['lobby_position_y'], $this->data['arena']['lobby_position_z'], $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data['arena']['arena_world'])));
      return;
     }
    if ($this->getPlayerMode($p) === 1)
     {
      if ($this->data['arena']['spectator_mode'] == 'true')
       {
        $e->setRespawnPosition(new Position($this->data['arena']['spec_spawn_x'], $this->data['arena']['spec_spawn_y'], $this->data['arena']['spec_spawn_z'], $this
          ->plugin
          ->getServer()
          ->getLevelByName($this->data['arena']['arena_world'])));
        unset($this->ingamep[strtolower($p->getName()) ]);
        $this->spec[strtolower($p->getName()) ] = $p;
        return;
       }
      unset($this->ingamep[strtolower($p->getName()) ]);
      $e->setRespawnPosition(new Position($this->data['arena']['leave_position_x'], $this->data['arena']['leave_position_y'], $this->data['arena']['leave_position_z'], $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data['arena']['leave_position_world'])));
      return;
     }
    if ($this->getPlayerMode($p) === 2)
     {
      $e->setRespawnPosition(new Position($this->data['arena']['spec_spawn_x'], $this->data['arena']['spec_spawn_y'], $this->data['arena']['spec_spawn_z'], $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data['arena']['arena_world'])));
     }
   }

  public function onDeath(PlayerDeathEvent $e)
   {
    $p = $e->getEntity();
    if ($p instanceof Player)
     {
      if ($this->getPlayerMode($p) === 0 || $this->getPlayerMode($p) === 2)
       {
        $p->setGamemode(3);
        $e->setDeathMessage("");
       }
      if ($this->getPlayerMode($p) === 1)
       {
        $this
          ->plugin
          ->getServer()
          ->getPluginManager()
          ->callEvent($event = new PlayerLoseArenaEvent($this->plugin, $p, $this));
        $e->setDeathMessage("");
        $e->setDrops([]);
        $ingame = array_merge($this->lobbyp, $this->ingamep, $this->spec);
        $this->checkWinners($p);
        unset($this->ingamep[strtolower($p->getName()) ]);
        $this->spec[strtolower($p->getName()) ] = $p;
        foreach ($ingame as $pl)
         {
          $pl->sendMessage($this
            ->plugin
            ->getPrefix() . str_replace(['%2', '%1'], [count($this->ingamep) , $p->getName() ], $this
            ->plugin
            ->getMsg('death')));
         }
        $this->checkAlive();
       }
     }
   } 

  public function broadcastResults()
   {
    if ($this
      ->plugin
      ->getServer()
      ->getPlayer($this->winners[1]) instanceof Player)
     {
      $this->giveReward($this
        ->plugin
        ->getServer()
        ->getPlayer($this->winners[1]));
      $this
        ->plugin
        ->getServer()
        ->getPluginManager()
        ->callEvent($event   = new PlayerWinArenaEvent($this->plugin, $this
        ->plugin
        ->getServer()
        ->getPlayer($this->winners[1]) , $this));
     }
    if (!isset($this->winners[1])) $this->winners[1]         = "---";
    if (!isset($this->winners[2])) $this->winners[2]         = "---";
    if (!isset($this->winners[3])) $this->winners[3]         = "---";
    $vars    = ['%1', '%2', '%3', '%4'];
    $replace = [$this->id, $this->winners[1], $this->winners[2], $this->winners[3]];
    $msg     = str_replace($vars, $replace, $this
      ->plugin
      ->getMsg('end_game'));
   }

  public function setColor($color)
   {
    $this
      ->plugin
      ->getServer()
      ->getPluginManager()
      ->callEvent($event = new ArenaColorChangeEvent($this->plugin, $this, $this->currentColor, $color));
    if ($event->isCancelled())
     {
      return;
     }
    $this->currentColor = $event->getNewColor();
    foreach ($this->ingamep as $p)
     {
      $p->getInventory()
        ->setItem(0, Item::get($this->getBlock() , $color, 1));
      $p->getInventory()
        ->setItem(1, Item::get($this->getBlock() , $color, 1));
      $p->getInventory()
        ->setItem(2, Item::get($this->getBlock() , $color, 1));
      $p->getInventory()
        ->setItem(3, Item::get($this->getBlock() , $color, 1));
      $p->getInventory()
        ->setItem(4, Item::get($this->getBlock() , $color, 1));
      $p->getInventory()
        ->setItem(5, Item::get($this->getBlock() , $color, 1));
      $p->getInventory()
        ->setItem(6, Item::get($this->getBlock() , $color, 1));
     }
   }
  ######################################EVENTS############################################
  //-------------------------------------------------------------------------------------
  public function onChat(PlayerChatEvent $e)
   {
    $p = $e->getPlayer();
    if ($this->getPlayerMode($p) !== false)
     {
      $e->setCancelled(true);
      $ingame = array_merge($this->lobbyp, $this->ingamep, $this->spec);
      foreach ($ingame as $pl)
       {
        $pl->sendMessage($p->getDisplayName() . " .> " . $e->getMessage());
       }
     }
   }

  public function onDropItem(PlayerDropItemEvent $e)
   {
    $p = $e->getPlayer();
    if ($this->getPlayerMode($p) !== false)
     {
      $e->setCancelled();
     }
   }

  public function onExhaust(PlayerExhaustEvent $e)
   {
    $p = $e->getPlayer();
    if ($this->getPlayerMode($p) !== false)
     {
      $e->setCancelled();
     }
   }

  public function getMaxPlayers()
   {
    return $this->data['arena']['max_players'];
   }

  public function getMinPlayers()
   {
    return $this->data['arena']['min_players'];
   }
   
  public function onBlockTouch(PlayerInteractEvent $e)
   {
    $b = $e->getBlock();
    $p = $e->getPlayer();
    if ($p->hasPermission("bp.sign") || $p->isOp())
     {
      if ($b->x == $this->data["signs"]["join_sign_x"] && $b->y == $this->data["signs"]["join_sign_y"] && $b->z == $this->data["signs"]["join_sign_z"] && $b->level == $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data["signs"]["join_sign_world"]))
       {
        if ($this->getPlayerMode($p) === 0 || $this->getPlayerMode($p) === 1 || $this->getPlayerMode($p) === 2)
         {
          return;
         }
        $this->joinToArena($p);
       }
      if ($b->x == $this->data["signs"]["return_sign_x"] && $b->y == $this->data["signs"]["return_sign_y"] && $b->z == $this->data["signs"]["return_sign_z"] && $b->level == $this
        ->plugin
        ->getServer()
        ->getLevelByName($this->data["arena"]["arena_world"]))
       {
        if ($this->getPlayerMode($p) === 0 || $this->getPlayerMode($p) === 2)
         {
          $this->leaveArena($p);
         }
       }
      return;
     }
    $p->sendMessage($this
      ->plugin
      ->getMsg('has_not_permission'));
   }

  public function onItemTouch(PlayerInteractEvent $e)
   {
    if ($this->getPlayerMode($e->getPlayer()) !== false)
     {
      switch ($e->getPlayer()
        ->getInventory()
        ->getItemInHand()
        ->getId())
       {
      case Item::BED:
        unset($this->ingamep[strtolower($e->getPlayer()->getName()) ]);
		      unset($this->lobbyp[strtolower($e->getPlayer()->getName()) ]);
		      unset($this->spec[strtolower($e->getPlayer()->getName()) ]);
        $this->leaveArena($e->getPlayer());
        $e->getPlayer()->getInventory()
          ->clearAll();
        $e->getPlayer()->getArmorInventory()
          ->clearAll();
        $e->getPlayer()->getCursorInventory()
          ->clearAll();
      break;
       }
     }
   }
   
  public function onHit(EntityDamageEvent $e)
   {
    if ($e->getEntity() instanceof Player)
     {
      if ($e instanceof EntityDamageByEntityEvent)
       {
        $p1 = $e->getDamager();
        $p2 = $e->getEntity();
        if ($this->getPlayerMode($p2) !== false)
         {
          $e->setCancelled(true);
         }
       }
     }
   }

  public function onBlockBreak(BlockBreakEvent $e)
   {
    $p = $e->getPlayer();
    $b = $e->getBlock();
    if ($this->getPlayerMode($p) !== false)
     {
      $e->setCancelled(true);
     }
   }

  public function onBlockPlace(BlockPlaceEvent $e)
   {
    $p = $e->getPlayer();
    $b = $e->getBlock();
    if ($this->getPlayerMode($p) !== false)
     {
      $e->setCancelled(true);
     }
   }
   
  //-------------------------------------------------------------------------------------
  ######################################EVENTS############################################

  public function kickPlayer($p, $reason  = "")
   {
    $players = array_merge($this->ingamep, $this->lobbyp, $this->spec);
    $players[strtolower($p) ]->sendMessage(str_replace("%1", $reason, $this
      ->plugin
      ->getMsg('kick_from_game')));
    $this->leaveArena($players[strtolower($p) ]);
   }

  public function getStatus()
   {
    if ($this->game === 0) return "lobby";
    if ($this->game === 1) return "ingame";
   }

  public function checkWinners(Player $p)
   {
    if (count($this->ingamep) <= 3)
     {
      $this->winners[count($this->ingamep) ]     = $p->getName();
     }
   }

  public function giveReward(Player $p)
   {
    if (isset($this->data['arena']['item_reward']) && $this->data['arena']['item_reward'] !== null && intval($this->data['arena']['item_reward']) !== 0)
     {
      foreach (explode(',', str_replace(' ', '', $this->data['arena']['item_reward'])) as $item)
       {
        $exp = explode(':', $item);
        if (isset($exp[0]) && isset($exp[0]) && isset($exp[0]))
         {
          list($id, $damage, $count)      = $exp;
          if (Item::get($id, $damage, $count) instanceof Item)
           {
            $p->getInventory()
              ->addItem(Item::get($id, $damage, $count));
           }
         }
       }
     }
    if (isset($this->data['arena']['money_reward']))
     {
      if ($this->data['arena']['money_reward'] !== null && $this
        ->plugin->economy !== null)
       {
        $money = $this->data['arena']['money_reward'];
        $ec    = $this
          ->plugin->economy;
        switch ($ec->getName())
         {
        case "EconomyAPI":
          $ec->addMoney($p->getName() , $money);
        break;
        case "PocketMoney":
          $ec->setMoney($p->getName() , $ec->getMoney($p->getName()));
        break;
        case "MassiveEconomy":
          $ec->setMoney($p->getName() , $ec->getMoney($p->getName()));
        break;
        case "GoldStd":
          $ec->giveMoney($p, $money);
        break;
         }
        $p->sendMessage($this
          ->plugin
          ->getPrefix() . str_replace('%1', $money, $this
          ->plugin
          ->getMsg('get_money')));
       }
     }
   }

  public function checkWorlds()
   {
    if (!$this
      ->plugin
      ->getServer()
      ->isLevelGenerated($this->data['arena']['arena_world']))
     {
      $this
        ->plugin
        ->getServer()
        ->generateLevel($this->data['arena']['arena_world']);
     }
    if (!$this
      ->plugin
      ->getServer()
      ->isLevelLoaded($this->data['arena']['arena_world']))
     {
      $this
        ->plugin
        ->getServer()
        ->loadLevel($this->data['arena']['arena_world']);
     }
    if (!$this
      ->plugin
      ->getServer()
      ->isLevelGenerated($this->data['signs']['join_sign_world']))
     {
      $this
        ->plugin
        ->getServer()
        ->generateLevel($this->data['signs']['join_sign_world']);
     }
    if (!$this
      ->plugin
      ->getServer()
      ->isLevelLoaded($this->data['signs']['join_sign_world']))
     {
      $this
        ->plugin
        ->getServer()
        ->loadLevel($this->data['signs']['join_sign_world']);
     }
   }
 }

