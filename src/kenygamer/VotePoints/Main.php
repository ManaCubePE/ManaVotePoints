<?php

declare(strict_types=1);

namespace kenygamer\VotePoints;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\ItemFactory;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

use jojoe77777\FormAPI\ModalForm;
use jojoe77777\FormAPI\SimpleForm;
use DaPigGuy\PiggyCustomEnchants\CustomEnchantManager;

class Main extends PluginBase{
	/** @var Config */
	private $vp;
	/** @var array */
	private $lang, $prizes;
	
	public function onEnable() : void{
		$this->vp = new Config($this->getDataFolder() . "vp.yml", Config::YAML);
		$this->lang = (new Config($this->getDataFolder() . "lang.properties", Config::PROPERTIES))->getAll();
		$this->prizes = [];
		
		//Workaround to the plugin load order instead of appending `loadbefore:` in PiggyCustomEnchants
		//I know we can't do that in production
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $currentTick) : void{
			foreach($this->getConfig()->get("prizes") as $i => $prize){
				$this->prizes[$i] = new \stdClass();
				$this->prizes[$i]->cost = $prize["cost"];
				$this->prizes[$i]->name = $prize["name"];
				foreach($prize["items"] as $item){
					foreach($item as $name => $data){
						$this->prizes[$i]->items[] = $this->parseItem($name, $data);
					}
				}
			}
		}), 1);
	}
	
	/**
	 * @param int|string $item
	 * @param array $data
	 */
	public function parseItem($item, array $data){
		if(is_int($item)){
			try{
				$i = ItemFactory::get($item);
			}catch(\InvalidArgumentException $e){
				$this->getLogger()->error("As int: could not parse " . $item . " as a valid item");
				return false;
			}
		}elseif(is_string($item)){
			$parts = explode(":", $item);
			if(!(count($parts) > 1)){
				$parts = [$parts[0], 0];
		    }
		    list($name, $meta) = $parts;
			if(is_numeric($name)){
				try{
					$i = ItemFactory::get($item, $meta);
				}catch(\InvalidArgumentException $e){
					$this->getLogger()->error("As int:int: could not parse " . $item . " as a valid item");
					return false;
				}
			}else{
				try{
					$i = ItemFactory::fromString($item);
				}catch(\Exception $e){
					$this->getLogger()->error("As string:[int]: could not parse " . $item . " as a valid item");
					return false;
				}
			}
		}
		$i->setCount(intval($data["amount"] ?? 1));
		$i->setCustomName(TextFormat::RESET . TextFormat::colorize($data["name"]));
		$lines = [];
		$lore = explode("{LINE}", $data["lore"] ?? "");
		if($lore !== ""){
			foreach($lore as $index => $lore){
				if($lore !== ""){
					$lines[$index] = TextFormat::RESET . TextFormat::colorize($lore);
				}
			}
			$i->setLore($lines);
		}
		$ce = $this->getServer()->getPluginManager()->getPlugin("PiggyCustomEnchants");
		if($ce instanceof Plugin && version_compare($ce->getDescription()->getVersion(), "2.0") > -1){
			foreach($data["enchants"] ?? [] as $enchant => $level){
				$enchantment = is_numeric($enchant) ? CustomEnchantManager::getEnchantment(intval($enchant)) : CustomEnchantManager::getEnchantmentByName($enchant);
				if($enchantment instanceof Enchantment){
					$i->addEnchantment(new EnchantmentInstance($enchantment, (int) $level));
				}else{
					$this->getLogger()->notice("Enchant " . $enchant . " could not be parsed to a valid enchantment");
					if(is_string($enchant)){
						$this->getLogger()->notice("Vanilla enchants should be written using the enchant numerical ID");
					}
				}
			}
		}
		return $i;
	}
	
	/**
	 * Translates the language key with the parameters passed.
	 *
	 * @param string $key
	 * @param mixed ...$params
	 *
	 * @return string
	 */
	public function translateString(string $key, ...$params) : string{
		$msg = $this->lang[$key] ?? "";
		
		preg_match("/{/", $msg, $haystack, PREG_OFFSET_CAPTURE);
		foreach($haystack as $needle){
			$start = $needle[1] + 1;
			$pointer = $start;
			while($msg[$pointer] !== "}"){
				$pointer++;
			}
			$kkey = substr($msg, $start, $pointer - $start);
			if(isset($this->lang[$kkey])){
				$msg = str_replace("{" . $kkey . "}", $this->translateString($kkey), $msg);
			}
		}
		foreach($params as $i => $param){
			$msg = str_replace("{%" . $i . "}", $param, $msg);
		}
		$msg = str_replace("{LINE}", TextFormat::EOL, $msg);
		return TextFormat::colorize($msg);
	}
	
	public function onDisable() : void{
		if($this->vp instanceof Config){
			$this->vp->save();
		}
	}
	
	/**
	 * @param Player|string $player
	 *
	 * @return int
	 */
	public function getVp($player) : int{
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);
		return $this->vp->get($player, 0);
	}
	
	/**
	 * @param Player|string $player
	 * @param int $points
	 */
	public function addVp($player, int $points) : void{
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);
		$this->vp->set($player, $this->vp->get($player, 0) + $points);
	}
	
	/**
	 * @param CommandSender $sender
	 * @param Command $cmd
	 * @param string $label
	 * @param array $args
	 *
	 * @return bool
	 */
	public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args) : bool{
		if(isset($args[0])){
			if(!$sender->isOp()){
				$sender->sendMessage(TextFormat::RED . "You must be an operator to do this!");
				return true;
			}
			switch(strtolower($args[0])){
				case "add":
				    if(count($args) < 3){
				    	$sender->sendMessage(TextFormat::AQUA . "Usage: /vp add <player> <points>");
				    	break;
				    }
				    $player = $args[1];
				    $points = $args[2];
				    if(!is_numeric($points) || $points < 1){
				    	$sender->sendMessage(TextFormat::RED . "Please enter a positive numeric value.");
				    	break;
				    }
				    $points = (int) round($points);
				    $this->addVp($player, $points);
				    $sender->sendMessage(TextFormat::GREEN . "Added " . $points . " VP to " . $player . "!");
				    break;
				case "subtract":
				    if(count($args) < 3){
				    	$sender->sendMessage(TextFormat::AQUA . "Usage: /vp subtract <player> <points>");
				    	break;
				    }
				    $player = $args[1];
				    $points = $args[2];
				    if(!is_numeric($points) || $points < 1){
				    	$sender->sendMessage(TextFormat::RED . "Please enter a positive numeric value.");
				    	break;
				    }
				    $points = (int) round($points);
				    $this->addVp($player, -$points);
				    $sender->sendMessage(TextFormat::GREEN . "Subtracted " . $points . " VP from " . $player . "!");
				    break;
				case "see":
				    if(!isset($args[1])){
				    	$sender->sendMessage(TextFormat::AQUA . "Usage: /vp see <player>");
				    	break;
				    }
				    $player = $args[1];
				    $vp = $this->getVp($player);
				    $sender->sendMessage(TextFormat::YELLOW . $player . " has " . $vp . " VP");
				    break;
				default:
				    $sender->sendMessage(TextFormat::AQUA . "Usage: /vp <add/subtract/see> <player> [points]");
			}
			return true;
		}
		
		
		if(!($sender instanceof Player)){
			$sender->sendMessage(TextFormat::RED . "You must run this command in-game.");
			return true;
		}
		
		/** @var int */
		$vp = $this->getVp($sender);
		
		$form = new SimpleForm(function(Player $player, ?int $prize) use($vp){
			if(is_int($prize) && isset($this->prizes[$prize])){
				$form = new ModalForm(function(Player $player, ?bool $confirm) use($prize, $vp){
					if(!$confirm){
						$player->chat("/vp");
						return;
					}
					$form = new SimpleForm(function(Player $player, ?int $data){
						$player->chat("/vp");
					});
					$form->setTitle($this->translateString("votepoints-ui3"));
					$cost = $this->prizes[$prize]->cost;
					$items = $this->prizes[$prize]->items;
					if(!($vp >= $cost)){
						$result = $this->translateString("votepoints-ui3-novp", $cost - $vp);
					}elseif(($player->getInventory()->getSize() - count($player->getInventory()->getContents(false))) < count($items)){
						$result = $this->translateString("votepoints-ui3-nospace");
					}else{
						$this->addVp($player, -$cost);
						$result = $this->translateString("votepoints-ui3-ok", $cost, $this->prizes[$prize]->name);
						foreach($items as $item){
							$player->getInventory()->addItem($item);
						}
					}
					$form->addButton($this->translateString("votepoints-ui3-next"));
					$form->setContent($result);
					$player->sendForm($form);
				});
				$form->setTitle($this->translateString("votepoints-ui2"));
				$form->setContent($this->translateString("votepoints-ui2-2", $this->prizes[$prize]->cost, $this->prizes[$prize]->name));
				$form->setButton1($this->translateString("votepoints-ui2-next"));
				$form->setButton2($this->translateString("votepoints-ui2-back"));
				$player->sendForm($form);
			}
		});
		$form->setTitle($this->translateString("votepoints-ui"));
		$form->setContent($this->translateString("votepoints-ui-2", $vp));
		foreach($this->prizes as $prize){
			$desc = $this->translateString("votepoints-ui-prize", $prize->name) . "\n";
			if($vp >= $prize->cost){
				$desc .= $this->translateString("votepoints-ui-prize-claim", $prize->cost);
			}else{
				$desc .= $this->translateString("votepoints-ui-prize-missing", $vp, $prize->cost - $vp);
			}
			$form->addButton($desc);
		}
		$sender->sendForm($form);
		return true;
	}
	
}