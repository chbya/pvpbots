<?php

namespace chbya\Bots;

use pocketmine\plugin\PluginBase;
use pocketmine\entity\EntityFactory;
use pocketmine\world\World;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\Skin;
use chbya\Bots\entity\PvPBotEntity;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;
use pocketmine\utils\TextFormat as TF;
use pocketmine\utils\Config;
use pocketmine\item\ItemFactory;
use pocketmine\item\VanillaItems;
use pocketmine\item\Item;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;
use chbya\Bots\Arena;

class Main extends PluginBase implements Listener {
    
    private array $botConfigs = [];
    private array $messages = [];
    private array $activeArenas = [];
    private array $equipmentPresets = [];
    private array $playerPresets = [];
    private array $arenas = [];
    private Config $arenaConfig;
    private ?Skin $defaultSkin = null;
    private ?Position $lobbySpawn = null;

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->loadConfigurations();
        $this->verifyEquipmentPresets();
        $this->loadDefaultSkin();
        $this->loadLobbySpawn();
        
        $this->arenaConfig = new Config($this->getDataFolder() . "arenas.yml", Config::YAML);
        $this->loadArenas();
        
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
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
            
            $bot = new PvPBotEntity($location, $this->defaultSkin);
            $bot->setBotConfig($config);
            return $bot;
        }, ['PvPBot']);
    }

    private function loadLobbySpawn(): void {
        $lobbyData = $this->getConfig()->get("lobby_spawn", null);
        if($lobbyData !== null) {
            $world = $this->getServer()->getWorldManager()->getWorldByName($lobbyData["world"]);
            if($world !== null) {
                $this->lobbySpawn = new Position(
                    $lobbyData["x"],
                    $lobbyData["y"],
                    $lobbyData["z"],
                    $world
                );
            }
        }
    }

    private function saveLobbySpawn(): void {
        if($this->lobbySpawn !== null) {
            $this->getConfig()->set("lobby_spawn", [
                "x" => $this->lobbySpawn->x,
                "y" => $this->lobbySpawn->y,
                "z" => $this->lobbySpawn->z,
                "world" => $this->lobbySpawn->getWorld()->getFolderName()
            ]);
            $this->getConfig()->save();
        }
    }

    private function loadArenas(): void {
        $arenasData = $this->arenaConfig->getAll();
        foreach($arenasData as $arenaData) {
            $arena = Arena::deserialize($arenaData, $this);
            if($arena !== null) {
                $this->arenas[$arena->getName()] = $arena;
            }
        }
    }

    private function saveArenas(): void {
        $arenasData = [];
        foreach($this->arenas as $arena) {
            $arenasData[] = $arena->serialize();
        }
        $this->arenaConfig->setAll($arenasData);
        $this->arenaConfig->save();
    }

    private function verifyEquipmentPresets(): void {
        if(empty($this->equipmentPresets)) {
            $this->getLogger()->warning("No equipment presets found in config.yml!");
            $this->equipmentPresets = [
                "Default" => [
                    "armor" => [
                        "helmet" => "iron_helmet",
                        "chestplate" => "iron_chestplate",
                        "leggings" => "iron_leggings",
                        "boots" => "iron_boots"
                    ],
                    "weapon" => "iron_sword"
                ]
            ];
            $this->getConfig()->set("equipment_presets", $this->equipmentPresets);
            $this->getConfig()->save();
        }
    }

    private function loadDefaultSkin(): void {
        try {
            $skinPath = $this->getDataFolder() . "skin.png";
            if(!file_exists($skinPath)) {
                $skinData = str_repeat(chr(255), 64 * 64 * 4);
            } else {
                $img = @imagecreatefrompng($skinPath);
                if(!$img) {
                    throw new \RuntimeException("Failed to load skin PNG file");
                }

                $width = imagesx($img);
                $height = imagesy($img);

                if(($width !== 64 || $height !== 64) && ($width !== 64 || $height !== 32)) {
                    throw new \RuntimeException("Invalid skin dimensions. Must be 64x64 or 64x32");
                }

                $skinData = "";
                for($y = 0; $y < $height; $y++) {
                    for($x = 0; $x < $width; $x++) {
                        $rgba = imagecolorat($img, $x, $y);
                        $a = ((~($rgba >> 24)) << 24) >> 24;
                        $r = ($rgba >> 16) & 0xff;
                        $g = ($rgba >> 8) & 0xff;
                        $b = $rgba & 0xff;
                        $skinData .= chr($r) . chr($g) . chr($b) . chr($a);
                    }
                }

                imagedestroy($img);

                if($height === 32) {
                    $skinData .= str_repeat(chr(0), 64 * 32 * 4);
                }
            }

            $this->defaultSkin = new Skin(
                "Standard_Custom",
                $skinData,
                "",
                "geometry.humanoid.custom"
            );
        } catch(\Exception $e) {
            $this->getLogger()->error("Failed to load skin: " . $e->getMessage());
            $skinData = str_repeat(chr(255), 64 * 64 * 4);
            $this->defaultSkin = new Skin(
                "Standard_Custom",
                $skinData,
                "",
                "geometry.humanoid.custom"
            );
        }
    }

    private function loadConfigurations(): void {
        $config = $this->getConfig();

        $this->equipmentPresets = $config->get("equipment_presets", []);

        $useVanillaKnockback = $config->getNested("settings.use_vanilla_knockback", false);
        
        $defaultValues = [
            "movement" => [
                "move_speed" => $config->getNested("settings.default_values.movement.move_speed", 0.35),
                "drag" => $config->getNested("settings.default_values.movement.drag", 0.02),
                "jump_velocity" => $config->getNested("settings.default_values.movement.jump_velocity", 0.52),
                "jump_delay" => $config->getNested("settings.default_values.movement.jump_delay", 10),
                "base_gravity" => $config->getNested("settings.default_values.movement.base_gravity", 0.028),
                "gravity_drag" => $config->getNested("settings.default_values.movement.gravity_drag", 0.99),
                "max_fall_speed" => $config->getNested("settings.default_values.movement.max_fall_speed", 0.4)
            ],
            "combat" => [
                "attack_range" => $config->getNested("settings.default_values.combat.attack_range", 3.5),
                "visual_attack_range" => $config->getNested("settings.default_values.combat.visual_attack_range", 6.0),
                "engage_range" => $config->getNested("settings.default_values.combat.engage_range", 30.0),
                "attack_cooldown_ticks" => $config->getNested("settings.default_values.combat.attack_cooldown_ticks", 10),
                "path_update_interval" => $config->getNested("settings.default_values.combat.path_update_interval", 5),
                "max_hurt_time" => $config->getNested("settings.default_values.combat.max_hurt_time", 10)
            ]
        ];

        $difficulties = ["Easy", "Medium", "Hard"];
        foreach($difficulties as $difficulty) {
            $this->botConfigs[$difficulty] = [
                "speed" => $config->getNested("difficulties.$difficulty.speed", $defaultValues["movement"]["move_speed"]),
                "knockback" => $config->getNested("difficulties.$difficulty.knockback", 0.4),
                "vertical_knockback" => $config->getNested("difficulties.$difficulty.vertical_knockback", 0.5),
                "damage" => $config->getNested("difficulties.$difficulty.damage", 3),
                "reach" => $config->getNested("difficulties.$difficulty.reach", 3.5),
                "use_vanilla_knockback" => $useVanillaKnockback,
                "move_speed" => $config->getNested("difficulties.$difficulty.movement.move_speed", $defaultValues["movement"]["move_speed"]),
                "drag" => $config->getNested("difficulties.$difficulty.movement.drag", $defaultValues["movement"]["drag"]),
                "jump_velocity" => $config->getNested("difficulties.$difficulty.movement.jump_velocity", $defaultValues["movement"]["jump_velocity"]),
                "jump_delay" => $config->getNested("difficulties.$difficulty.movement.jump_delay", $defaultValues["movement"]["jump_delay"]),
                "base_gravity" => $config->getNested("difficulties.$difficulty.movement.base_gravity", $defaultValues["movement"]["base_gravity"]),
                "gravity_drag" => $config->getNested("difficulties.$difficulty.movement.gravity_drag", $defaultValues["movement"]["gravity_drag"]),
                "max_fall_speed" => $config->getNested("difficulties.$difficulty.movement.max_fall_speed", $defaultValues["movement"]["max_fall_speed"]),
                "attack_range" => $config->getNested("difficulties.$difficulty.combat.attack_range", $defaultValues["combat"]["attack_range"]),
                "visual_attack_range" => $config->getNested("difficulties.$difficulty.combat.visual_attack_range", $defaultValues["combat"]["visual_attack_range"]),
                "engage_range" => $config->getNested("difficulties.$difficulty.combat.engage_range", $defaultValues["combat"]["engage_range"]),
                "attack_cooldown_ticks" => $config->getNested("difficulties.$difficulty.combat.attack_cooldown_ticks", $defaultValues["combat"]["attack_cooldown_ticks"]),
                "path_update_interval" => $config->getNested("difficulties.$difficulty.combat.path_update_interval", $defaultValues["combat"]["path_update_interval"]),
                "max_hurt_time" => $config->getNested("difficulties.$difficulty.combat.max_hurt_time", $defaultValues["combat"]["max_hurt_time"])
            ];
        }

        $this->messages = [
            'victory' => [
                'title' => $config->getNested("messages.victory.title", "§l§aVICTORY!"),
                'subtitle' => $config->getNested("messages.victory.subtitle", "§7You defeated the bot!")
            ],
            'defeat' => [
                'title' => $config->getNested("messages.defeat.title", "§l§cLOSER"),
                'subtitle' => $config->getNested("messages.defeat.subtitle", "§7Better luck next time!")
            ]
        ];
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch($command->getName()) {
            case "arena":
                if(!$sender instanceof Player) {
                    $sender->sendMessage(TF::RED . "This command can only be used in-game!");
                    return true;
                }

                if(!$sender->hasPermission("pvpbots.arena.setup")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command!");
                    return true;
                }

                if(count($args) < 1) {
                    $sender->sendMessage(TF::RED . "Usage: /arena <setup|setlobby> [arena_name] [1|2]");
                    return true;
                }

                if($args[0] === "setlobby") {
                    $this->lobbySpawn = $sender->getPosition();
                    $this->saveLobbySpawn();
                    $sender->sendMessage(TF::GREEN . "Lobby spawn point has been set!");
                    return true;
                }

                if($args[0] === "setup") {
                    if(count($args) < 3) {
                        $sender->sendMessage(TF::RED . "Usage: /arena setup <arena_name> <1|2>");
                        return true;
                    }

                    $arenaName = $args[1];
                    $spawnType = $args[2];

                    if(!isset($this->arenas[$arenaName])) {
                        $this->arenas[$arenaName] = new Arena($arenaName);
                    }

                    $arena = $this->arenas[$arenaName];
                    $location = $sender->getPosition();

                    if($spawnType === "1") {
                        $arena->setPlayerSpawn($location);
                        $sender->sendMessage(TF::GREEN . "Player spawn point set for arena '$arenaName'!");
                    } elseif($spawnType === "2") {
                        $arena->setBotSpawn($location);
                        $sender->sendMessage(TF::GREEN . "Bot spawn point set for arena '$arenaName'!");
                    } else {
                        $sender->sendMessage(TF::RED . "Invalid spawn type! Use '1' for player or '2' for bot.");
                        return true;
                    }

                    $this->saveArenas();
                    return true;
                }
                break;

            case "queue":
                if(!$sender instanceof Player) {
                    $sender->sendMessage(TF::RED . "This command can only be used in-game!");
                    return true;
                }

                if(isset($this->activeArenas[$sender->getName()])) {
                    $sender->sendMessage(TF::RED . "You are already in an arena!");
                    return true;
                }

                $this->showDifficultyForm($sender);
                return true;
                
            case "settings":
                if(!$sender instanceof Player) {
                    $sender->sendMessage(TF::RED . "This command can only be used in-game!");
                    return true;
                }
                $this->showSettingsMenu($sender);
                return true;
        }
        return false;
    }

    private function showSettingsMenu(Player $player): void {
        $form = new SimpleForm(function(Player $player, ?int $data) {
            if($data === null) return;
            
            switch($data) {
                case 0: // Equipment Presets
                    $this->showEquipmentPresetsMenu($player);
                    break;
                case 1: // Back to main menu
                    break;
            }
        });

        $form->setTitle("§l§cBot Settings");
        $form->setContent("§7Configure bot and player equipment");
        $form->addButton("§l§cEquipment Presets\n§r§7Click to configure");
        $form->addButton("§l§cBack\n§r§7Return to main menu");
        
        $player->sendForm($form);
    }

    private function showEquipmentPresetsMenu(Player $player): void {
        if(empty($this->equipmentPresets)) {
            $player->sendMessage(TF::RED . "No equipment presets are configured!");
            return;
        }

        $form = new CustomForm(function(Player $player, ?array $data) {
            if($data === null) return;
            
            $presetNames = array_keys($this->equipmentPresets);
            if(isset($data[1]) && isset($presetNames[$data[1]])) {
                $presetName = $presetNames[$data[1]];
                $this->playerPresets[$player->getName()] = $presetName;
                $player->sendMessage(TF::GREEN . "Equipment preset '" . $presetName . "' selected! Equipment will be given when you join a game.");
            }
        });

        $form->setTitle("§l§cEquipment Presets");
        $form->addLabel("§7Select equipment preset for bot and player");
        
        $presetNames = array_values(array_keys($this->equipmentPresets));
        $form->addDropdown("§7Select Preset", $presetNames);
        
        $player->sendForm($form);
    }

    private function showDifficultyForm(Player $player): void {
        $form = new SimpleForm(function(Player $player, $data) {
            if($data === null) return;
            
            $difficulties = array_keys($this->botConfigs);
            if(isset($difficulties[$data])) {
                $this->spawnBotInArena($player, $difficulties[$data]);
            }
        });

        $form->setTitle("§l§cSelect Difficulty");
        $form->setContent("§7Choose your opponent's difficulty:");
        
        foreach(array_keys($this->botConfigs) as $difficulty) {
            $form->addButton("§l§c" . $difficulty);
        }

        $player->sendForm($form);
    }

    private function getRandomArena(): ?Arena {
        $setupArenas = array_filter($this->arenas, fn($arena) => $arena->isSetup());
        if(empty($setupArenas)) {
            return null;
        }
        return $setupArenas[array_rand($setupArenas)];
    }

    private function spawnBotInArena(Player $player, string $difficulty): void {
        $arena = $this->getRandomArena();
        if($arena === null) {
            $player->sendMessage(TF::RED . "No arenas are set up! Please contact an administrator.");
            return;
        }
    
        // Clear and teleport player
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->teleport($arena->getPlayerSpawn());
    
        // Convert Position to Location for the bot
        $botSpawnPos = $arena->getBotSpawn();
        $botLocation = new Location(
            $botSpawnPos->x,
            $botSpawnPos->y,
            $botSpawnPos->z,
            $botSpawnPos->getWorld(),
            0, // yaw
            0  // pitch
        );
    
        $bot = new PvPBotEntity($botLocation, $this->defaultSkin);
        $bot->setBotConfig($this->botConfigs[$difficulty]);
        
        // Get player's selected preset
        $presetName = $this->playerPresets[$player->getName()] ?? "Default";
        
        // Apply equipment to both bot and player
        if(isset($this->equipmentPresets[$presetName])) {
            $preset = $this->equipmentPresets[$presetName];
            $this->applyEquipmentToBot($bot, $presetName);
            $this->giveEquipmentToPlayer($player, $preset);
        }
        
        $bot->spawnToAll();
    
        $this->activeArenas[$player->getName()] = true;
        $player->sendMessage(TF::GREEN . "Match started against " . $difficulty . " difficulty bot!");
    }

    private function getVanillaItem(string $itemName): ?Item {
        return match($itemName) {
            "wooden_sword" => VanillaItems::WOODEN_SWORD(),
            "stone_sword" => VanillaItems::STONE_SWORD(),
            "iron_sword" => VanillaItems::IRON_SWORD(),
            "diamond_sword" => VanillaItems::DIAMOND_SWORD(),
            "netherite_sword" => VanillaItems::NETHERITE_SWORD(),
            "iron_helmet" => VanillaItems::IRON_HELMET(),
            "iron_chestplate" => VanillaItems::IRON_CHESTPLATE(),
            "iron_leggings" => VanillaItems::IRON_LEGGINGS(),
            "iron_boots" => VanillaItems::IRON_BOOTS(),
            "diamond_helmet" => VanillaItems::DIAMOND_HELMET(),
            "diamond_chestplate" => VanillaItems::DIAMOND_CHESTPLATE(),
            "diamond_leggings" => VanillaItems::DIAMOND_LEGGINGS(),
            "diamond_boots" => VanillaItems::DIAMOND_BOOTS(),
            "netherite_helmet" => VanillaItems::NETHERITE_HELMET(),
            "netherite_chestplate" => VanillaItems::NETHERITE_CHESTPLATE(),
            "netherite_leggings" => VanillaItems::NETHERITE_LEGGINGS(),
            "netherite_boots" => VanillaItems::NETHERITE_BOOTS(),
            default => null
        };
    }

    private function giveEquipmentToPlayer(Player $player, array $preset): void {
        // Clear previous armor and items
        $player->getArmorInventory()->clearAll();
        $player->getInventory()->clearAll();
        
        // Give armor
        if(isset($preset['armor'])) {
            foreach($preset['armor'] as $slot => $itemName) {
                if(empty($itemName)) continue;
                
                $item = $this->getVanillaItem($itemName);
                if($item !== null) {
                    switch($slot) {
                        case 'helmet':
                            $player->getArmorInventory()->setHelmet($item);
                            break;
                        case 'chestplate':
                            $player->getArmorInventory()->setChestplate($item);
                            break;
                        case 'leggings':
                            $player->getArmorInventory()->setLeggings($item);
                            break;
                        case 'boots':
                            $player->getArmorInventory()->setBoots($item);
                            break;
                    }
                }
            }
        }
        
        // Give weapon
        if(isset($preset['weapon'])) {
            $weapon = $this->getVanillaItem($preset['weapon']);
            if($weapon !== null) {
                $player->getInventory()->setItem(0, $weapon);
            }
        }
    }

    public function applyEquipmentToBot(PvPBotEntity $bot, string $presetName): void {
        if(!isset($this->equipmentPresets[$presetName])) return;
        
        $preset = $this->equipmentPresets[$presetName];
        
        // Clear previous equipment
        $bot->getArmorInventory()->clearAll();
        $bot->getInventory()->clearAll();
        
        // Apply armor
        if(isset($preset['armor'])) {
            foreach($preset['armor'] as $slot => $itemName) {
                if(empty($itemName)) continue;
                
                $item = $this->getVanillaItem($itemName);
                if($item !== null) {
                    switch($slot) {
                        case 'helmet':
                            $bot->getArmorInventory()->setHelmet($item);
                            break;
                        case 'chestplate':
                            $bot->getArmorInventory()->setChestplate($item);
                            break;
                        case 'leggings':
                            $bot->getArmorInventory()->setLeggings($item);
                            break;
                        case 'boots':
                            $bot->getArmorInventory()->setBoots($item);
                            break;
                    }
                }
            }
        }
        
        // Apply weapon
        if(isset($preset['weapon'])) {
            $weapon = $this->getVanillaItem($preset['weapon']);
            if($weapon !== null) {
                $bot->getInventory()->setItem(0, $weapon);
            }
        }
    }

    private function teleportToLobby(Player $player): void {
        if($this->lobbySpawn !== null) {
            $player->teleport($this->lobbySpawn);
        }
    }

    private function scheduleCleanup(Player $player): void {
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($player): void {
                if($player->isOnline()) {
                    $this->cleanupPlayer($player);
                    $this->teleportToLobby($player);
                }
            }
        ), 60); // 3 seconds = 60 ticks
    }

    private function cleanupPlayer(Player $player): void {
        if(isset($this->activeArenas[$player->getName()])) {
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            unset($this->activeArenas[$player->getName()]);
        }
    }

    public function onItemDrop(PlayerDropItemEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->activeArenas[$player->getName()])) {
            $event->cancel();
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $this->cleanupPlayer($player);
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->activeArenas[$player->getName()])) {
            $event->setDrops([]);
            
            // Send defeat message
            $messages = $this->getMessages();
            $defeat = $messages['defeat'] ?? ['title' => '§l§cLOSER', 'subtitle' => '§7Better luck next time!'];
            
            $player->sendTitle(
                $defeat['title'],
                $defeat['subtitle'],
                20,
                40,
                20
            );
            
            // Schedule cleanup and teleport after 3 seconds
            $this->scheduleCleanup($player);
        }
    }

    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();
        if($entity instanceof PvPBotEntity) {
            // Prevent bot from dropping items
            $event->setDrops([]);
            
            // Find the player who killed the bot (if any)
            $lastDamageCause = $entity->getLastDamageCause();
            if($lastDamageCause instanceof EntityDamageByEntityEvent) {
                $killer = $lastDamageCause->getDamager();
                if($killer instanceof Player && $killer->isOnline()) {
                    // Send victory message
                    $messages = $this->getMessages();
                    $victory = $messages['victory'] ?? ['title' => '§l§aVICTORY!', 'subtitle' => '§7You defeated the bot!'];
                    
                    $killer->sendTitle(
                        $victory['title'],
                        $victory['subtitle'],
                        20,
                        40,
                        20
                    );
                    
                    // Schedule cleanup and teleport after 3 seconds
                    $this->scheduleCleanup($killer);
                }
            }
        }
    }

    public function removeFromArena(string $playerName): void {
        if(isset($this->activeArenas[$playerName])) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if($player !== null) {
                $this->cleanupPlayer($player);
            } else {
                unset($this->activeArenas[$playerName]);
            }
        }
    }

    public function getMessages(): array {
        return $this->messages;
    }
}