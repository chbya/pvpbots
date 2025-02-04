<?php

namespace chbya\Bots\arena;

use pocketmine\player\Player;
use pocketmine\Server;
use chbya\Bots\Main;
use chbya\Bots\entity\PvPBotEntity;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\utils\TextFormat;

class PvPBotArena {

    private string $mode;
    private Main $plugin;

    public function __construct(string $mode, Main $plugin) {
        $this->mode = $mode;
        $this->plugin = $plugin;
    }

    public function startMatch(Player $player): void {
        $player->sendMessage("Starting a {$this->mode} match with an advanced bot...");

        // Broadcast, for example
        Server::getInstance()->broadcastMessage(
            "[PvPBot] {$player->getName()} started a {$this->mode} match!"
        );

        // Spawn the bot near the player
        $location = $player->getLocation();
        $spawnPos = $location->add(3, 0, 0);

        // Create a Location object for the bot
        $botLocation = new Location($spawnPos->getX(), $spawnPos->getY(), $spawnPos->getZ(), $location->getWorld(), $location->yaw, $location->pitch);

        // Use the player's skin for the bot
        $skin = $player->getSkin();

        // Pass the Location and Skin to the PvPBotEntity constructor
        $bot = new PvPBotEntity($botLocation, $skin);
        $bot->setNameTag(TextFormat::AQUA . "{$this->mode} Bot");
        $bot->setNameTagVisible(true);
        $bot->spawnToAll();
    }
}