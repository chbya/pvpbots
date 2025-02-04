<?php

namespace chbya\Bots;

use pocketmine\plugin\PluginBase;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Skin;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;
use pocketmine\entity\Location;
use chbya\Bots\entity\PvPBotEntity;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\utils\TextFormat as TF;

class Main extends PluginBase {

    /** @var array */
    private array $botConfigs = [];
    
    /** @var array */
    private array $messages = [];

    /** @var array */
    private array $activeArenas = [];

    /** @var string */
    private string $skinData;

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->loadConfigurations();
        
        // Load skin from file
        $skinPath = $this->getDataFolder() . "steve.png";
        if(!file_exists($skinPath)){
            $this->saveResource("steve.png");
        }
        
        // Read skin file
        $img = @imagecreatefrompng($skinPath);
        if($img !== false){
            $skinBytes = "";
            $size = getimagesize($skinPath);
            
            for($y = 0; $y < $size[1]; $y++){
                for($x = 0; $x < $size[0]; $x++){
                    $colorat = imagecolorat($img, $x, $y);
                    $a = ((~($colorat >> 24)) << 1) & 0xff;
                    $r = ($colorat >> 16) & 0xff;
                    $g = ($colorat >> 8) & 0xff;
                    $b = $colorat & 0xff;
                    $skinBytes .= chr($r) . chr($g) . chr($b) . chr($a);
                }
            }
            imagedestroy($img);
            $this->skinData = $skinBytes;
        } else {
            // Fallback to default skin if file can't be loaded
            $this->skinData = str_repeat("\xff", 64 * 64 * 4);
        }

        EntityFactory::getInstance()->register(PvPBotEntity::class, function(World $world, CompoundTag $nbt): PvPBotEntity {
            $location = new Location(
                $nbt->getDouble("PosX"),
                $nbt->getDouble("PosY"),
                $nbt->getDouble("PosZ"),
                $world,
                $nbt->getFloat("Yaw"),
                $nbt->getFloat("Pitch")
            );
            
            $mode = $nbt->getString("BotMode", "Medium");
            $config = $this->botConfigs[$mode] ?? $this->botConfigs["Medium"];

            $skin = new Skin(
                "Standard_Custom",
                $this->skinData,
                "",
                "geometry.humanoid.custom"
            );

            $bot = new PvPBotEntity($location, $skin);
            $bot->setBotConfig($config);
            return $bot;
        }, [PvPBotEntity::NETWORK_ID]);
    }

    private function loadConfigurations(): void {
        $config = $this->getConfig();
        $this->botConfigs = $config->get("difficulties", [
            "Easy" => [
                "speed" => 0.25,
                "knockback" => 0.3,
                "vertical_knockback" => 0.35,
                "damage" => 2,
                "reach" => 3.0
            ],
            "Medium" => [
                "speed" => 0.35,
                "knockback" => 0.4,
                "vertical_knockback" => 0.45,
                "damage" => 3,
                "reach" => 3.5
            ],
            "Hard" => [
                "speed" => 0.45,
                "knockback" => 0.5,
                "vertical_knockback" => 0.55,
                "damage" => 4,
                "reach" => 4.0
            ]
        ]);

        $this->messages = $config->get("messages", [
            "victory" => [
                "title" => "§l§aVICTORY!",
                "subtitle" => "§7You defeated the bot!"
            ],
            "defeat" => [
                "title" => "§l§cLOSER",
                "subtitle" => "§7Better luck next time!"
            ]
        ]);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game.");
            return true;
        }

        if (strtolower($command->getName()) === "queue") {
            $this->showQueueForm($sender);
            return true;
        }

        return false;
    }

    private function showQueueForm(Player $player): void {
        if (isset($this->activeArenas[$player->getName()])) {
            $player->sendMessage(TF::RED . "You are already in a match!");
            return;
        }

        $form = new SimpleForm(function(Player $player, ?string $data) {
            if($data === null) {
                return;
            }

            if(isset($this->botConfigs[$data])) {
                $this->startMatch($player, $data);
            }
        });

        $form->setTitle(TF::BOLD . TF::AQUA . "PvP Bot Queue");
        $form->setContent(TF::YELLOW . "Select a difficulty to fight against:");

        foreach($this->botConfigs as $mode => $config) {
            $form->addButton(TF::GREEN . $mode . TF::GRAY . "\nClick to start", -1, "", $mode);
        }

        $player->sendForm($form);
    }

    private function startMatch(Player $player, string $difficulty): void {
        if (isset($this->activeArenas[$player->getName()])) {
            $player->sendMessage(TF::RED . "You are already in a match!");
            return;
        }

        $pos = $player->getPosition();
        $yaw = $player->getLocation()->getYaw();
        $rad = deg2rad($yaw);
        $spawnX = $pos->x - sin($rad) * 3;
        $spawnZ = $pos->z + cos($rad) * 3;

        $bot = new PvPBotEntity(
            new Location($spawnX, $pos->y, $spawnZ, $pos->getWorld(), $yaw + 180, 0),
            new Skin("Standard_Custom", str_repeat("\xff", 64 * 64 * 4), "", "geometry.humanoid.custom")
        );
        
        $bot->setBotConfig($this->botConfigs[$difficulty]);
        $bot->spawnToAll();
        
        $this->activeArenas[$player->getName()] = $bot;
        $player->sendMessage(TF::GREEN . "Started match against " . TF::YELLOW . $difficulty . TF::GREEN . " bot!");
    }

    public function removeFromArena(string $playerName): void {
        unset($this->activeArenas[$playerName]);
    }
    public function getMessages(): array {
        return $this->messages;
    }

    public function onDisable(): void {
        // Safely remove bots without calling flagForDespawn
        foreach ($this->activeArenas as $playerName => $bot) {
            if ($bot->isAlive()) {
                $bot->close();
            }
        }
        $this->activeArenas = [];
    }
}